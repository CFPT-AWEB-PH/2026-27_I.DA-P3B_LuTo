// Éditeur 3D d'une salle : scène, arborescence des rangements et panneau de propriétés.

import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { busy, confirmDialog, emptyState, errorState, field, locationLabel, openDialog, plural, showError, skeleton, toast } from '../ui.js';
import { icon } from '../icons.js';
import { setTitle } from '../app.js';
import {
  findFreeSpot, formatLength, freeOffset, fromDisplay, gridLayout, nearestWall, openingProblem, referenceDoor, rotateAroundCenter, SHAPE_LABELS, stackLayout, toDisplay, unitInterior, unitProblem, UNITS, wallLength,
} from '../spatial.js';
import { openOpeningsDialog, openRoomDialog } from './room-form.js';
import { formatQuantity, itemDialog, moveDialog, storeItemsDialog, takeDialog } from '../stock-dialogs.js';
import { contextMenu, openContextMenu } from '../context-menu.js';
import { itemMenuItems } from '../item-menu.js';
import { clipboard, History, MOD, typingOrDialog } from '../history.js';
import { createUnitActions, shortcutsDialog, templateSummary, templateThumb, withoutIds } from '../unit-actions.js';

let scene = null;
let removeShortcuts = null;

export function dispose() {
  removeShortcuts?.();
  removeShortcuts = null;
  scene?.dispose();
  scene = null;
}

