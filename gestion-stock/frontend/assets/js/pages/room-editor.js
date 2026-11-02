// Éditeur 3D d'une salle : scène, arborescence des rangements et panneau de propriétés.

import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { busy, confirmDialog, field, openDialog, showError, toast } from '../ui.js';
import {
  findFreeSpot, formatLength, fromDisplay, rotateAroundCenter, SHAPE_LABELS, toDisplay, unitInterior, UNITS,
} from '../spatial.js';
import { openOpeningsDialog, openRoomDialog } from './room-form.js';
import { addDialog, formatQuantity, itemDialog, pickItem, takeDialog } from '../stock-dialogs.js';

let scene = null;

export function dispose() {
  scene?.dispose();
  scene = null;
}

export async function render(view, session, params) {
  const roomId = Number(params.id);
  const can = (p) => session.can(p);
  const state = { payload: null, types: [], selectedId: null, expanded: new Set(), busy: false };

  const title = h('h1', { class: 'editor-title' }, 'Chargement…');
  const tree = h('nav', { class: 'editor-tree', 'aria-label': 'Rangements de la salle' });
  const details = h('aside', { class: 'editor-details', 'aria-live': 'polite' });
  const stage = h('div', { class: 'editor-stage' });
  const hint = h('p', { class: 'editor-hint' }, 'Clic : sélectionner · glisser : tourner · clic droit ou Maj + glisser : déplacer la vue · molette : zoom · double-clic : ouvrir un tiroir');

  const pressed = (button, on) => button.setAttribute('aria-pressed', String(on));
  const dimsButton = h('button', { class: 'btn btn-small', type: 'button', 'aria-pressed': 'false' }, 'Cotes');
  const moveButton = can('furniture.edit') && h('button', { class: 'btn btn-small', type: 'button', 'aria-pressed': 'false', title: 'Faire glisser le meuble sélectionné au sol (Maj : pas de 10 cm)' }, 'Déplacer');
  const colorSelect = can('stock.view') && h('select', { class: 'select-small', 'aria-label': 'Couleurs des rangements' },
    h('option', { value: 'original' }, "Couleurs : d'origine"), h('option', { value: 'fill' }, 'Couleurs : remplissage'));
  const legend = h('div', { class: 'fill-legend', hidden: true });
  const wallSelect = h('select', { class: 'select-small', 'aria-label': 'Affichage des murs' },
    [['auto', 'Murs : auto'], ['transparent', 'Murs : transparents'], ['hidden', 'Murs : masqués'], ['solid', 'Murs : pleins']].map(([v, t]) => h('option', { value: v }, t)));

  view.classList.add('view-wide');
  view.append(h('div', { class: 'editor' },
    h('div', { class: 'editor-toolbar' },
      h('a', { class: 'btn btn-small btn-ghost', href: '/rooms' }, '← Salles'),
      title,
      h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Points de vue' },
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => scene?.viewPreset('iso') }, 'Vue 3D'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => scene?.viewPreset('top') }, 'Dessus'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => scene?.viewPreset('door') }, 'Depuis la porte')),
      h('div', { class: 'toolbar-group' }, dimsButton, wallSelect, colorSelect, moveButton,
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => { scene?.showAll(); renderTree(); renderDetails(); } }, 'Tout afficher')),
      h('div', { class: 'toolbar-group toolbar-end' },
        can('furniture.edit') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(null, null) }, 'Ajouter un meuble'),
        can('rooms.edit') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          await openOpeningsDialog(state.payload, (payload) => apply(payload));
        } }, 'Ouvertures'),
        can('rooms.edit') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          const result = await openRoomDialog(state.payload.room);
          if (result) apply(result);
        } }, 'Paramètres')),
    ),
    tree, h('div', { class: 'editor-canvas' }, stage, hint, legend), details,
  ));

  // ===== Données =====

  const unitById = (id) => state.payload?.units.find((u) => u.id === id) ?? null;
  const childrenOf = (id) => state.payload.units.filter((u) => u.parent_id === id);
  const unitLabel = () => state.payload.room.display_unit;

  function apply(payload) {
    state.payload = payload;
    title.textContent = payload.room.name;
    document.title = `${payload.room.name} · Gestion de stock`;
    if (state.selectedId !== null && !unitById(state.selectedId)) state.selectedId = null;
    scene?.setData(payload.room, payload.openings, payload.units);
    applyColors();
    scene?.select(state.selectedId);
    renderTree();
    renderDetails();
  }

  async function reload() {
    apply(await api(`/rooms/${roomId}`));
  }

  function select(id, { focus = false } = {}) {
    state.selectedId = id;
    for (let u = unitById(id); u && u.parent_id !== null; u = unitById(u.parent_id)) state.expanded.add(u.parent_id);
    scene?.select(id);
    if (focus && id !== null) scene?.focus(id);
    const url = new URL(location.href);
    if (id === null) url.searchParams.delete('unit'); else url.searchParams.set('unit', id);
    history.replaceState(null, '', url);
    renderTree();
    renderDetails();
  }

  async function patchUnit(unit, changes) {
    try {
      await api(`/storage-units/${unit.id}`, { method: 'PATCH', body: changes });
      await reload();
      return true;
    } catch (error) {
      showError(error);
      return false;
    }
  }

  // ===== Scène 3D =====

  try {
    scene = new (await import('../three/room-scene.js')).RoomScene(stage, {
      onSelect: (id) => select(id),
      onMove: (unit, changes) => patchUnit(unit, changes),
    });
  } catch (error) {
    stage.append(h('div', { class: 'alert alert-error' }, "La vue 3D n'a pas pu démarrer (WebGL indisponible sur cet appareil). L'arborescence et les propriétés restent utilisables."));
    console.error(error);
  }

  dimsButton.addEventListener('click', () => {
    const on = dimsButton.getAttribute('aria-pressed') !== 'true';
    pressed(dimsButton, on);
    scene?.setShowDimensions(on);
  });
  wallSelect.addEventListener('change', () => scene?.setWallMode(wallSelect.value));
  colorSelect?.addEventListener('change', applyColors);

  // ===== Remplissage =====

  /** vide / partiel / plein, pour les rangements pouvant contenir des objets. */
  function fillStatus(u) {
    if (!u.can_hold_items) return null;
    const stock = state.payload.stock ?? {};
    const own = stock[u.id]?.quantity ?? 0;
    if (u.capacity && own >= u.capacity) return 'full';
    const deep = (id) => childrenOf(id).some((c) => (stock[c.id]?.quantity ?? 0) > 0 || deep(c.id));
    return own > 0 || deep(u.id) ? 'partial' : 'empty';
  }

  function applyColors() {
    const on = colorSelect?.value === 'fill' && state.payload?.stock;
    legend.hidden = !on;
    if (!on) { scene?.setColorOverrides(null); return; }
    const colors = session.settings['fill.colors'] ?? { empty: '#9ca3af', partial: '#f59e0b', full: '#dc2626' };
    const map = new Map();
    for (const u of state.payload.units) {
      const status = fillStatus(u);
      if (status) map.set(u.id, colors[status]);
    }
    scene?.setColorOverrides(map);
    clear(legend, [['empty', 'Vide'], ['partial', 'Partiellement rempli'], ['full', 'Plein (capacité atteinte)']].map(([k, t]) => {
      const dot = h('span', { class: 'legend-dot' });
      dot.style.background = colors[k];
      return h('span', { class: 'legend-item' }, dot, t);
    }));
  }
  moveButton?.addEventListener('click', () => {
    const on = moveButton.getAttribute('aria-pressed') !== 'true';
    pressed(moveButton, on);
    scene?.setMoveMode(on);
  });

  // Flèches : décalage de 10 mm (Maj : 100 mm) ; Pg préc./suiv. : hauteur ; Échap : désélection
  stage.addEventListener('keydown', (event) => {
    const u = unitById(state.selectedId);
    if (event.key === 'Escape') return select(null);
    if (!u || !can(u.parent_id === null ? 'furniture.edit' : 'storage.edit')) return;
    const step = event.shiftKey ? 100 : 10;
    const moves = { ArrowLeft: ['pos_x_mm', -step], ArrowRight: ['pos_x_mm', step], ArrowUp: ['pos_z_mm', -step], ArrowDown: ['pos_z_mm', step], PageUp: ['pos_y_mm', step], PageDown: ['pos_y_mm', -step] };
    const move = moves[event.key];
    if (!move || state.busy) return;
    event.preventDefault();
    nudge(u, move[0], move[1]);
  });

  async function nudge(u, key, delta) {
    state.busy = true;
    await patchUnit(u, { [key]: u[key] + delta });
    state.busy = false;
  }

  // ===== Arborescence =====

  function renderTree() {
    if (!state.payload) return;
    const node = (u) => {
      const kids = childrenOf(u.id);
      const open = state.expanded.has(u.id);
      const hidden = scene?.hidden.has(u.id) ?? false;
      return h('li', { class: ['tree-node', u.id === state.selectedId && 'is-selected', hidden && 'is-hidden'] },
        h('div', { class: 'tree-row' },
          kids.length
            ? h('button', { class: 'tree-toggle', type: 'button', 'aria-expanded': String(open), 'aria-label': open ? 'Replier' : 'Déplier',
              onclick: () => { if (open) state.expanded.delete(u.id); else state.expanded.add(u.id); renderTree(); } }, open ? '▾' : '▸')
            : h('span', { class: 'tree-toggle' }),
          h('button', { class: 'tree-label', type: 'button', onclick: () => select(u.id, { focus: true }) },
            h('span', { class: 'tree-swatch', dataset: { color: u.color } }), u.name, u.label && h('span', { class: 'badge' }, u.label)),
          h('button', { class: 'tree-eye', type: 'button', 'aria-pressed': String(hidden), title: hidden ? 'Afficher' : 'Masquer temporairement',
            onclick: () => { scene?.setHidden(u.id, !hidden); renderTree(); } }, hidden ? '◌' : '●'),
        ),
        kids.length > 0 && open && h('ul', {}, kids.map(node)),
      );
    };
    const roots = childrenOf(null);
    clear(tree,
      h('h2', { class: 'panel-title' }, 'Rangements'),
      roots.length ? h('ul', { class: 'tree' }, roots.map(node)) : h('p', { class: 'muted small' }, 'Aucun meuble dans cette salle.'));
    // Pastilles de couleur sans style en ligne (CSP) : couleur appliquée via le CSSOM
    tree.querySelectorAll('.tree-swatch').forEach((el) => { el.style.background = el.dataset.color; });
  }

  // ===== Panneau de propriétés =====

  function renderDetails() {
    if (!state.payload) return;
    const unit = unitLabel();
    const u = unitById(state.selectedId);
    if (!u) {
      const { room, units, openings } = state.payload;
      clear(details,
        h('h2', { class: 'panel-title' }, room.name),
        room.description && h('p', { class: 'muted' }, room.description),
        h('dl', { class: 'props' },
          h('dt', {}, 'Hauteur'), h('dd', {}, formatLength(room.height_mm, unit)),
          h('dt', {}, 'Murs'), h('dd', {}, `${room.outline.length} (épaisseur ${formatLength(room.wall_thickness_mm, unit)})`),
          h('dt', {}, 'Porte'), h('dd', {}, `mur 1, à ${formatLength(room.door_offset_mm, unit)} de l'origine, ${formatLength(room.door_width_mm, unit)} × ${formatLength(room.door_height_mm, unit)}`),
          h('dt', {}, 'Ouvertures'), h('dd', {}, String(openings.length)),
          h('dt', {}, 'Meubles'), h('dd', {}, String(childrenOf(null).length)),
          h('dt', {}, 'Rangements'), h('dd', {}, String(units.length))),
        h('p', { class: 'field-hint' }, "Repère : origine au coin gauche du mur de la porte (vu de l'intérieur), au sol. X longe le mur de la porte, Z entre dans la salle, Y monte."),
        h('p', { class: 'muted small' }, 'Sélectionnez un meuble dans la vue ou dans la liste.'));
      return;
    }

    const parent = unitById(u.parent_id);
    const inside = unitInterior(u);
    const kids = childrenOf(u.id);
    const editRight = can(u.parent_id === null ? 'furniture.edit' : 'storage.edit');
    const hidden = scene?.hidden.has(u.id) ?? false;

    const actions = h('div', { class: 'detail-actions' },
      editRight && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(u, parent) }, 'Modifier'),
      can('storage.edit') && u.can_contain_units && inside && h('button', { class: 'btn btn-small', type: 'button', onclick: () => editUnit(null, u) }, 'Ajouter un rangement dedans'),
      can('storage.edit') && u.can_contain_units && inside && kids.length === 0 && h('button', { class: 'btn btn-small', type: 'button', onclick: () => generate(u) }, "Générer l'intérieur"),
      u.shape === 'drawer' && h('button', { class: 'btn btn-small', type: 'button', onclick: () => { scene?.setDrawerOpen(u.id, !scene.isDrawerOpen(u.id)); setTimeout(renderDetails, 260); } },
        scene?.isDrawerOpen(u.id) ? 'Fermer le tiroir' : 'Ouvrir le tiroir'),
      h('button', { class: 'btn btn-small', type: 'button', onclick: () => scene?.focus(u.id) }, 'Centrer'),
      h('button', { class: 'btn btn-small', type: 'button', onclick: () => { scene?.setHidden(u.id, !hidden); renderTree(); renderDetails(); } }, hidden ? 'Afficher' : 'Masquer'),
      editRight && h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: () => remove(u) }, 'Supprimer'),
    );

    const position = editRight && nudgePad(u);

    clear(details,
      h('div', { class: 'detail-header' },
        h('h2', { class: 'panel-title' }, u.name),
        h('span', { class: 'badge' }, u.type_name), u.label && h('span', { class: 'badge badge-accent' }, u.label)),
      h('p', { class: 'path' }, u.path),
      u.description && h('p', { class: 'muted' }, u.description),
      actions,
      h('dl', { class: 'props' },
        u.reference && [h('dt', {}, 'Référence'), h('dd', {}, u.reference)],
        h('dt', {}, 'Dimensions (L × P × H)'), h('dd', {}, `${formatLength(u.width_mm, unit)} × ${formatLength(u.depth_mm, unit)} × ${formatLength(u.height_mm, unit)}`),
        h('dt', {}, u.parent_id === null ? 'Position (coin arrière gauche)' : `Position dans « ${parent?.name} »`),
        h('dd', {}, `X ${formatLength(u.pos_x_mm, unit)} · Y ${formatLength(u.pos_y_mm, unit)} · Z ${formatLength(u.pos_z_mm, unit)}`),
        u.parent_id === null && [h('dt', {}, 'Rotation'), h('dd', {}, `${u.rotation_deg}°`)],
        h('dt', {}, 'Épaisseur des parois'), h('dd', {}, formatLength(u.panel_mm, unit)),
        inside && [h('dt', {}, 'Intérieur utile'), h('dd', {}, `${formatLength(inside.width, unit)} × ${formatLength(inside.depth, unit)} × ${formatLength(inside.height, unit)}`)]),
      position,
      h('h3', { class: 'panel-subtitle' }, `Contenu (${kids.length})`),
      kids.length
        ? h('ul', { class: 'plain-list' }, kids.map((k) => h('li', {}, h('button', { class: 'link-btn', type: 'button', onclick: () => select(k.id, { focus: true }) }, k.name), h('span', { class: 'muted small' }, ` · ${k.type_name}`))))
        : h('p', { class: 'muted small' }, u.can_contain_units ? 'Aucun sous-rangement.' : 'Ce type ne contient pas de rangements.'),
      u.can_hold_items && can('stock.view') && contentsSection(u),
    );
  }

  function nudgePad(u) {
    const unit = unitLabel();
    const stepSelect = h('select', { class: 'select-small', 'aria-label': 'Pas de déplacement' },
      [[1, '1 mm'], [10, '1 cm'], [100, '10 cm'], [500, '50 cm']].map(([v, t]) => h('option', { value: v, selected: v === 10 }, t)));
    const b = (text, key, sign, title) => h('button', { class: 'btn btn-small', type: 'button', title, onclick: () => nudge(u, key, sign * Number(stepSelect.value)) }, text);
    return h('div', { class: 'nudge' },
      h('h3', { class: 'panel-subtitle' }, 'Ajuster la position'),
      h('div', { class: 'nudge-grid' },
        b('X −', 'pos_x_mm', -1, 'Vers la gauche'), b('X +', 'pos_x_mm', 1, 'Vers la droite'),
        b(u.parent_id === null ? 'Z −' : 'Arrière', 'pos_z_mm', -1, u.parent_id === null ? 'Vers le mur de la porte' : 'Vers le fond'),
        b(u.parent_id === null ? 'Z +' : 'Avant', 'pos_z_mm', 1, u.parent_id === null ? 'Vers le fond de la salle' : 'Vers la façade'),
        b('Y −', 'pos_y_mm', -1, 'Plus bas'), b('Y +', 'pos_y_mm', 1, 'Plus haut'),
        stepSelect),
      u.parent_id === null && h('div', { class: 'nudge-grid' },
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg - 90)) }, '⟲ 90°'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg + 90)) }, '⟳ 90°')),
      h('p', { class: 'field-hint' }, `Clavier dans la vue 3D : flèches (Maj : ×10), Pg préc./suiv. pour la hauteur. Unité : ${unit}.`),
    );
  }

  /** Objets rangés dans ce rangement (et ses sous-rangements), avec prise et ajout directs. */
  function contentsSection(u) {
    const body = h('div', {}, h('p', { class: 'muted small' }, 'Chargement…'));
    const location = { unit_id: u.id, path: u.path };
    const load = async () => {
      try {
        const { contents } = await api(`/storage-units/${u.id}/contents?deep=1`);
        clear(body, contents.length
          ? h('ul', { class: 'plain-list contents-list' }, contents.map((c) => h('li', { class: 'list-row' },
            h('span', {},
              h('a', { href: `/items/${c.item_id}` }, c.name), ' · ', h('strong', {}, formatQuantity(c.quantity, c.unit)),
              c.unit_id !== u.id && h('div', { class: 'muted small' }, c.path.split(' › ').slice(-1)[0])),
            can('stock.take') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
              const item = { id: c.item_id, name: c.name, unit: c.unit, is_consumable: c.is_consumable };
              if (await takeDialog(item, { unit_id: c.unit_id, path: c.path, quantity: c.quantity })) { await reload(); }
            } }, 'Prendre'))))
          : h('p', { class: 'muted small' }, 'Aucun objet rangé ici.'));
      } catch (error) { showError(error); }
    };
    load();
    return h('div', {},
      h('h3', { class: 'panel-subtitle' }, 'Objets', u.capacity && ` (capacité ${formatQuantity(u.capacity)})`),
      body,
      h('div', { class: 'detail-actions' },
        can('stock.add') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          const item = await pickItem('Ajouter du stock ici : choisir l\'objet');
          if (item && await addDialog(item, location)) await reload();
        } }, 'Ajouter du stock ici'),
        can('items.edit') && can('stock.add') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          if (await itemDialog(null, { canAddStock: true, location: { id: u.id, path: u.path } })) await reload();
        } }, 'Nouvel objet ici')));
  }

  // ===== Actions =====

  async function remove(u) {
    const count = state.payload.units.filter((x) => x.path.startsWith(`${u.path} › `)).length;
    const ok = await confirmDialog({
      title: 'Supprimer',
      message: count ? `Supprimer « ${u.name} » et les ${count} rangement(s) qu'il contient ?` : `Supprimer « ${u.name} » ?`,
      confirmLabel: 'Supprimer', danger: true,
    });
    if (!ok) return;
    try {
      await api(`/storage-units/${u.id}`, { method: 'DELETE' });
      toast('Rangement supprimé.');
      state.selectedId = u.parent_id;
      await reload();
    } catch (error) { showError(error); }
  }

  async function generate(u) {
    const types = state.types.filter((t) => t.shape !== 'divider');
    const saved = await openDialog(`Générer l'intérieur de « ${u.name} »`, (close) => {
      const rows = h('input', { name: 'rows', type: 'number', min: 1, max: 50, value: 4, required: true });
      const columns = h('input', { name: 'columns', type: 'number', min: 1, max: 50, value: 1, required: true });
      const gap = h('input', { name: 'gap_mm', type: 'number', min: 0, max: 500, value: 0 });
      const type = h('select', { name: 'type_id' }, types.map((t) => h('option', { value: t.id, selected: t.shape === 'shelf' }, t.name)));
      const inside = unitInterior(u);
      const form = h('form', { class: 'dialog-body', novalidate: true },
        h('p', { class: 'field-hint' }, `Divise l'intérieur utile (${formatLength(inside.width, unitLabel())} × ${formatLength(inside.height, unitLabel())}) en une grille régulière. Numérotation de haut en bas, puis de gauche à droite.`),
        h('div', { class: 'form-grid' }, field('Type', type), field('Espacement (mm)', gap), field('Rangées (hauteur)', rows), field('Colonnes (largeur)', columns)),
        h('div', { class: 'dialog-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
          h('button', { class: 'btn btn-primary', type: 'submit' }, 'Générer')));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            const r = await api(`/storage-units/${u.id}/generate`, { method: 'POST', body: {
              rows: Number(rows.value), columns: Number(columns.value), gap_mm: Number(gap.value || 0), type_id: Number(type.value),
            } });
            toast(`${r.created} rangement(s) créé(s).`);
            close(true);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });
    if (saved) {
      state.expanded.add(u.id);
      await reload();
    }
  }

  /** Création (unit null) ou modification d'un meuble (parent null) ou d'un rangement. */
  async function editUnit(u, parent) {
    const room = state.payload.room;
    const unit = room.display_unit;
    const isFurniture = parent === null;
    const types = state.types;
    const saved = await openDialog(u ? `Modifier « ${u.name} »` : isFurniture ? 'Nouveau meuble' : `Nouveau rangement dans « ${parent.name} »`, (close) => {
      const inside = parent ? unitInterior(parent) : null;
      const typeSelect = h('select', { name: 'type_id' }, types.map((t) => h('option', { value: t.id, selected: t.id === (u?.type_id ?? (isFurniture ? types.find((x) => x.shape === 'cabinet')?.id : types.find((x) => x.shape === 'drawer')?.id)) }, `${t.name} (${SHAPE_LABELS[t.shape]})`)));
      const len = (name, mm) => h('input', { name, type: 'number', inputmode: 'decimal', step: UNITS[unit].step, value: toDisplay(mm, unit), required: true });
      const name = h('input', { name: 'name', required: true, maxlength: 120, value: u?.name ?? '' });
      const label = h('input', { name: 'label', maxlength: 50, value: u?.label ?? '' });
      const reference = h('input', { name: 'reference', maxlength: 50, value: u?.reference ?? '' });
      const description = h('textarea', { name: 'description', rows: 2, maxlength: 1000 }, u?.description ?? '');
      const width = len('width_mm', u?.width_mm ?? 0); const depth = len('depth_mm', u?.depth_mm ?? 0); const height = len('height_mm', u?.height_mm ?? 0);
      const px = len('pos_x_mm', u?.pos_x_mm ?? 0); const py = len('pos_y_mm', u?.pos_y_mm ?? 0); const pz = len('pos_z_mm', u?.pos_z_mm ?? 0);
      const rotation = h('input', { name: 'rotation_deg', type: 'number', min: 0, max: 359, step: 1, value: u?.rotation_deg ?? 0 });
      const panel = h('input', { name: 'panel_mm', type: 'number', min: 0, max: 200, step: 1, value: u?.panel_mm ?? 0 });
      const capacity = h('input', { name: 'capacity', type: 'number', min: 0, step: 'any', value: u?.capacity ?? '', placeholder: 'Facultatif' });
      const color = h('input', { name: 'color', type: 'color', value: u?.color ?? '#b08d5c' });

      const applyTypeDefaults = () => {
        const t = types.find((x) => x.id === Number(typeSelect.value));
        if (!t) return;
        let size = { width_mm: t.default_width_mm, depth_mm: t.default_depth_mm, height_mm: t.default_height_mm };
        if (inside) size = { width_mm: Math.min(size.width_mm, inside.width), depth_mm: Math.min(size.depth_mm, inside.depth), height_mm: Math.min(size.height_mm, inside.height) };
        width.value = toDisplay(size.width_mm, unit); depth.value = toDisplay(size.depth_mm, unit); height.value = toDisplay(size.height_mm, unit);
        panel.value = t.default_panel_mm;
        color.value = t.default_color;
        const count = state.payload.units.filter((x) => x.parent_id === (parent?.id ?? null) && x.type_id === t.id).length;
        if (!name.dataset.touched) name.value = `${t.name} ${isFurniture ? String.fromCharCode(65 + count) : count + 1}`;
        const spot = findFreeSpot(room, state.payload.units, parent, size);
        if (spot) { px.value = toDisplay(spot.pos_x_mm, unit); py.value = toDisplay(spot.pos_y_mm, unit); pz.value = toDisplay(spot.pos_z_mm, unit); }
        spotHint.textContent = spot ? 'Position libre proposée automatiquement.' : "Aucun emplacement libre trouvé automatiquement : indiquez une position.";
      };
      const spotHint = h('p', { class: 'field-hint' });
      name.addEventListener('input', () => { name.dataset.touched = '1'; });
      if (!u) { typeSelect.addEventListener('change', applyTypeDefaults); }

      const form = h('form', { class: 'dialog-body', novalidate: true },
        h('div', { class: 'form-grid' }, field('Type', typeSelect), field('Nom', name)),
        h('div', { class: 'form-grid' }, field('Étiquette', label, 'Code court, ex. « A » ou « T2 ».'), field('Référence', reference, 'Unique dans tout le système (facultatif).')),
        field('Description', description),
        h('fieldset', { class: 'form-section' }, h('legend', {}, `Dimensions extérieures (${unit})`),
          h('div', { class: 'form-grid form-grid-3' }, field('Largeur', width), field('Profondeur', depth), field('Hauteur', height)),
          inside && h('p', { class: 'field-hint' }, `Intérieur disponible dans « ${parent.name} » : ${formatLength(inside.width, unit)} × ${formatLength(inside.depth, unit)} × ${formatLength(inside.height, unit)}.`)),
        h('fieldset', { class: 'form-section' }, h('legend', {}, `Position (${unit})`),
          h('p', { class: 'field-hint' }, isFurniture
            ? "Coin arrière gauche du meuble, depuis l'origine de la salle. La rotation se fait autour de ce coin."
            : `Coin arrière gauche bas, depuis le coin arrière gauche bas de l'intérieur de « ${parent.name} ».`),
          h('div', { class: isFurniture ? 'form-grid form-grid-4' : 'form-grid form-grid-3' },
            field('X', px), field(isFurniture ? 'Y (élévation)' : 'Y (hauteur)', py), field(isFurniture ? 'Z' : 'Z (depuis le fond)', pz),
            isFurniture && field('Rotation (°)', rotation)),
          spotHint),
        h('div', { class: 'form-grid form-grid-3' }, field('Épaisseur des parois (mm)', panel), field('Couleur', color), field('Capacité (objets)', capacity, "Pour l'état « plein ».")),
        h('div', { class: 'dialog-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
          h('button', { class: 'btn btn-primary', type: 'submit' }, u ? 'Enregistrer' : 'Ajouter')));
      if (!u) applyTypeDefaults();

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const body = {
          type_id: Number(typeSelect.value), name: name.value, label: label.value, reference: reference.value, description: description.value,
          width_mm: fromDisplay(width.value, unit), depth_mm: fromDisplay(depth.value, unit), height_mm: fromDisplay(height.value, unit),
          pos_x_mm: fromDisplay(px.value, unit), pos_y_mm: fromDisplay(py.value, unit), pos_z_mm: fromDisplay(pz.value, unit),
          panel_mm: Number(panel.value), color: color.value, capacity: capacity.value === '' ? null : Number(capacity.value),
        };
        if (isFurniture) body.rotation_deg = Number(rotation.value);
        if (!u) {
          if (parent) body.parent_id = parent.id; else body.room_id = room.id;
        }
        busy(event.submitter, async () => {
          try {
            const result = await api(u ? `/storage-units/${u.id}` : '/storage-units', { method: u ? 'PATCH' : 'POST', body });
            toast(u ? 'Modifications enregistrées.' : 'Rangement ajouté.');
            close(result.unit.id);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    }, { wide: true });

    if (saved) {
      if (parent) state.expanded.add(parent.id);
      await reload();
      select(saved, { focus: !u });
    }
  }

  // ===== Chargement =====

  try {
    const [payload, types] = await Promise.all([api(`/rooms/${roomId}`), api('/storage-types')]);
    state.types = types.types;
    apply(payload);
    const highlight = Number(new URLSearchParams(location.search).get('unit'));
    if (highlight && unitById(highlight)) {
      select(highlight);
      scene?.highlight(highlight);
    }
  } catch (error) {
    title.textContent = 'Salle introuvable';
    showError(error);
  }
}
