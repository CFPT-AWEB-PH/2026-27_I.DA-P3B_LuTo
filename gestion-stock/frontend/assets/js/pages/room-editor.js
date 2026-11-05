// Éditeur 3D d'une salle : scène, arborescence des rangements et panneau de propriétés.

import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { busy, confirmDialog, emptyState, errorState, field, locationLabel, openDialog, plural, showError, skeleton, toast } from '../ui.js';
import { icon } from '../icons.js';
import { setTitle } from '../app.js';
import {
  findFreeSpot, formatLength, fromDisplay, rotateAroundCenter, SHAPE_LABELS, toDisplay, unitInterior, UNITS,
} from '../spatial.js';
import { openOpeningsDialog, openRoomDialog } from './room-form.js';
import { addDialog, formatQuantity, itemDialog, moveDialog, pickItem, takeDialog } from '../stock-dialogs.js';
import { contextMenu, openContextMenu } from '../context-menu.js';
import { itemMenuItems } from '../item-menu.js';

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
  const hint = h('p', { class: 'editor-hint' }, 'Clic : sélectionner  ·  Glisser : tourner  ·  Clic droit : menu d’actions  ·  Clic droit + glisser : déplacer la vue  ·  Molette : zoomer  ·  Double-clic : ouvrir un tiroir');
  // L'aide s'efface dès la première interaction : elle a servi
  stage.addEventListener('pointerdown', () => hint.classList.add('is-faded'), { once: true });

  const pressed = (button, on) => button.setAttribute('aria-pressed', String(on));
  const label = (text) => h('span', { class: 'btn-label' }, text);
  const dimsButton = h('button', { class: 'btn btn-small', type: 'button', 'aria-pressed': 'false', title: 'Afficher les cotes' }, icon('ruler'), label('Cotes'));
  const moveButton = can('furniture.edit') ? h('button', { class: 'btn btn-small', type: 'button', 'aria-pressed': 'false', title: 'Faire glisser le meuble sélectionné au sol (Maj : pas de 10 cm)' }, icon('move'), label('Déplacer')) : null;
  const colorSelect = can('stock.view') ? h('select', { class: 'select-small', 'aria-label': 'Couleurs des rangements' },
    h('option', { value: 'original' }, "Couleurs d'origine"), h('option', { value: 'fill' }, 'Couleurs de remplissage')) : null;
  const legend = h('div', { class: 'fill-legend', hidden: true });
  const wallSelect = h('select', { class: 'select-small', 'aria-label': 'Affichage des murs' },
    [['auto', 'Murs : auto'], ['transparent', 'Murs : transparents'], ['hidden', 'Murs : masqués'], ['solid', 'Murs : pleins']].map(([v, t]) => h('option', { value: v }, t)));
  const preset = (name, text) => h('button', { class: 'segmented-option', type: 'button', onclick: () => scene?.viewPreset(name) }, text);

  view.classList.add('view-wide');
  append(view, h('div', { class: 'editor' },
    h('div', { class: 'editor-toolbar' },
      h('div', { class: 'editor-heading' },
        h('a', { class: 'icon-btn icon-btn-small', href: '/rooms', 'aria-label': 'Retour aux salles', title: 'Retour aux salles' }, icon('arrowLeft')),
        title),
      h('div', { class: 'editor-tools' },
      h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Points de vue' },
        h('div', { class: 'segmented' }, preset('iso', 'Vue 3D'), preset('top', 'Dessus'), preset('door', 'Porte'))),
      h('span', { class: 'toolbar-divider', 'aria-hidden': 'true' }),
      h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Affichage' }, dimsButton, wallSelect, colorSelect, moveButton,
        h('button', { class: 'btn btn-small btn-ghost', type: 'button', title: 'Réafficher les éléments masqués', onclick: () => { scene?.showAll(); renderTree(); renderDetails(); } }, icon('eye'), label('Tout afficher'))),
      h('div', { class: 'toolbar-group toolbar-end' },
        can('rooms.edit') && h('button', { class: 'btn btn-small btn-ghost', type: 'button', title: 'Fenêtres, portes et passages', onclick: () => openOpenings() }, icon('door'), label('Ouvertures')),
        can('rooms.edit') && h('button', { class: 'btn btn-small btn-ghost', type: 'button', title: 'Dimensions, porte et apparence', onclick: () => openSettings() }, icon('sliders'), label('Salle')),
        can('furniture.edit') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(null, null) }, icon('plus'), 'Meuble'))),
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
    setTitle(payload.room.name);
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
      onContextMenu: (unit, point) => {
        if (unit) select(unit.id);
        openContextMenu(unit ? unitMenuItems(unit) : roomMenuItems(), { x: point.clientX, y: point.clientY, label: unit ? `Actions pour ${unit.name}` : 'Actions de la salle' });
      },
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
  const setMoveMode = (on) => {
    if (moveButton) pressed(moveButton, on);
    scene?.setMoveMode(on);
  };
  moveButton?.addEventListener('click', () => setMoveMode(moveButton.getAttribute('aria-pressed') !== 'true'));

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
      const row = h('div', { class: 'tree-row' },
          kids.length
            ? h('button', { class: 'tree-toggle', type: 'button', 'aria-expanded': String(open), 'aria-label': `${open ? 'Replier' : 'Déplier'} ${u.name}`,
              onclick: () => { if (open) state.expanded.delete(u.id); else state.expanded.add(u.id); renderTree(); } }, icon('chevronRight'))
            : h('span', { class: 'tree-toggle' }),
          h('button', { class: 'tree-label', type: 'button', onclick: () => select(u.id, { focus: true }) },
            h('span', { class: 'tree-swatch', dataset: { color: u.color } }), u.name, u.label && h('span', { class: 'badge' }, u.label)),
          h('button', { class: 'tree-eye', type: 'button', 'aria-pressed': String(hidden), title: hidden ? 'Afficher' : 'Masquer temporairement', 'aria-label': `${hidden ? 'Afficher' : 'Masquer'} ${u.name}`,
            onclick: () => { scene?.setHidden(u.id, !hidden); renderTree(); } }, icon(hidden ? 'eyeOff' : 'eye')),
        );
      contextMenu(row, () => unitMenuItems(u), { label: `Actions pour ${u.name}` });
      return h('li', { class: ['tree-node', u.id === state.selectedId && 'is-selected', hidden && 'is-hidden'] },
        row,
        kids.length > 0 && open && h('ul', { class: 'tree-children' }, kids.map(node)),
      );
    };
    const roots = childrenOf(null);
    clear(tree,
      h('h2', { class: 'panel-subtitle' }, 'Rangements', h('span', { class: 'count' }, String(state.payload.units.length))),
      roots.length ? h('ul', { class: 'tree' }, roots.map(node)) : emptyState(can('furniture.edit') ? 'Ajoutez un premier meuble : armoire, étagère, bac…' : 'Aucun meuble dans cette salle.', {
        iconName: 'drawer', action: can('furniture.edit') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(null, null) }, icon('plus'), 'Ajouter un meuble'),
      }));
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
        h('div', { class: 'detail-header' }, h('h2', { class: 'panel-title' }, room.name), room.label && h('span', { class: 'badge' }, room.label)),
        room.description && h('p', { class: 'muted small' }, room.description),
        h('dl', { class: 'props' },
          h('dt', {}, 'Hauteur'), h('dd', {}, formatLength(room.height_mm, unit)),
          h('dt', {}, 'Murs'), h('dd', {}, `${room.outline.length} (épaisseur ${formatLength(room.wall_thickness_mm, unit)})`),
          h('dt', {}, 'Porte'), h('dd', {}, `mur 1, à ${formatLength(room.door_offset_mm, unit)} de l'origine, ${formatLength(room.door_width_mm, unit)} × ${formatLength(room.door_height_mm, unit)}`),
          h('dt', {}, 'Ouvertures'), h('dd', {}, String(openings.length)),
          h('dt', {}, 'Meubles'), h('dd', {}, String(childrenOf(null).length)),
          h('dt', {}, 'Rangements'), h('dd', {}, String(units.length))),
        h('p', { class: 'alert alert-info', role: 'note' }, icon('info'), h('span', {}, 'Sélectionnez un meuble dans la vue ou dans la liste pour voir son contenu.')),
        h('p', { class: 'field-hint' }, "Repère : l'origine est au coin gauche du mur de la porte (vu de l'intérieur), au sol. X longe le mur de la porte, Z entre dans la salle, Y monte."));
      return;
    }

    const parent = unitById(u.parent_id);
    const inside = unitInterior(u);
    const kids = childrenOf(u.id);
    const editRight = can(u.parent_id === null ? 'furniture.edit' : 'storage.edit');
    const hidden = scene?.hidden.has(u.id) ?? false;

    const actions = h('div', { class: 'detail-actions' },
      editRight && h('button', { class: 'btn btn-small', type: 'button', onclick: () => editUnit(u, parent) }, icon('edit'), 'Modifier'),
      u.shape === 'drawer' && h('button', { class: 'btn btn-small', type: 'button', onclick: () => { scene?.setDrawerOpen(u.id, !scene.isDrawerOpen(u.id)); setTimeout(renderDetails, 260); } },
        icon('drawer'), scene?.isDrawerOpen(u.id) ? 'Fermer le tiroir' : 'Ouvrir le tiroir'),
      h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Centrer la vue', 'aria-label': 'Centrer la vue sur ce rangement', onclick: () => scene?.focus(u.id) }, icon('focus')),
      h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: hidden ? 'Afficher' : 'Masquer temporairement', 'aria-label': hidden ? 'Afficher ce rangement' : 'Masquer ce rangement', onclick: () => { scene?.setHidden(u.id, !hidden); renderTree(); renderDetails(); } }, icon(hidden ? 'eyeOff' : 'eye')),
      editRight && h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', title: 'Supprimer', 'aria-label': `Supprimer ${u.name}`, onclick: () => remove(u) }, icon('trash')),
    );
    const addInside = can('storage.edit') && u.can_contain_units && inside && h('div', { class: 'detail-actions' },
      h('button', { class: 'btn btn-small', type: 'button', onclick: () => editUnit(null, u) }, icon('plus'), 'Rangement dedans'),
      kids.length === 0 && h('button', { class: 'btn btn-small', type: 'button', onclick: () => generate(u) }, icon('grid'), "Générer l'intérieur"));

    const position = editRight && nudgePad(u);

    clear(details,
      h('div', { class: 'detail-header' },
        h('h2', { class: 'panel-title' }, u.name),
        h('span', { class: 'badge' }, u.type_name), u.label && h('span', { class: 'badge badge-accent' }, u.label)),
      locationLabel(u.path),
      u.description && h('p', { class: 'muted small' }, u.description),
      actions,
      u.can_hold_items && can('stock.view') && contentsSection(u),
      (u.can_contain_units || kids.length > 0) && [
        h('h3', { class: 'panel-subtitle' }, 'Rangements à l’intérieur', h('span', { class: 'count' }, String(kids.length))),
        kids.length
          ? h('ul', { class: 'plain-list' }, kids.map((k) => h('li', {}, h('button', { class: 'tree-label', type: 'button', onclick: () => select(k.id, { focus: true }) }, icon('chevronRight'), k.name, h('span', { class: 'sub' }, k.type_name)))))
          : h('p', { class: 'muted small' }, 'Aucun pour le moment.'),
        addInside],
      h('h3', { class: 'panel-subtitle' }, 'Dimensions et position'),
      h('dl', { class: 'props' },
        u.reference && [h('dt', {}, 'Référence'), h('dd', {}, u.reference)],
        h('dt', {}, 'Dimensions (L × P × H)'), h('dd', {}, `${formatLength(u.width_mm, unit)} × ${formatLength(u.depth_mm, unit)} × ${formatLength(u.height_mm, unit)}`),
        h('dt', {}, u.parent_id === null ? 'Position (coin arrière gauche)' : `Position dans « ${parent?.name} »`),
        h('dd', {}, `X ${formatLength(u.pos_x_mm, unit)} · Y ${formatLength(u.pos_y_mm, unit)} · Z ${formatLength(u.pos_z_mm, unit)}`),
        u.parent_id === null && [h('dt', {}, 'Rotation'), h('dd', {}, `${u.rotation_deg}°`)],
        h('dt', {}, 'Épaisseur des parois'), h('dd', {}, formatLength(u.panel_mm, unit)),
        inside && [h('dt', {}, 'Intérieur utile'), h('dd', {}, `${formatLength(inside.width, unit)} × ${formatLength(inside.depth, unit)} × ${formatLength(inside.height, unit)}`)]),
      position,
    );
  }

  function nudgePad(u) {
    const unit = unitLabel();
    const stepSelect = h('select', { class: 'select-small', 'aria-label': 'Pas de déplacement' },
      [[1, '1 mm'], [10, '1 cm'], [100, '10 cm'], [500, '50 cm']].map(([v, t]) => h('option', { value: v, selected: v === 10 }, t)));
    const b = (text, key, sign, title) => h('button', { class: 'btn btn-small', type: 'button', title, 'aria-label': title, onclick: (e) => busy(e.currentTarget, () => nudge(u, key, sign * Number(stepSelect.value))) }, text);
    return h('div', { class: 'nudge' },
      h('h3', { class: 'panel-subtitle' }, 'Ajuster la position'),
      h('div', { class: 'nudge-row' }, h('label', { class: 'small muted', for: stepSelect.id = `step-${u.id}` }, 'Pas'), stepSelect,
        u.parent_id === null && [
          h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', title: 'Pivoter de 90° à gauche', 'aria-label': 'Pivoter de 90° à gauche', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg - 90)) }, icon('rotateLeft')),
          h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', title: 'Pivoter de 90° à droite', 'aria-label': 'Pivoter de 90° à droite', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg + 90)) }, icon('rotateRight'))]),
      h('div', { class: 'nudge-grid' },
        b('X −', 'pos_x_mm', -1, 'Vers la gauche'), b('X +', 'pos_x_mm', 1, 'Vers la droite'),
        b('Y −', 'pos_y_mm', -1, 'Plus bas'),
        b(u.parent_id === null ? 'Z −' : 'Arrière', 'pos_z_mm', -1, u.parent_id === null ? 'Vers le mur de la porte' : 'Vers le fond'),
        b(u.parent_id === null ? 'Z +' : 'Avant', 'pos_z_mm', 1, u.parent_id === null ? 'Vers le fond de la salle' : 'Vers la façade'),
        b('Y +', 'pos_y_mm', 1, 'Plus haut')),
      h('p', { class: 'field-hint' }, `Au clavier dans la vue : flèches (Maj : ×10), Page préc./suiv. pour la hauteur. Unité : ${unit}.`),
    );
  }

  /** Objets rangés dans ce rangement (et ses sous-rangements), avec prise et ajout directs. */
  function contentsSection(u) {
    const body = h('div', {}, skeleton('text', 2));
    const location = { unit_id: u.id, path: u.path };
    const load = async () => {
      try {
        const { contents } = await api(`/storage-units/${u.id}/contents?deep=1`);
        clear(body, contents.length
          ? h('ul', { class: 'plain-list contents-list' }, contents.map((c) => withItemMenu(c, h('li', { class: 'list-row' },
            h('div', { class: 'list-row-main' },
              h('a', { href: `/items/${c.item_id}` }, c.name),
              h('span', { class: 'sub' }, formatQuantity(c.quantity, c.unit), c.unit_id !== u.id && `, dans ${c.path.split(' › ').slice(-1)[0]}`)),
            can('stock.take') && h('button', { class: 'btn btn-small', type: 'button', 'aria-label': `Prendre ${c.name}`, onclick: async () => {
              const item = { id: c.item_id, name: c.name, unit: c.unit, is_consumable: c.is_consumable };
              if (await takeDialog(item, { unit_id: c.unit_id, path: c.path, quantity: c.quantity })) { await reload(); }
            } }, 'Prendre')))))
          : h('p', { class: 'muted small' }, 'Aucun objet rangé ici.'));
      } catch (error) { clear(body, errorState(error, load)); }
    };
    load();
    return h('div', {},
      h('h3', { class: 'panel-subtitle contents-heading' }, 'Objets rangés ici', u.capacity && h('span', { class: 'count' }, `capacité ${formatQuantity(u.capacity)}`)),
      body,
      h('div', { class: 'detail-actions' },
        can('stock.add') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          const item = await pickItem('Ajouter du stock ici : choisir l\'objet');
          if (item && await addDialog(item, location)) await reload();
        } }, icon('plus'), 'Ajouter du stock'),
        can('items.edit') && can('stock.add') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
          if (await itemDialog(null, { canAddStock: true, location: { id: u.id, path: u.path } })) await reload();
        } }, 'Nouvel objet')));
  }

  // ===== Menus contextuels =====

  function withItemMenu(c, element) {
    const item = { id: c.item_id, name: c.name, unit: c.unit, is_consumable: c.is_consumable };
    contextMenu(element, () => itemMenuItems(item, session, {
      reload, location: { unit_id: c.unit_id, path: c.path, quantity: c.quantity },
    }), { label: `Actions pour ${c.name}` });
    return element;
  }

  /** Salle (clic droit dans le vide de la vue 3D). */
  function roomMenuItems() {
    return [
      can('furniture.edit') && { label: 'Ajouter un meuble…', icon: 'plus', onSelect: () => editUnit(null, null) },
      can('rooms.edit') && { label: 'Modifier les paramètres…', icon: 'sliders', onSelect: openSettings },
      can('rooms.edit') && { label: 'Gérer les ouvertures…', icon: 'door', onSelect: openOpenings },
      { separator: true },
      { label: 'Vue 3D', icon: 'cube', onSelect: () => scene?.viewPreset('iso') },
      { label: 'Vue de dessus', icon: 'grid', onSelect: () => scene?.viewPreset('top') },
      { label: 'Vue depuis la porte', icon: 'door', onSelect: () => scene?.viewPreset('door') },
      { label: 'Tout afficher', icon: 'eye', onSelect: () => { scene?.showAll(); renderTree(); renderDetails(); } },
    ];
  }

  /** Meuble ou rangement : mêmes actions que le panneau de propriétés, selon les permissions. */
  function unitMenuItems(u) {
    const parent = unitById(u.parent_id);
    const furniture = u.parent_id === null;
    const editRight = can(furniture ? 'furniture.edit' : 'storage.edit');
    const canNest = can('storage.edit') && u.can_contain_units && unitInterior(u);
    const nest = (shape) => () => editUnit(null, u, { shape });
    const hidden = scene?.hidden.has(u.id) ?? false;
    const showContents = () => {
      select(u.id);
      // Le panneau vient d'être reconstruit : aller aux objets (sous la vue 3D sur mobile)
      details.querySelector('.contents-heading')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    return [
      { label: 'Sélectionner et centrer', icon: 'focus', onSelect: () => select(u.id, { focus: true }) },
      u.can_hold_items && can('stock.view') && { label: 'Consulter le contenu', icon: 'box', onSelect: showContents },
      u.shape === 'drawer' && { label: scene?.isDrawerOpen(u.id) ? 'Fermer le tiroir' : 'Ouvrir le tiroir', icon: 'drawer', onSelect: () => {
        scene?.setDrawerOpen(u.id, !scene.isDrawerOpen(u.id));
        setTimeout(renderDetails, 260);
      } },
      { separator: true },
      editRight && { label: furniture ? 'Modifier les dimensions…' : 'Modifier…', icon: 'edit', onSelect: () => editUnit(u, parent) },
      editRight && !furniture && { label: 'Renommer…', icon: 'tag', onSelect: () => rename(u) },
      furniture && can('furniture.edit') && moveButton && { label: 'Déplacer dans la salle', icon: 'move', onSelect: () => {
        select(u.id);
        setMoveMode(true);
        toast('Faites glisser le meuble au sol. Maj : pas de 10 cm.', 'info');
      } },
      canNest && furniture && { label: 'Ajouter un tiroir…', icon: 'drawer', onSelect: nest('drawer') },
      canNest && furniture && { label: 'Ajouter une étagère…', icon: 'layers', onSelect: nest('shelf') },
      canNest && !furniture && { label: 'Ajouter un sous-rangement…', icon: 'plus', onSelect: nest('box') },
      { separator: true },
      u.can_hold_items && can('stock.add') && { label: 'Ajouter des objets…', icon: 'plus', onSelect: async () => {
        const item = await pickItem(`Ajouter dans « ${u.name} » : choisir l'objet`);
        if (item && await addDialog(item, { unit_id: u.id, path: u.path })) { await reload(); select(u.id); }
      } },
      u.can_hold_items && can('stock.move') && { label: 'Déplacer des objets…', icon: 'move', onSelect: () => moveContents(u) },
      { separator: true },
      { label: hidden ? 'Afficher' : 'Masquer temporairement', icon: hidden ? 'eye' : 'eyeOff', onSelect: () => { scene?.setHidden(u.id, !hidden); renderTree(); renderDetails(); } },
      editRight && { label: 'Supprimer…', icon: 'trash', danger: true, onSelect: () => remove(u) },
    ];
  }

  async function rename(u) {
    const saved = await openDialog(`Renommer « ${u.name} »`, (close) => {
      const name = h('input', { name: 'name', required: true, maxlength: 120, value: u.name });
      const form = h('form', { class: 'dialog-body', novalidate: true }, field('Nom', name),
        h('div', { class: 'dialog-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
          h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Renommer'))));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            await api(`/storage-units/${u.id}`, { method: 'PATCH', body: { name: name.value } });
            close(true);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });
    if (saved) await reload();
  }

  async function moveContents(u) {
    try {
      const { contents } = await api(`/storage-units/${u.id}/contents`);
      if (contents.length === 0) return toast(`Aucun objet directement dans « ${u.name} ».`, 'info');
      const c = contents.length === 1 ? contents[0] : await openDialog(`Déplacer depuis « ${u.name} »`, (close) => h('div', { class: 'dialog-body' },
        h('ul', { class: 'plain-list choice-list' }, contents.map((x) => h('li', {},
          h('button', { class: 'choice', type: 'button', onclick: () => close(x) }, x.name, h('strong', { class: 'choice-side' }, formatQuantity(x.quantity, x.unit)))))),
        h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'))));
      if (!c) return;
      const done = await moveDialog({ id: c.item_id, name: c.name, unit: c.unit }, { unit_id: c.unit_id, path: c.path, quantity: c.quantity });
      if (done) { await reload(); select(u.id); }
    } catch (error) { showError(error); }
  }

  // ===== Actions =====

  async function openSettings() {
    const result = await openRoomDialog(state.payload.room);
    if (result) apply(result);
  }

  async function openOpenings() {
    await openOpeningsDialog(state.payload, (payload) => apply(payload));
  }

  async function remove(u) {
    const count = state.payload.units.filter((x) => x.path.startsWith(`${u.path} › `)).length;
    const ok = await confirmDialog({
      title: 'Supprimer',
      message: count ? `« ${u.name} » et ${plural(count, 'rangement')} à l'intérieur seront supprimés.` : `« ${u.name} » sera supprimé.`,
      confirmLabel: 'Supprimer', danger: true,
    });
    if (!ok) return;
    try {
      await api(`/storage-units/${u.id}`, { method: 'DELETE' });
      toast(`« ${u.name} » supprimé.`);
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
          h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Générer'))));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            const r = await api(`/storage-units/${u.id}/generate`, { method: 'POST', body: {
              rows: Number(rows.value), columns: Number(columns.value), gap_mm: Number(gap.value || 0), type_id: Number(type.value),
            } });
            toast(`${plural(r.created, 'rangement créé', 'rangements créés')}.`);
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
  async function editUnit(u, parent, { shape = null } = {}) {
    const room = state.payload.room;
    const unit = room.display_unit;
    const isFurniture = parent === null;
    const types = state.types;
    const saved = await openDialog(u ? `Modifier « ${u.name} »` : isFurniture ? 'Nouveau meuble' : `Nouveau rangement dans « ${parent.name} »`, (close) => {
      const inside = parent ? unitInterior(parent) : null;
      const typeSelect = h('select', { name: 'type_id' }, types.map((t) => h('option', { value: t.id, selected: t.id === (u?.type_id ?? types.find((x) => x.shape === (shape ?? (isFurniture ? 'cabinet' : 'drawer')))?.id) }, `${t.name} (${SHAPE_LABELS[t.shape]})`)));
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
          h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, u ? 'Enregistrer' : isFurniture ? 'Ajouter le meuble' : 'Ajouter le rangement'))));
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
            toast(u ? 'Modifications enregistrées.' : `« ${name.value} » ajouté.`);
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
    const query = new URLSearchParams(location.search);
    const action = query.get('action');
    if (action) {
      query.delete('action');
      history.replaceState(null, '', `${location.pathname}${query.size ? `?${query}` : ''}`);
      if (action === 'add-furniture' && can('furniture.edit')) editUnit(null, null);
      if (action === 'openings' && can('rooms.edit')) openOpenings();
    }
  } catch (error) {
    title.textContent = error.status === 404 ? 'Salle introuvable' : 'Salle';
    clear(details, errorState(error, () => location.reload()));
  }
}