export async function render(view, session, params) {
  const roomId = Number(params.id);
  const can = (p) => session.can(p);
  const state = { payload: null, types: [], templates: [], selectedId: null, selectedOpening: null, expanded: new Set(), busy: false, panel: null, leftTab: 'tree' };

  const title = h('h1', { class: 'editor-title' }, 'Chargement…');
  const tree = h('nav', { class: 'editor-tree', 'aria-label': 'Rangements de la salle' });
  const history = new History({ onChange: () => refreshHistoryButtons() });
  const details = h('aside', { class: 'editor-details', 'aria-live': 'polite' });
  const stage = h('div', { class: 'editor-stage' });
  const hint = h('p', { class: 'editor-hint' }, `Clic : sélectionner  ·  Glisser : tourner  ·  Clic droit : menu  ·  Molette : zoomer  ·  Double-clic : ouvrir un tiroir  ·  ${MOD}+Z : annuler  ·  ? : raccourcis`);
  // L'aide s'efface dès la première interaction : elle a servi
  stage.addEventListener('pointerdown', () => hint.classList.add('is-faded'), { once: true });

  const pressed = (button, on) => button.setAttribute('aria-pressed', String(on));
  const label = (text) => h('span', { class: 'btn-label' }, text);
  const dimsButton = h('button', { class: 'btn btn-small', type: 'button', 'aria-pressed': 'false', title: 'Afficher les cotes' }, icon('ruler'), label('Cotes'));
  // Modes du gizmo : W, E, R au clavier dans la vue, comme dans les logiciels de 3D
  const canEditSomething = can('furniture.edit') || can('storage.edit') || can('rooms.edit');
  const GIZMO_MODES = [['none', 'Sélection', 'eye', 'Sélection seule, sans poignées (Q)'], ['translate', 'Déplacer', 'move', 'Déplacer avec les flèches du gizmo (W)'], ['rotate', 'Pivoter', 'rotateRight', 'Pivoter autour du centre (E)'], ['scale', 'Taille', 'ruler', 'Changer les dimensions (R)']];
  const gizmoButtons = GIZMO_MODES.map(([mode, text, iconName, tip]) => h('button', {
    class: 'segmented-option', type: 'button', role: 'radio', title: tip, 'aria-checked': String(mode === 'translate'),
    onclick: () => setGizmoMode(mode),
  }, icon(iconName), label(text)));
  const gizmoSwitch = canEditSomething ? h('div', { class: 'segmented gizmo-switch', role: 'radiogroup', 'aria-label': 'Mode du gizmo' }, gizmoButtons) : null;
  function setGizmoMode(mode) {
    GIZMO_MODES.forEach(([m], i) => gizmoButtons[i].setAttribute('aria-checked', String(m === mode)));
    scene?.setGizmoMode(mode);
  }
  const hud = h('div', { class: 'gizmo-hud', hidden: true, role: 'status' });
  const colorSelect = can('stock.view') ? h('select', { class: 'select-small', 'aria-label': 'Couleurs des rangements' },
    h('option', { value: 'original' }, "Couleurs d'origine"), h('option', { value: 'fill' }, 'Couleurs de remplissage')) : null;
  const legend = h('div', { class: 'fill-legend', hidden: true });
  const wallSelect = h('select', { class: 'select-small', 'aria-label': 'Affichage des murs' },
    [['auto', 'Murs : auto'], ['transparent', 'Murs : transparents'], ['hidden', 'Murs : masqués'], ['solid', 'Murs : pleins']].map(([v, t]) => h('option', { value: v }, t)));
  const preset = (name, text) => h('button', { class: 'segmented-option', type: 'button', onclick: () => scene?.viewPreset(name) }, text);
  const undoButton = h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', disabled: true, onclick: () => undo() }, icon('undo'));
  const redoButton = h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', disabled: true, onclick: () => redo() }, icon('redo'));
  function refreshHistoryButtons() {
    undoButton.disabled = !history.canUndo;
    redoButton.disabled = !history.canRedo;
    const tip = (word, label, keys) => (label ? `${word} : ${label} (${keys})` : `${word} (${keys})`);
    for (const [button, word, label, keys] of [[undoButton, 'Annuler', history.undoLabel, `${MOD}+Z`], [redoButton, 'Rétablir', history.redoLabel, `${MOD}+Y`]]) {
      button.title = tip(word, label, keys);
      button.setAttribute('aria-label', tip(word, label, keys));
    }
  }
  refreshHistoryButtons();

  view.classList.add('view-wide');
  append(view, h('div', { class: 'editor' },
    h('div', { class: 'editor-toolbar' },
      h('div', { class: 'editor-heading' },
        h('a', { class: 'icon-btn icon-btn-small', href: '/plan', 'aria-label': 'Retour au plan du bâtiment', title: 'Retour au plan du bâtiment' }, icon('arrowLeft')),
        title),
      h('div', { class: 'editor-tools' },
      canEditSomething && h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Historique' }, undoButton, redoButton),
      h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Points de vue' },
        h('div', { class: 'segmented' }, preset('iso', 'Vue 3D'), preset('top', 'Dessus'), preset('door', 'Porte'))),
      h('span', { class: 'toolbar-divider', 'aria-hidden': 'true' }),
      h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Affichage' }, gizmoSwitch, dimsButton, wallSelect, colorSelect,
        h('button', { class: 'btn btn-small btn-ghost', type: 'button', title: 'Réafficher les éléments masqués', onclick: () => { scene?.showAll(); renderTree(); renderDetails(); } }, icon('eye'), label('Tout afficher'))),
      h('div', { class: 'toolbar-group toolbar-end' },
        h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Raccourcis clavier (?)', 'aria-label': 'Raccourcis clavier', onclick: () => shortcutsDialog() }, icon('help')),
        can('rooms.edit') && h('button', { class: 'btn btn-small', type: 'button', title: 'Ajouter une porte, à déplacer ensuite avec le gizmo', onclick: () => addOpening('door') }, icon('door'), label('Porte')),
        can('rooms.edit') && h('button', { class: 'btn btn-small', type: 'button', title: 'Ajouter une fenêtre, à déplacer ensuite avec le gizmo', onclick: () => addOpening('window') }, icon('window'), label('Fenêtre')),
        can('rooms.edit') && h('button', { class: 'btn btn-small btn-ghost', type: 'button', title: 'Dimensions, porte et apparence', onclick: () => openSettings() }, icon('sliders'), label('Salle')),
        can('furniture.edit') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(null, null) }, icon('plus'), 'Meuble'))),
    ),
    tree, h('div', { class: 'editor-canvas' }, stage, hint, legend, hud), details,
  ));

  const history_replace = (url) => window.history.replaceState(null, '', url);

  // ===== Données =====

  const unitById = (id) => state.payload?.units.find((u) => u.id === id) ?? null;
  const childrenOf = (id) => state.payload.units.filter((u) => u.parent_id === id);
  const unitLabel = () => state.payload.room.display_unit;
  const openingById = (id) => (id === 'ref' ? referenceDoor(state.payload.room) : state.payload.openings.find((o) => o.id === id) ?? null);
  const actions = createUnitActions({ history, can, reload: () => reload() });

  function apply(payload) {
    state.payload = payload;
    title.textContent = payload.room.name;
    setTitle(payload.room.name);
    if (state.selectedId !== null && !unitById(state.selectedId)) {
      state.selectedId = null;
      const url = new URL(location.href);
      url.searchParams.delete('unit');
      history_replace(url);
    }
    if (state.selectedOpening !== null && !openingById(state.selectedOpening)) state.selectedOpening = null;
    scene?.setData(payload.room, payload.openings, payload.units, payload.contents ?? []);
    applyColors();
    if (state.selectedOpening !== null) scene?.selectOpening(state.selectedOpening);
    else scene?.select(state.selectedId);
    renderTree();
    renderDetails();
  }

  async function reload() {
    apply(await api(`/rooms/${roomId}`));
  }

  function select(id, { focus = false } = {}) {
    state.selectedId = id;
    state.selectedOpening = null;
    for (let u = unitById(id); u && u.parent_id !== null; u = unitById(u.parent_id)) state.expanded.add(u.parent_id);
    scene?.select(id);
    if (focus && id !== null) scene?.focus(id);
    const url = new URL(location.href);
    if (id === null) url.searchParams.delete('unit'); else url.searchParams.set('unit', id);
    history_replace(url);
    renderTree();
    renderDetails();
  }

  function selectOpening(id, { focus = false } = {}) {
    state.selectedId = null;
    state.selectedOpening = id;
    scene?.selectOpening(id);
    if (focus) scene?.focusOpening(id);
    const url = new URL(location.href);
    url.searchParams.delete('unit');
    history_replace(url);
    renderTree();
    renderDetails();
  }

  async function undo() {
    if (state.panel) return;
    if (await history.undo()) renderTree();
  }

  async function redo() {
    if (state.panel) return;
    if (await history.redo()) renderTree();
  }

  async function patchUnit(unit, changes, label) {
    try {
      await actions.patch(unit, changes, label);
      return true;
    } catch (error) {
      showError(error);
      return false;
    }
  }

  // ===== Scène 3D =====

  try {
    scene = new (await import('../three/room-scene.js')).RoomScene(stage, {
      onSelect: (id) => { if (!state.panel) select(id); },
      onSelectOpening: (id) => { if (!state.panel) selectOpening(id); },
      canEdit: (u) => can(u.parent_id === null ? 'furniture.edit' : 'storage.edit'),
      canEditRoom: () => can('rooms.edit') && !state.panel,
      onOpeningChange: (o, problem) => showOpeningHud(o, problem),
      onOpeningEnd: (original, o) => { hud.hidden = true; commitOpening(original, o); },
      onGizmoChange: (c, problem, isDraft) => {
        showHud(c, problem);
        if (isDraft) state.panel?.onGizmo?.(c, false);
      },
      onGizmoEnd: async (unit, c, isDraft) => {
        hud.hidden = true;
        if (isDraft) return state.panel?.onGizmo?.(c, true);
        const changes = {};
        for (const key of ['pos_x_mm', 'pos_y_mm', 'pos_z_mm', 'rotation_deg', 'width_mm', 'depth_mm', 'height_mm']) {
          if (c[key] !== unit[key]) changes[key] = c[key];
        }
        if (Object.keys(changes).length === 0) return scene?.rebuildUnits();
        // Emplacement impossible (chevauchement…) : retour à sa place, avec la raison, sans rien envoyer
        const problem = unitProblem(state.payload.room, state.payload.units, unitById(unit.parent_id), c);
        if (problem) {
          toast(`${problem} « ${unit.name} » revient à sa place.`, 'info');
          return scene?.rebuildUnits();
        }
        if (!await patchUnit(unit, changes, `Déplacer « ${unit.name} »`)) scene?.rebuildUnits();
      },
      onContextMenu: (unit, point, hit) => {
        if (state.panel) return;
        if (hit?.opening) {
          selectOpening(hit.opening.id);
          return openContextMenu(openingMenuItems(hit.opening), { x: point.clientX, y: point.clientY, label: `Actions pour ${openingName(hit.opening)}` });
        }
        if (unit) select(unit.id);
        openContextMenu(unit ? unitMenuItems(unit) : roomMenuItems(hit), { x: point.clientX, y: point.clientY, label: unit ? `Actions pour ${unit.name}` : 'Actions de la salle' });
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
  function showHud(c, problem) {
    const unit = unitLabel();
    hud.hidden = false;
    hud.classList.toggle('is-invalid', Boolean(problem));
    const parts = [`${formatLength(c.width_mm, unit)} × ${formatLength(c.depth_mm, unit)} × ${formatLength(c.height_mm, unit)}`,
      `X ${formatLength(c.pos_x_mm, unit)}  Y ${formatLength(c.pos_y_mm, unit)}  Z ${formatLength(c.pos_z_mm, unit)}`];
    if (c.parent_id === null || c.parent_id === undefined) parts.push(`${c.rotation_deg}°`);
    clear(hud, h('span', {}, parts.join('   ·   ')), problem && h('strong', {}, problem));
  }

  function showOpeningHud(o, problem) {
    const unit = unitLabel();
    hud.hidden = false;
    hud.classList.toggle('is-invalid', Boolean(problem));
    clear(hud, h('span', {}, `Mur ${o.wall_index + 1}   ·   à ${formatLength(o.offset_mm, unit)}   ·   ${formatLength(o.width_mm, unit)} × ${formatLength(o.height_mm, unit)}`), problem && h('strong', {}, problem));
  }

  // Raccourcis de l'éditeur : actifs partout sur la page, sauf pendant une saisie ou dans une fenêtre
  const onKeyDown = (event) => {
    if (typingOrDialog(event)) {
      if (event.key === 'Escape' && state.panel) state.panel.cancel();
      return;
    }
    const key = event.key?.toLowerCase();
    const mod = event.ctrlKey || event.metaKey;
    const u = unitById(state.selectedId);
    const door = state.selectedOpening !== null ? openingById(state.selectedOpening) : null;
    const handle = (action) => { event.preventDefault(); action(); };

    if (mod && key === 'z') return handle(() => (event.shiftKey ? redo() : undo()));
    if (mod && key === 'y') return handle(redo);
    if (state.panel) {
      if (event.key === 'Escape') state.panel.cancel();
      else if (gizmoSwitch && !mod && { q: 1, w: 1, e: 1, r: 1 }[key]) handle(() => setGizmoMode({ q: 'none', w: 'translate', e: 'rotate', r: 'scale' }[key]));
      return;
    }
    if (mod && key === 'c' && u) return handle(() => copyUnit(u));
    if (mod && key === 'x' && u) return handle(() => copyUnit(u, true));
    if (mod && key === 'v') return handle(pasteHere);
    if (mod && key === 'd' && u) return handle(() => duplicateUnit(u));
    if (mod && key === 's' && u) return handle(() => saveTemplate(u));
    if (mod) return undefined;

    if (event.key === 'Escape') return handle(() => select(null));
    if (event.key === '?') return handle(() => shortcutsDialog());
    if ((event.key === 'Delete' || event.key === 'Backspace') && u) return handle(() => remove(u));
    if ((event.key === 'Delete' || event.key === 'Backspace') && door && door.id !== 'ref') return handle(() => deleteOpening(door));
    if (key === 'f' && u) return handle(() => scene?.focus(u.id));
    if (key === 'h' && u) return handle(() => { scene?.setHidden(u.id, !scene.hidden.has(u.id)); renderTree(); renderDetails(); });
    const modes = { q: 'none', w: 'translate', e: 'rotate', r: 'scale' };
    if (gizmoSwitch && modes[key]) return handle(() => setGizmoMode(modes[key]));

    // Flèches : décalage de 10 mm (Maj : 100 mm) ; Pg préc./suiv. : hauteur
    const step = event.shiftKey ? 100 : 10;
    if (door && can('rooms.edit')) {
      const slide = { ArrowLeft: -step, ArrowRight: step }[event.key];
      if (slide && !state.busy) handle(() => nudgeOpening(door, slide));
      return undefined;
    }
    if (!u || !can(u.parent_id === null ? 'furniture.edit' : 'storage.edit')) return undefined;
    const moves = { ArrowLeft: ['pos_x_mm', -step], ArrowRight: ['pos_x_mm', step], ArrowUp: ['pos_z_mm', -step], ArrowDown: ['pos_z_mm', step], PageUp: ['pos_y_mm', step], PageDown: ['pos_y_mm', -step] };
    const move = moves[event.key];
    if (!move || state.busy) return undefined;
    event.preventDefault();
    return nudge(u, move[0], move[1]);
  };
  document.addEventListener('keydown', onKeyDown);
  removeShortcuts = () => document.removeEventListener('keydown', onKeyDown);

  async function nudge(u, key, delta) {
    const parent = unitById(u.parent_id);
    const c = { ...u, [key]: u[key] + delta };
    const problem = unitProblem(state.payload.room, state.payload.units, parent, c);
    if (problem) return toast(problem, 'info');
    state.busy = true;
    await patchUnit(u, { [key]: c[key] }, `Déplacer « ${u.name} »`);
    state.busy = false;
    return undefined;
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
    const tabs = (can('furniture.edit') || can('storage.edit')) && h('div', { class: 'segmented panel-tabs', role: 'tablist', 'aria-label': 'Panneau de gauche' },
      [['tree', 'Rangements'], ['library', 'Bibliothèque']].map(([key, text]) => h('button', {
        class: 'segmented-option', type: 'button', role: 'tab', 'aria-selected': String(state.leftTab === key), 'aria-checked': String(state.leftTab === key),
        onclick: () => { state.leftTab = key; renderTree(); },
      }, text)));
    if (state.leftTab === 'library') {
      clear(tree, tabs, libraryPanel());
      tree.querySelectorAll('.tree-swatch').forEach((el) => { el.style.background = el.dataset.color; });
      return;
    }
    const doors = [referenceDoor(state.payload.room), ...state.payload.openings];
    clear(tree, tabs,
      h('h2', { class: 'panel-subtitle' }, 'Rangements', h('span', { class: 'count' }, String(state.payload.units.length))),
      roots.length ? h('ul', { class: 'tree' }, roots.map(node)) : emptyState(can('furniture.edit') ? 'Ajoutez un premier meuble : armoire, étagère, bac…' : 'Aucun meuble dans cette salle.', {
        iconName: 'drawer', action: can('furniture.edit') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => editUnit(null, null) }, icon('plus'), 'Ajouter un meuble'),
      }),
      h('h2', { class: 'panel-subtitle' }, 'Portes et fenêtres', h('span', { class: 'count' }, String(doors.length))),
      h('ul', { class: 'tree' }, doors.map((o) => {
        const row = h('div', { class: 'tree-row' }, h('span', { class: 'tree-toggle' }),
          h('button', { class: 'tree-label', type: 'button', onclick: () => selectOpening(o.id, { focus: true }) }, icon(o.kind === 'window' ? 'window' : 'door'), openingName(o)));
        contextMenu(row, () => openingMenuItems(o), { label: `Actions pour ${openingName(o)}` });
        return h('li', { class: ['tree-node', o.id === state.selectedOpening && 'is-selected'] }, row);
      })));
    // Pastilles de couleur sans style en ligne (CSP) : couleur appliquée via le CSSOM
    tree.querySelectorAll('.tree-swatch').forEach((el) => { el.style.background = el.dataset.color; });
  }

  // ===== Panneau de propriétés =====

  function renderDetails() {
    if (!state.payload || state.panel) return;
    const unit = unitLabel();
    const u = unitById(state.selectedId);
    if (state.selectedOpening !== null && openingById(state.selectedOpening)) return renderOpeningDetails(openingById(state.selectedOpening));
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
        h('p', { class: 'alert alert-info', role: 'note' }, icon('info'), h('span', {}, 'Cliquez sur un meuble ou une porte, dans la vue ou la liste, pour voir son contenu et le déplacer.')),
        h('p', { class: 'field-hint' }, "Repère : l'origine est au coin gauche du mur de la porte (vu de l'intérieur), au sol. X longe le mur de la porte, Z entre dans la salle, Y monte."));
      return undefined;
    }

    const parent = unitById(u.parent_id);
    const inside = unitInterior(u);
    const kids = childrenOf(u.id);
    const editRight = can(u.parent_id === null ? 'furniture.edit' : 'storage.edit');
    const hidden = scene?.hidden.has(u.id) ?? false;

    const buttons = h('div', { class: 'detail-actions' },
      editRight && h('button', { class: 'btn btn-small', type: 'button', onclick: () => editUnit(u, parent) }, icon('edit'), 'Modifier'),
      u.shape === 'drawer' && h('button', { class: 'btn btn-small', type: 'button', onclick: () => { if (scene?.isDrawerOpen(u.id)) scene.setDrawerOpen(u.id, false); else scene?.focus(u.id); setTimeout(renderDetails, 300); } },
        icon('drawer'), scene?.isDrawerOpen(u.id) ? 'Fermer le tiroir' : 'Ouvrir et regarder dedans'),
      h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Centrer la vue', 'aria-label': 'Centrer la vue sur ce rangement', onclick: () => scene?.focus(u.id) }, icon('focus')),
      h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: hidden ? 'Afficher' : 'Masquer temporairement', 'aria-label': hidden ? 'Afficher ce rangement' : 'Masquer ce rangement', onclick: () => { scene?.setHidden(u.id, !hidden); renderTree(); renderDetails(); } }, icon(hidden ? 'eyeOff' : 'eye')),
      editRight && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: `Copier (${MOD}+C)`, 'aria-label': `Copier ${u.name}`, onclick: () => copyUnit(u) }, icon('copy')),
      editRight && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: `Enregistrer comme modèle (${MOD}+S)`, 'aria-label': `Enregistrer ${u.name} comme modèle`, onclick: () => saveTemplate(u) }, icon('save')),
      editRight && h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', title: 'Supprimer (Suppr)', 'aria-label': `Supprimer ${u.name}`, onclick: () => remove(u) }, icon('trash')),
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
      buttons,
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
    return undefined;
  }

  const openingName = (o) => o.name || { door: 'Porte', window: 'Fenêtre', opening: 'Passage' }[o.kind];
  const OPENING_KINDS = { door: 'Porte', window: 'Fenêtre', opening: 'Passage' };

  /** Propriétés d'une porte ou d'une fenêtre : chaque champ s'enregistre dès qu'on le quitte, avec annulation. */
  function renderOpeningDetails(o) {
    const unit = unitLabel();
    const room = state.payload.room;
    const editable = can('rooms.edit');
    const isRef = o.id === 'ref';
    const num = (key, text, min = 0) => {
      const input = h('input', { type: 'number', step: UNITS[unit].step, min: toDisplay(min, unit), value: toDisplay(o[key], unit), disabled: !editable });
      input.addEventListener('change', () => {
        const mm = fromDisplay(input.value, unit);
        if (Number.isFinite(mm)) commitOpening(o, { ...o, [key]: mm });
      });
      return field(`${text} (${unit})`, input);
    };
    const wall = h('select', { disabled: !editable || isRef, 'aria-label': 'Mur' }, room.outline.map((_, i) => h('option', { value: i, selected: i === o.wall_index }, `Mur ${i + 1}, ${formatLength(wallLength(room.outline, i), unit)}`)));
    wall.addEventListener('change', () => commitOpening(o, { ...o, wall_index: Number(wall.value) }));
    const kind = h('select', { disabled: !editable || isRef }, Object.entries(OPENING_KINDS).map(([v, t]) => h('option', { value: v, selected: v === o.kind }, t)));
    kind.addEventListener('change', () => commitOpening(o, { ...o, kind: kind.value, sill_mm: kind.value === 'window' ? Math.max(o.sill_mm, 900) : 0 }));
    const name = h('input', { maxlength: 80, value: isRef ? 'Porte principale' : o.name, disabled: !editable || isRef });
    name.addEventListener('change', () => commitOpening(o, { ...o, name: name.value }));
    clear(details,
      h('div', { class: 'detail-header' }, h('h2', { class: 'panel-title' }, openingName(o)), h('span', { class: 'badge' }, OPENING_KINDS[o.kind])),
      editable && h('p', { class: 'field-hint' }, isRef
        ? 'Tirez le gizmo pour la faire glisser sur le mur 1. La porte principale reste sur ce mur : elle sert de repère à toute la salle.'
        : 'Tirez le gizmo : la porte suit le mur le plus proche et perce le mur en direct.'),
      h('div', { class: 'panel-form' },
        field('Nom', name),
        h('div', { class: 'form-grid' }, field('Type', kind), field('Mur', wall)),
        h('div', { class: 'form-grid' }, num('offset_mm', 'Distance'), num('width_mm', 'Largeur', 50)),
        h('div', { class: 'form-grid' }, num('height_mm', 'Hauteur', 50), !isRef && num('sill_mm', 'Allège'))),
      editable && !isRef && h('div', { class: 'detail-actions' },
        h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: () => deleteOpening(o) }, icon('trash'), 'Supprimer')));
  }

  /** Champs qui ont changé, écrits où il faut : la porte principale fait partie de la salle. */
  async function writeOpening(id, values) {
    if (id === 'ref') {
      const { offset_mm: door_offset_mm, width_mm: door_width_mm, height_mm: door_height_mm } = values;
      return api(`/rooms/${roomId}`, { method: 'PATCH', body: { door_offset_mm, door_width_mm, door_height_mm } });
    }
    return api(`/openings/${history.id(id)}`, { method: 'PATCH', body: values });
  }

  const openingFields = ({ kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm }) => ({ kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm });

  async function commitOpening(original, next) {
    const problem = openingProblem(state.payload.room, state.payload.openings, next);
    if (problem) {
      toast(`${problem} La porte revient à sa place.`, 'info');
      scene?.clearOpeningOverride();
      renderDetails();
      return;
    }
    const previous = openingFields(original);
    const values = openingFields(next);
    if (JSON.stringify(previous) === JSON.stringify(values)) { scene?.clearOpeningOverride(); return; }
    try {
      await writeOpening(original.id, values);
      history.push({
        label: `Modifier « ${openingName(original)} »`,
        undo: async () => { await writeOpening(original.id, previous); await reload(); },
        redo: async () => { await writeOpening(original.id, values); await reload(); },
      });
      await reload();
    } catch (error) {
      showError(error);
      scene?.clearOpeningOverride();
      renderDetails();
    }
  }

  function nudgeOpening(o, delta) {
    const length = wallLength(state.payload.room.outline, o.wall_index);
    commitOpening(o, { ...o, offset_mm: Math.max(0, Math.min(o.offset_mm + delta, Math.floor(length) - o.width_mm)) });
  }

  /** Ajoute une porte ou une fenêtre : sur le mur cliqué, sinon le premier mur où il reste de la place. */
  async function addOpening(kind, at = null) {
    const { room, openings } = state.payload;
    const base = kind === 'window'
      ? { kind, name: '', width_mm: 1200, height_mm: 1200, sill_mm: Math.min(900, room.height_mm - 1200) }
      : { kind, name: '', width_mm: 900, height_mm: Math.min(2050, room.height_mm), sill_mm: 0 };
    let wallIndex = at?.wallIndex ?? null;
    let offset = null;
    if (at) {
      const wall = nearestWall(room.outline, at.x, at.z, wallIndex);
      wallIndex = wall.index;
      offset = Math.max(0, Math.min(Math.round((wall.along - base.width_mm / 2) / 10) * 10, Math.floor(wall.length) - base.width_mm));
      if (openingProblem(room, openings, { ...base, id: -1, wall_index: wallIndex, offset_mm: offset })) offset = freeOffset(room, openings, wallIndex, { ...base, id: -1 });
    } else {
      for (let i = 1; i <= room.outline.length && offset === null; i++) {
        wallIndex = i % room.outline.length;
        offset = freeOffset(room, openings, wallIndex, { ...base, id: -1 });
      }
    }
    if (offset === null) return toast('Plus de place libre sur ce mur. Essayez un autre mur.', 'info');
    const body = { ...base, wall_index: wallIndex, offset_mm: offset };
    try {
      let created = await api(`/rooms/${roomId}/openings`, { method: 'POST', body });
      const newest = () => created.openings.reduce((a, b) => (a.id > b.id ? a : b));
      let id = newest().id;
      history.push({
        label: `Ajouter ${kind === 'window' ? 'une fenêtre' : 'une porte'}`,
        undo: async () => { await api(`/openings/${history.id(id)}`, { method: 'DELETE' }); await reload(); },
        redo: async () => {
          created = await api(`/rooms/${roomId}/openings`, { method: 'POST', body });
          const newId = newest().id;
          history.remember(id, newId);
          id = newId;
          await reload();
        },
      });
      apply(created);
      setGizmoMode('translate');
      selectOpening(id, { focus: true });
      toast(`${kind === 'window' ? 'Fenêtre ajoutée' : 'Porte ajoutée'}. Tirez le gizmo pour la placer.`);
    } catch (error) { showError(error); }
    return undefined;
  }

  async function deleteOpening(o) {
    if (o.id === 'ref') return;
    const body = openingFields(o);
    let id = o.id;
    try {
      const result = await api(`/openings/${history.id(id)}`, { method: 'DELETE' });
      history.pushWithToast({
        label: `Supprimer « ${openingName(o)} »`,
        undo: async () => {
          const restored = await api(`/rooms/${roomId}/openings`, { method: 'POST', body });
          const newId = restored.openings.reduce((a, b) => (a.id > b.id ? a : b)).id;
          history.remember(id, newId);
          id = newId;
          await reload();
        },
        redo: async () => { await api(`/openings/${history.id(id)}`, { method: 'DELETE' }); await reload(); },
      }, `« ${openingName(o)} » supprimée.`);
      state.selectedOpening = null;
      apply(result);
    } catch (error) { showError(error); }
  }

  function openingMenuItems(o) {
    const edit = can('rooms.edit');
    return [
      { label: 'Sélectionner', icon: 'focus', onSelect: () => selectOpening(o.id) },
      edit && { label: 'Déplacer avec le gizmo', icon: 'move', onSelect: () => { setGizmoMode('translate'); selectOpening(o.id); } },
      edit && o.id !== 'ref' && { separator: true },
      edit && o.id !== 'ref' && { label: 'Supprimer', icon: 'trash', danger: true, onSelect: () => deleteOpening(o) },
    ];
  }

  // ===== Copier, couper, coller, modèles =====

  const copyUnit = async (u, cut = false) => {
    try { await actions.copy(u, { cut }); renderDetails(); } catch (error) { showError(error); }
  };

  /** Où coller : dans le rangement sélectionné s'il peut contenir cet arbre, sinon dans la salle. */
  function pasteTarget(tree) {
    const size = { width_mm: tree.width_mm, depth_mm: tree.depth_mm, height_mm: tree.height_mm };
    const { room, units } = state.payload;
    const selected = unitById(state.selectedId);
    for (let parent = selected; parent; parent = unitById(parent.parent_id)) {
      if (!parent.can_contain_units || !unitInterior(parent) || !can('storage.edit')) continue;
      const spot = findFreeSpot(room, units, parent, size);
      if (spot) return { target: { parent_id: parent.id }, position: { pos_x_mm: spot.pos_x_mm, pos_y_mm: spot.pos_y_mm, pos_z_mm: spot.pos_z_mm }, parent };
    }
    if (!can('furniture.edit')) return null;
    const spot = findFreeSpot(room, units, null, size);
    return spot ? { target: { room_id: room.id }, position: { pos_x_mm: spot.pos_x_mm, pos_y_mm: spot.pos_y_mm, pos_z_mm: spot.pos_z_mm, rotation_deg: 0 }, parent: null } : null;
  }

  /** « Armoire A » existe déjà : « Armoire A 2 ». Deux rangements de même nom se confondraient dans les listes. */
  function freshName(name) {
    const taken = new Set(state.payload.units.map((u) => u.name));
    if (!taken.has(name)) return name;
    const base = name.replace(/\s\d+$/, '');
    for (let i = 2; ; i++) if (!taken.has(`${base} ${i}`)) return `${base} ${i}`;
  }

  async function placeTree(tree, label, run) {
    const place = pasteTarget(tree);
    if (!place) return toast(`Pas assez de place libre pour « ${tree.name} ». Libérez de la place ou agrandissez la salle.`, 'info');
    try {
      const unit = await run(place);
      if (place.parent) state.expanded.add(place.parent.id);
      await reload();
      select(unit.id, { focus: true });
      toast(`« ${unit.name} » ${label}. Déplacez-le avec le gizmo, ${MOD}+Z pour annuler.`);
    } catch (error) { showError(error); }
    return undefined;
  }

  async function pasteHere() {
    const value = clipboard.get();
    if (!value) return toast('Rien à coller : sélectionnez un rangement et faites d’abord ' + MOD + '+C.', 'info');
    return placeTree(value.tree, value.cut ? 'déplacé' : 'collé', ({ target, position }) => actions.paste(target, value.cut ? position : { ...position, name: freshName(value.tree.name) }));
  }

  async function duplicateUnit(u) {
    try {
      const { tree } = await api(`/storage-units/${u.id}/tree`);
      const clean = withoutIds(tree);
      return placeTree(clean, 'dupliqué', ({ target, position }) => actions.createTree({ ...clean, ...position, name: freshName(u.name) }, target, `Dupliquer « ${u.name} »`));
    } catch (error) { showError(error); return undefined; }
  }

  async function saveTemplate(u) {
    const template = await actions.saveAsTemplate(u);
    if (!template) return;
    await loadTemplates();
    state.leftTab = 'library';
    renderTree();
  }

  async function loadTemplates() {
    state.templates = (await api('/storage-templates')).templates;
  }

  const placeTemplate = (template) => placeTree(template.tree, 'posé', ({ target, position }) => actions.createTree({ ...withoutIds(template.tree), ...position, name: freshName(template.tree.name) }, target, `Poser « ${template.name} »`));

  async function renameTemplate(template) {
    const name = await openDialog('Renommer le modèle', (close) => {
      const input = h('input', { name: 'name', required: true, maxlength: 120, value: template.name });
      const form = h('form', { class: 'dialog-body', novalidate: true }, field('Nom', input),
        h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'), h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Renommer'))));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            await api(`/storage-templates/${template.id}`, { method: 'PATCH', body: { name: input.value } });
            close(input.value);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });
    if (name) { await loadTemplates(); renderTree(); }
  }

  async function deleteTemplate(template) {
    if (!await confirmDialog({ title: 'Supprimer le modèle', message: `Le modèle « ${template.name} » sera supprimé. Les rangements déjà posés restent en place.`, confirmLabel: 'Supprimer', danger: true })) return;
    try {
      await api(`/storage-templates/${template.id}`, { method: 'DELETE' });
      await loadTemplates();
      renderTree();
    } catch (error) { showError(error); }
  }

  /** Bibliothèque : modèles enregistrés et types de base, à poser d'un clic. */
  function libraryPanel() {
    const editable = can('furniture.edit') || can('storage.edit');
    const card = (template) => {
      const element = h('li', { class: 'library-card' },
        h('button', { class: 'library-main', type: 'button', disabled: !editable, title: editable ? 'Poser ce modèle dans la salle' : null, onclick: () => placeTemplate(template) },
          templateThumb(template.tree),
          h('span', { class: 'library-text' }, h('strong', {}, template.name), h('span', { class: 'sub' }, templateSummary(template, unitLabel())))));
      if (editable) contextMenu(element, () => templateMenuItems(template), { label: `Actions pour ${template.name}` });
      return element;
    };
    return h('div', { class: 'library' },
      h('h2', { class: 'panel-subtitle' }, 'Mes modèles', h('span', { class: 'count' }, String(state.templates.length))),
      state.templates.length
        ? h('ul', { class: 'plain-list library-list' }, state.templates.map(card))
        : h('p', { class: 'muted small' }, `Sélectionnez un meuble rempli de tiroirs puis ${MOD}+S : il apparaît ici, prêt à être reposé ailleurs, dans n'importe quelle salle.`),
      can('furniture.edit') && [
        h('h2', { class: 'panel-subtitle' }, 'Types de rangement'),
        h('ul', { class: 'plain-list library-types' }, state.types.filter((t) => t.shape !== 'divider').map((t) => h('li', {},
          h('button', { class: 'tree-label', type: 'button', onclick: () => editUnit(null, inTypeTarget(t), { typeId: t.id }) },
            h('span', { class: 'tree-swatch', dataset: { color: t.default_color } }), t.name)))),
      ]);
  }

  /** Un tiroir s'ajoute dans le meuble sélectionné s'il y a de la place ; un meuble dans la salle. */
  function inTypeTarget(type) {
    const selected = unitById(state.selectedId);
    if (['cabinet'].includes(type.shape) || !selected?.can_contain_units || !unitInterior(selected) || !can('storage.edit')) return null;
    return selected;
  }

  function templateMenuItems(template) {
    return [
      { label: 'Poser dans la salle', icon: 'plus', onSelect: () => placeTemplate(template) },
      { label: 'Renommer…', icon: 'tag', onSelect: () => renameTemplate(template) },
      { separator: true },
      { label: 'Supprimer…', icon: 'trash', danger: true, onSelect: () => deleteTemplate(template) },
    ];
  }

  function nudgePad(u) {
    const unit = unitLabel();
    const stepSelect = h('select', { class: 'select-small', 'aria-label': 'Pas de déplacement' },
      [[1, '1 mm'], [10, '1 cm'], [100, '10 cm'], [500, '50 cm']].map(([v, t]) => h('option', { value: v, selected: v === 10 }, t)));
    const b = (text, key, sign, title) => h('button', { class: 'btn btn-small', type: 'button', title, 'aria-label': title, onclick: (e) => busy(e.currentTarget, () => nudge(u, key, sign * Number(stepSelect.value))) }, text);
    return h('details', { class: 'nudge panel-more' },
      h('summary', {}, 'Ajuster la position au millimètre'),
      h('div', { class: 'nudge-row' }, h('label', { class: 'small muted', for: stepSelect.id = `step-${u.id}` }, 'Pas'), stepSelect,
        u.parent_id === null && [
          h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', title: 'Pivoter de 90° à gauche', 'aria-label': 'Pivoter de 90° à gauche', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg - 90), `Pivoter « ${u.name} »`) }, icon('rotateLeft')),
          h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', title: 'Pivoter de 90° à droite', 'aria-label': 'Pivoter de 90° à droite', onclick: () => patchUnit(u, rotateAroundCenter(u, u.rotation_deg + 90), `Pivoter « ${u.name} »`) }, icon('rotateRight'))]),
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
        can('stock.add') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => {
          if (await storeItemsDialog(location)) await reload();
        } }, icon('plus'), 'Ranger des objets'),
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
  function roomMenuItems(hit = null) {
    const here = hit?.point ? { x: hit.point.x * 1000, z: hit.point.z * 1000, wallIndex: hit.wallIndex } : null;
    return [
      can('furniture.edit') && { label: 'Ajouter un meuble…', icon: 'plus', onSelect: () => editUnit(null, null) },
      clipboard.get() && (can('furniture.edit') || can('storage.edit')) && { label: `Coller « ${clipboard.get().name} » (${MOD}+V)`, icon: 'paste', onSelect: pasteHere },
      can('rooms.edit') && { label: here ? 'Ajouter une porte ici' : 'Ajouter une porte', icon: 'door', onSelect: () => addOpening('door', here) },
      can('rooms.edit') && { label: here ? 'Ajouter une fenêtre ici' : 'Ajouter une fenêtre', icon: 'window', onSelect: () => addOpening('window', here) },
      { separator: true },
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
      u.shape === 'drawer' && { label: scene?.isDrawerOpen(u.id) ? 'Fermer le tiroir' : 'Ouvrir et regarder dedans', icon: 'drawer', onSelect: () => {
        if (scene?.isDrawerOpen(u.id)) scene.setDrawerOpen(u.id, false); else scene?.focus(u.id);
        setTimeout(renderDetails, 300);
      } },
      { separator: true },
      editRight && { label: furniture ? 'Modifier les dimensions…' : 'Modifier…', icon: 'edit', onSelect: () => editUnit(u, parent) },
      editRight && !furniture && { label: 'Renommer…', icon: 'tag', onSelect: () => rename(u) },
      furniture && can('furniture.edit') && { label: 'Déplacer dans la salle', icon: 'move', onSelect: () => {
        select(u.id);
        setGizmoMode('translate');
      } },
      canNest && furniture && { label: 'Ajouter un tiroir…', icon: 'drawer', onSelect: nest('drawer') },
      canNest && furniture && { label: 'Ajouter une étagère…', icon: 'layers', onSelect: nest('shelf') },
      canNest && !furniture && { label: 'Ajouter un sous-rangement…', icon: 'plus', onSelect: nest('box') },
      { separator: true },
      editRight && { label: `Copier (${MOD}+C)`, icon: 'copy', onSelect: () => copyUnit(u) },
      editRight && { label: `Couper (${MOD}+X)`, icon: 'cut', onSelect: () => copyUnit(u, true) },
      clipboard.get() && canNest && { label: `Coller dedans (${MOD}+V)`, icon: 'paste', onSelect: pasteHere },
      editRight && { label: `Dupliquer (${MOD}+D)`, icon: 'copy', onSelect: () => duplicateUnit(u) },
      editRight && { label: `Enregistrer comme modèle (${MOD}+S)`, icon: 'save', onSelect: () => saveTemplate(u) },
      { separator: true },
      u.can_hold_items && can('stock.add') && { label: 'Ranger des objets…', icon: 'plus', onSelect: async () => {
        if (await storeItemsDialog({ unit_id: u.id, path: u.path })) { await reload(); select(u.id); }
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
            await actions.patch(u, { name: name.value }, `Renommer « ${u.name} »`);
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
    const result = await openRoomDialog(state.payload.room, state.payload);
    if (result) apply(result);
  }

  async function openOpenings() {
    await openOpeningsDialog(state.payload, (payload) => apply(payload));
  }

  // Pas de confirmation : Annuler est proposé tout de suite, et le serveur refuse un rangement qui contient du stock
  async function remove(u) {
    try {
      state.selectedId = u.parent_id;
      await actions.remove(u);
    } catch (error) {
      state.selectedId = u.id;
      showError(error);
      renderDetails();
    }
  }

  /**
   * Panneau latéral à la place des propriétés : contrairement à une fenêtre, il laisse la vue 3D visible
   * et manipulable pendant la saisie. Résout avec la valeur passée à close().
   */
  function openPanel(title, build) {
    state.panel?.cancel();
    return new Promise((resolve) => {
      const panel = { onGizmo: null, cancel: () => finish(undefined) };
      const finish = (value) => {
        if (state.panel !== panel) return;
        state.panel = null;
        hud.hidden = true;
        scene?.clearDraft();
        renderDetails();
        resolve(value);
      };
      state.panel = panel;
      clear(details,
        h('header', { class: 'panel-header' },
          h('h2', { class: 'panel-title' }, title),
          h('button', { class: 'icon-btn icon-btn-small', type: 'button', 'aria-label': 'Annuler', title: 'Annuler (Échap)', onclick: () => finish(undefined) }, icon('x'))),
        build(finish, panel));
      details.scrollTop = 0;
      details.querySelector('input, select')?.focus({ preventScroll: true });
      // Sur mobile, le panneau est sous la vue : l'aperçu reste au-dessus
      details.addEventListener('keydown', (event) => { if (event.key === 'Escape' && state.panel === panel) finish(undefined); });
    });
  }

  const actionsRow = (close, submitLabel) => h('div', { class: 'panel-actions' },
    h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'),
    h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, submitLabel)));

  async function generate(u) {
    const types = state.types.filter((t) => t.shape !== 'divider');
    const flat = ['drawer', 'box', 'bin'].includes(u.shape);
    scene?.focus(u.id);
    const saved = await openPanel(`Remplir « ${u.name} »`, (close) => {
      const inside = unitInterior(u);
      let mode = flat ? 'grid' : 'stack';
      const rows = h('input', { name: 'rows', type: 'number', min: 1, max: 50, value: flat ? 2 : 4, required: true });
      const columns = h('input', { name: 'columns', type: 'number', min: 1, max: 50, value: flat ? 4 : 1, required: true });
      const gap = h('input', { name: 'gap_mm', type: 'number', min: 0, max: 500, value: 0 });
      const type = h('select', { name: 'type_id' }, types.map((t) => h('option', { value: t.id, selected: t.shape === (flat ? 'bin' : 'drawer') }, t.name)));
      const labels = h('textarea', { name: 'labels', rows: 4, placeholder: 'M3 x 12\nM3 x 16\nM4 x 6' });
      const stack = h('textarea', { name: 'stack', rows: 7, placeholder: 'Visserie ; 75\nRésistances 1 % ; 75\nCondensateurs ; 100\nCâbles ; 200' });
      const status = h('p', { class: 'panel-status', role: 'status' });
      const submit = actionsRow(close, 'Créer');
      const parseStack = () => stack.value.split('\n').map((l) => l.trim()).filter(Boolean).map((line) => {
        const m = line.match(/^(.*?)[;,\t]\s*(\d+)\s*(?:mm)?$/);
        return m ? { name: m[1].trim(), height_mm: Number(m[2]) } : { name: line, height_mm: 0 };
      });
      const labelList = () => labels.value.split('\n').map((l) => l.trim());
      const refresh = () => {
        const t = types.find((x) => x.id === Number(type.value));
        const g = Number(gap.value || 0);
        let drafts = [];
        let problem = null;
        let summary = '';
        if (mode === 'stack') {
          const entries = parseStack();
          const total = entries.reduce((n, x) => n + x.height_mm, 0) + Math.max(0, entries.length - 1) * g;
          summary = `${plural(entries.length, 'rangement')}, ${total} mm sur ${inside.height} mm disponibles.`;
          if (entries.length === 0) problem = 'Une ligne par rangement : nom ; hauteur en mm.';
          else if (entries.some((x) => x.height_mm < 10)) problem = 'Chaque ligne doit indiquer une hauteur, par exemple « Visserie ; 75 ».';
          else if (total > inside.height) problem = `Trop haut de ${total - inside.height} mm.`;
          else drafts = stackLayout(u, t, entries, g);
        } else {
          drafts = gridLayout(u, t, Number(rows.value) || 0, Number(columns.value) || 0, g, labelList());
          if (drafts.length === 0) problem = 'Trop de divisions pour la place disponible.';
          else summary = `${plural(drafts.length, 'rangement')} de ${formatLength(drafts[0].width_mm, unitLabel())} × ${formatLength(drafts[0].depth_mm, unitLabel())} × ${formatLength(drafts[0].height_mm, unitLabel())}.`;
        }
        scene?.setPreviewUnits(u, drafts);
        status.textContent = problem ?? summary;
        status.classList.toggle('is-invalid', Boolean(problem));
        submit.querySelector('[type=submit]').disabled = Boolean(problem);
      };
      const gridFields = h('div', {},
        h('p', { class: 'field-hint' }, flat ? "Posés au fond, rangées de l'arrière vers l'avant." : 'Répartis sur la hauteur, de haut en bas.'),
        h('div', { class: 'form-grid' }, field('Rangées', rows), field('Colonnes', columns)),
        field('Étiquettes (facultatif)', labels, 'Une par ligne, dans l’ordre de lecture.'));
      const stackFields = h('div', {}, field('De haut en bas', stack, 'Une ligne par rangement : nom ; hauteur en mm.'));
      const sync = () => { gridFields.hidden = mode !== 'grid'; stackFields.hidden = mode !== 'stack'; refresh(); };
      const modeSwitch = !flat && h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': 'Disposition' },
        [['stack', 'Liste de tiroirs'], ['grid', 'Grille régulière']].map(([m, text]) => h('button', {
          type: 'button', role: 'radio', class: 'segmented-option', 'aria-checked': String(m === mode),
          onclick: (event) => {
            mode = m;
            event.currentTarget.parentElement.querySelectorAll('[role=radio]').forEach((b) => b.setAttribute('aria-checked', String(b === event.currentTarget)));
            sync();
          },
        }, text)));
      const form = h('form', { class: 'panel-form', novalidate: true },
        modeSwitch,
        h('div', { class: 'form-grid' }, field('Type', type), field('Espacement (mm)', gap)),
        gridFields, stackFields, status, submit);
      form.addEventListener('input', refresh);
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const body = { type_id: Number(type.value), gap_mm: Number(gap.value || 0) };
        if (mode === 'stack') {
          body.stack = parseStack();
        } else {
          body.rows = Number(rows.value);
          body.columns = Number(columns.value);
          body.labels = labelList().slice(0, body.rows * body.columns);
          while (body.labels.length && !body.labels.at(-1)) body.labels.pop();
          body.labels = body.labels.map((l) => l || ' ');
        }
        busy(event.submitter, async () => {
          try {
            const r = await api(`/storage-units/${u.id}/generate`, { method: 'POST', body });
            let ids = r.ids;
            history.pushWithToast({
              label: `Remplir « ${u.name} »`,
              undo: async () => { for (const id of ids) await api(`/storage-units/${id}`, { method: 'DELETE' }); await reload(); },
              redo: async () => { ids = (await api(`/storage-units/${history.id(u.id)}/generate`, { method: 'POST', body })).ids; await reload(); },
            }, `${plural(r.created, 'rangement créé', 'rangements créés')}.`);
            close(true);
          } catch (error) { showError(error, form); }
        });
      });
      requestAnimationFrame(sync);
      return form;
    });
    if (saved) {
      state.expanded.add(u.id);
      await reload();
    }
  }

  /**
   * Création (u null) ou modification d'un meuble (parent null) ou d'un rangement, avec aperçu en direct :
   * le formulaire et le gizmo agissent sur le même brouillon, visible dans la vue 3D.
   */
  async function editUnit(u, parent, { shape = null, typeId = null } = {}) {
    const room = state.payload.room;
    const isFurniture = parent === null;
    const types = state.types;
    let unit = room.display_unit;
    if (u) select(u.id);
    const saved = await openPanel(u ? `Modifier « ${u.name} »` : isFurniture ? 'Nouveau meuble' : `Dans « ${parent.name} »`, (close, panel) => {
      const inside = parent ? unitInterior(parent) : null;
      const typeSelect = h('select', { name: 'type_id' }, types.map((t) => h('option', { value: t.id, selected: t.id === (u?.type_id ?? typeId ?? types.find((x) => x.shape === (shape ?? (isFurniture ? 'cabinet' : 'drawer')))?.id) }, `${t.name} (${SHAPE_LABELS[t.shape]})`)));
      const len = (name, mm) => h('input', { name, type: 'number', inputmode: 'decimal', step: UNITS[unit].step, value: toDisplay(mm, unit), required: true, dataset: { mm: mm } });
      const name = h('input', { name: 'name', required: true, maxlength: 120, value: u?.name ?? '' });
      const labelInput = h('input', { name: 'label', maxlength: 50, value: u?.label ?? '' });
      const reference = h('input', { name: 'reference', maxlength: 50, value: u?.reference ?? '' });
      const description = h('textarea', { name: 'description', rows: 2, maxlength: 1000 }, u?.description ?? '');
      const width = len('width_mm', u?.width_mm ?? 0); const depth = len('depth_mm', u?.depth_mm ?? 0); const height = len('height_mm', u?.height_mm ?? 0);
      const px = len('pos_x_mm', u?.pos_x_mm ?? 0); const py = len('pos_y_mm', u?.pos_y_mm ?? 0); const pz = len('pos_z_mm', u?.pos_z_mm ?? 0);
      const lengths = [width, depth, height, px, py, pz];
      const rotation = h('input', { name: 'rotation_deg', type: 'number', min: 0, max: 359, step: 1, value: u?.rotation_deg ?? 0 });
      const panelInput = h('input', { name: 'panel_mm', type: 'number', min: 0, max: 200, step: 1, value: u?.panel_mm ?? 0 });
      const capacity = h('input', { name: 'capacity', type: 'number', min: 0, step: 'any', value: u?.capacity ?? '', placeholder: 'Facultatif' });
      const color = h('input', { name: 'color', type: 'color', value: u?.color ?? '#b08d5c' });
      const status = h('div', { class: 'panel-status', role: 'status' });
      const submit = actionsRow(close, u ? 'Enregistrer' : isFurniture ? 'Ajouter le meuble' : 'Ajouter le rangement');
      const unitSelect = h('select', { 'aria-label': 'Unité des cotes', class: 'select-small' }, Object.keys(UNITS).map((k) => h('option', { value: k, selected: k === unit }, k)));
      const unitLabels = [];
      const unitText = () => { const el = h('span', { class: 'unit-text' }, unit); unitLabels.push(el); return el; };

      const draft = () => {
        const t = types.find((x) => x.id === Number(typeSelect.value));
        return {
          id: u?.id ?? -1, parent_id: parent?.id ?? null, type_id: t?.id, shape: t?.shape, name: name.value, label: labelInput.value,
          width_mm: fromDisplay(width.value, unit), depth_mm: fromDisplay(depth.value, unit), height_mm: fromDisplay(height.value, unit),
          pos_x_mm: fromDisplay(px.value, unit), pos_y_mm: fromDisplay(py.value, unit), pos_z_mm: fromDisplay(pz.value, unit),
          rotation_deg: isFurniture ? Number(rotation.value) || 0 : 0, panel_mm: Number(panelInput.value) || 0, color: color.value,
        };
      };

      /** Met à jour l'état affiché. rebuild : le brouillon de la scène doit être reconstruit. */
      const refresh = (rebuild = true) => {
        const d = draft();
        // Messages dans l'unité choisie dans ce formulaire
        const problem = unitProblem({ ...room, display_unit: unit }, state.payload.units, parent, d);
        const usable = [d.width_mm, d.depth_mm, d.height_mm].every((v) => Number.isFinite(v) && v >= 1 && v <= 50000) && [d.pos_x_mm, d.pos_y_mm, d.pos_z_mm].every(Number.isFinite);
        if (rebuild && usable) scene?.setDraft(d, parent, { valid: !problem });
        else scene?.setDraftValid(!problem);
        submit.querySelector('[type=submit]').disabled = Boolean(problem);
        const roomSpan = Math.max(...room.outline.flat(), room.height_mm);
        const tooBig = unit !== 'cm' && unit !== 'mm' && Math.max(d.width_mm, d.depth_mm, d.height_mm) > roomSpan;
        status.classList.toggle('is-invalid', Boolean(problem));
        clear(status,
          h('span', {}, problem ?? (u ? 'Emplacement valide.' : 'Emplacement libre : déplacez-le avec les flèches du gizmo.')),
          // Le cas du « 100 » tapé en pensant centimètres alors que la salle est réglée en mètres
          tooBig && h('button', { class: 'btn btn-small', type: 'button', onclick: () => {
            const positions = [px, py, pz].map((input) => fromDisplay(input.value, unit));
            unit = 'cm';
            unitSelect.value = 'cm';
            applyUnit();
            [px, py, pz].forEach((input, i) => { input.value = toDisplay(positions[i], unit); });
            refresh();
          } }, 'Ces cotes sont en cm'));
      };
      const applyUnit = () => {
        lengths.forEach((input) => { input.step = UNITS[unit].step; });
        unitLabels.forEach((el) => { el.textContent = unit; });
      };
      unitSelect.addEventListener('change', () => {
        const values = lengths.map((input) => fromDisplay(input.value, unit));
        unit = unitSelect.value;
        applyUnit();
        lengths.forEach((input, i) => { if (Number.isFinite(values[i])) input.value = toDisplay(values[i], unit); });
        refresh(false);
      });

      // Le gizmo met à jour le formulaire : les deux restent toujours d'accord
      panel.onGizmo = (c, final) => {
        const write = (input, mm) => { input.value = toDisplay(mm, unit); };
        write(width, c.width_mm); write(depth, c.depth_mm); write(height, c.height_mm);
        write(px, c.pos_x_mm); write(py, c.pos_y_mm); write(pz, c.pos_z_mm);
        rotation.value = c.rotation_deg;
        refresh(final);
      };

      const applyTypeDefaults = () => {
        const t = types.find((x) => x.id === Number(typeSelect.value));
        if (!t) return;
        let size = { width_mm: t.default_width_mm, depth_mm: t.default_depth_mm, height_mm: t.default_height_mm };
        if (inside) size = { width_mm: Math.min(size.width_mm, inside.width), depth_mm: Math.min(size.depth_mm, inside.depth), height_mm: Math.min(size.height_mm, inside.height) };
        width.value = toDisplay(size.width_mm, unit); depth.value = toDisplay(size.depth_mm, unit); height.value = toDisplay(size.height_mm, unit);
        panelInput.value = t.default_panel_mm;
        color.value = t.default_color;
        const count = state.payload.units.filter((x) => x.parent_id === (parent?.id ?? null) && x.type_id === t.id).length;
        if (!name.dataset.touched) name.value = `${t.name} ${isFurniture ? String.fromCharCode(65 + count) : count + 1}`;
        const spot = findFreeSpot(room, state.payload.units, parent, size);
        if (spot) { px.value = toDisplay(spot.pos_x_mm, unit); py.value = toDisplay(spot.pos_y_mm, unit); pz.value = toDisplay(spot.pos_z_mm, unit); }
      };
      name.addEventListener('input', () => { name.dataset.touched = '1'; });
      if (!u) typeSelect.addEventListener('change', applyTypeDefaults);

      const form = h('form', { class: 'panel-form', novalidate: true },
        field('Type', typeSelect), field('Nom', name),
        h('div', { class: 'panel-section-head' }, h('h3', { class: 'panel-subtitle' }, 'Dimensions extérieures'), unitSelect),
        h('div', { class: 'form-grid form-grid-3' },
          field(h('span', {}, 'Largeur (', unitText(), ')'), width), field(h('span', {}, 'Profond. (', unitText(), ')'), depth), field(h('span', {}, 'Hauteur (', unitText(), ')'), height)),
        inside && h('p', { class: 'field-hint' }, `Intérieur de « ${parent.name} » : ${formatLength(inside.width, unit)} × ${formatLength(inside.depth, unit)} × ${formatLength(inside.height, unit)}.`),
        h('h3', { class: 'panel-subtitle' }, 'Position'),
        h('div', { class: 'form-grid form-grid-3' },
          field(h('span', {}, 'X (', unitText(), ')'), px), field(h('span', {}, 'Y (', unitText(), ')'), py), field(h('span', {}, 'Z (', unitText(), ')'), pz)),
        isFurniture && field('Rotation (°)', rotation),
        h('p', { class: 'field-hint' }, 'Avec le gizmo dans la vue : W déplacer, E pivoter, R taille. Le rangement s’aimante aux bords voisins.'),
        h('details', { class: 'panel-more' }, h('summary', {}, 'Plus d’options'),
          h('div', { class: 'form-grid' }, field('Étiquette', labelInput), field('Référence', reference)),
          field('Description', description),
          h('div', { class: 'form-grid form-grid-3' }, field('Parois (mm)', panelInput), field('Couleur', color), field('Capacité', capacity))),
        status, submit);
      if (!u) applyTypeDefaults();
      form.addEventListener('input', () => refresh());
      requestAnimationFrame(() => refresh());

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const body = {
          type_id: Number(typeSelect.value), name: name.value, label: labelInput.value, reference: reference.value, description: description.value,
          width_mm: fromDisplay(width.value, unit), depth_mm: fromDisplay(depth.value, unit), height_mm: fromDisplay(height.value, unit),
          pos_x_mm: fromDisplay(px.value, unit), pos_y_mm: fromDisplay(py.value, unit), pos_z_mm: fromDisplay(pz.value, unit),
          panel_mm: Number(panelInput.value), color: color.value, capacity: capacity.value === '' ? null : Number(capacity.value),
        };
        if (isFurniture) body.rotation_deg = Number(rotation.value);
        if (!u) {
          if (parent) body.parent_id = parent.id; else body.room_id = room.id;
        }
        busy(event.submitter, async () => {
          try {
            let id;
            if (u) {
              await actions.patch(u, body, `Modifier « ${u.name} »`);
              id = u.id;
            } else {
              const result = await api('/storage-units', { method: 'POST', body });
              id = result.unit.id;
              const { tree: created } = await api(`/storage-units/${id}/tree`);
              const { parent_id: parentId, room_id: bodyRoom } = body;
              actions.recordCreated(id, withoutIds(created), parentId ? { parent_id: parentId } : { room_id: bodyRoom }, `Ajouter « ${name.value} »`);
            }
            toast(u ? 'Modifications enregistrées.' : `« ${name.value} » ajouté. ${MOD}+Z pour annuler.`);
            close(id);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });

    if (saved) {
      if (parent) state.expanded.add(parent.id);
      await reload();
      select(saved, { focus: !u });
    }
  }

  // ===== Chargement =====

  try {
    const [payload, types] = await Promise.all([api(`/rooms/${roomId}`), api('/storage-types'), loadTemplates()]);
    state.types = types.types;
    apply(payload);
    const highlight = Number(new URLSearchParams(location.search).get('unit'));
    if (highlight && unitById(highlight)) {
      select(highlight);
      scene?.highlight(highlight);
      renderDetails();
    }
    const query = new URLSearchParams(location.search);
    const action = query.get('action');
    if (action) {
      query.delete('action');
      history_replace(`${location.pathname}${query.size ? `?${query}` : ''}`);
      if (action === 'add-furniture' && can('furniture.edit')) editUnit(null, null);
      if (action === 'openings' && can('rooms.edit')) openOpenings();
    }
  } catch (error) {
    title.textContent = error.status === 404 ? 'Salle introuvable' : 'Salle';
    clear(details, errorState(error, () => location.reload()));
  }
}
