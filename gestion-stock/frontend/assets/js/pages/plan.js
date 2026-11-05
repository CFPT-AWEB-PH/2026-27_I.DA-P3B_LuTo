// Plan du bâtiment, façon logiciel d'aménagement : étages, salles, portes, fenêtres et meubles,
// placés avec le gizmo, en 2D ou en 3D. Double-clic sur une salle pour y entrer.

import { api } from '../api.js';
import { navigate, setTitle } from '../app.js';
import { contextMenu, openContextMenu } from '../context-menu.js';
import { append, clear, h } from '../dom.js';
import { clipboard, History, MOD, typingOrDialog } from '../history.js';
import { icon } from '../icons.js';
import {
  findFreeSpot, formatLength, freeOffset, fromDisplay, isRectangle, nearestWall, openingProblem, outlineSize, planBounds, referenceDoor, toDisplay, UNITS, unitProblem, wallLength,
} from '../spatial.js';
import { busy, confirmDialog, emptyState, errorState, field, openDialog, plural, showError, toast } from '../ui.js';
import { createUnitActions, shortcutsDialog, templateSummary, templateThumb, withoutIds } from '../unit-actions.js';

const OPENING_KINDS = { door: 'Porte', window: 'Fenêtre', opening: 'Passage' };
const FLOOR_KEY = 'plan-floor';
const DEFAULT_ROOM = { width: 5000, depth: 4000, height: 2800, wall: 150 };

let scene = null;
let removeShortcuts = null;

export function dispose() {
  removeShortcuts?.();
  removeShortcuts = null;
  scene?.dispose();
  scene = null;
}

export async function render(view, session) {
  const can = (p) => session.can(p);
  const editRooms = can('rooms.edit');
  const editFurniture = can('furniture.edit');
  const state = {
    plan: null, types: [], templates: [], floorId: null, selection: null, leftTab: 'floor', expanded: new Set(), is2d: true,
  };

  const history = new History({ onChange: () => refreshHistory() });
  const actions = createUnitActions({ history, can, reload: () => reload() });

  // ===== Structure de la page =====

  const floorTabs = h('div', { class: 'segmented floor-tabs', role: 'tablist', 'aria-label': 'Étages' });
  const left = h('nav', { class: 'editor-tree', 'aria-label': 'Contenu de l’étage' });
  const details = h('aside', { class: 'editor-details', 'aria-live': 'polite' });
  const stage = h('div', { class: 'editor-stage' });
  const hud = h('div', { class: 'gizmo-hud', hidden: true, role: 'status' });
  const hint = h('p', { class: 'editor-hint' }, `Clic : sélectionner  ·  Double-clic : entrer dans la salle  ·  Clic droit : menu  ·  Molette : zoomer  ·  ${MOD}+Z : annuler  ·  ? : raccourcis`);
  stage.addEventListener('pointerdown', () => hint.classList.add('is-faded'), { once: true });

  const undoButton = h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', disabled: true, onclick: () => history.undo() }, icon('undo'));
  const redoButton = h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', disabled: true, onclick: () => history.redo() }, icon('redo'));
  function refreshHistory() {
    for (const [button, word, label, keys, active] of [
      [undoButton, 'Annuler', history.undoLabel, `${MOD}+Z`, history.canUndo],
      [redoButton, 'Rétablir', history.redoLabel, `${MOD}+Y`, history.canRedo],
    ]) {
      button.disabled = !active;
      const tip = label ? `${word} : ${label} (${keys})` : `${word} (${keys})`;
      button.title = tip;
      button.setAttribute('aria-label', tip);
    }
  }
  refreshHistory();

  const label = (text) => h('span', { class: 'btn-label' }, text);
  const GIZMO_MODES = [['none', 'Sélection', 'eye', 'Sélection seule, sans poignées (Q)'], ['translate', 'Déplacer', 'move', 'Déplacer (W)'], ['rotate', 'Pivoter', 'rotateRight', 'Pivoter (E)'], ['scale', 'Taille', 'ruler', 'Dimensions d’une salle (R)']];
  const gizmoButtons = GIZMO_MODES.map(([mode, text, iconName, tip]) => h('button', {
    class: 'segmented-option', type: 'button', role: 'radio', title: tip, 'aria-checked': String(mode === 'translate'), onclick: () => setGizmoMode(mode),
  }, icon(iconName), label(text)));
  function setGizmoMode(mode) {
    GIZMO_MODES.forEach(([m], i) => gizmoButtons[i].setAttribute('aria-checked', String(m === mode)));
    scene?.setGizmoMode(mode);
  }
  const viewButtons = [[true, '2D'], [false, '3D']].map(([flat, text]) => h('button', {
    class: 'segmented-option', type: 'button', role: 'radio', 'aria-checked': String(flat === state.is2d),
    onclick: (event) => {
      state.is2d = flat;
      event.currentTarget.parentElement.querySelectorAll('[role=radio]').forEach((b) => b.setAttribute('aria-checked', String(b === event.currentTarget)));
      scene?.setView(flat);
    },
  }, text));
  const wallSelect = h('select', { class: 'select-small', 'aria-label': 'Affichage des murs' },
    [['auto', 'Murs : auto'], ['transparent', 'Murs : transparents'], ['hidden', 'Murs : masqués'], ['solid', 'Murs : pleins']].map(([v, t]) => h('option', { value: v }, t)));
  wallSelect.addEventListener('change', () => { scene?.setWallMode(wallSelect.value); });

  view.classList.add('view-wide');
  append(view, h('div', { class: 'editor plan-editor' },
    h('div', { class: 'editor-toolbar' },
      h('div', { class: 'editor-heading' }, h('h1', { class: 'editor-title' }, 'Plan')),
      h('div', { class: 'editor-tools' },
        (editRooms || editFurniture) && h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Historique' }, undoButton, redoButton),
        h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Étage' }, floorTabs,
          editRooms && h('button', { class: 'icon-btn icon-btn-small icon-btn-bordered', type: 'button', title: 'Ajouter un étage', 'aria-label': 'Ajouter un étage', onclick: () => addFloor(1) }, icon('plus'))),
        h('span', { class: 'toolbar-divider', 'aria-hidden': 'true' }),
        h('div', { class: 'toolbar-group', role: 'group', 'aria-label': 'Affichage' },
          h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': 'Vue' }, viewButtons),
          (editRooms || editFurniture) && h('div', { class: 'segmented gizmo-switch', role: 'radiogroup', 'aria-label': 'Mode du gizmo' }, gizmoButtons),
          wallSelect),
        h('div', { class: 'toolbar-group toolbar-end' },
          h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Raccourcis clavier (?)', 'aria-label': 'Raccourcis clavier', onclick: () => shortcutsDialog() }, icon('help')),
          editRooms && h('button', { class: 'btn btn-small', type: 'button', title: 'Ajouter une porte à la salle sélectionnée', onclick: () => addOpening('door') }, icon('door'), label('Porte')),
          editRooms && h('button', { class: 'btn btn-small', type: 'button', title: 'Ajouter une fenêtre à la salle sélectionnée', onclick: () => addOpening('window') }, icon('window'), label('Fenêtre')),
          editFurniture && h('button', { class: 'btn btn-small', type: 'button', title: 'Choisir un rangement à poser', onclick: () => { state.leftTab = 'library'; renderLeft(); } }, icon('library'), label('Rangement')),
          editRooms && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => addRoom() }, icon('plus'), 'Salle')))),
    left, h('div', { class: 'editor-canvas' }, stage, hint, hud), details));

  // ===== Données =====

  const room = (id) => state.plan?.rooms.find((r) => r.id === id) ?? null;
  const unit = (id) => state.plan?.units.find((u) => u.id === id) ?? null;
  const openingsOf = (id) => state.plan.openings.filter((o) => o.room_id === id);
  const unitsOf = (id) => state.plan.units.filter((u) => u.room_id === id);
  const opening = (r, id) => (id === 'ref' ? referenceDoor(r) : openingsOf(r.id).find((o) => o.id === id) ?? null);
  const floorOf = (r) => state.plan.floors.find((f) => f.id === r.floor_id) ?? null;
  const roomsOnFloor = () => state.plan.rooms.filter((r) => state.floorId === 'all' || r.floor_id === state.floorId);
  const openingName = (o) => o.name || OPENING_KINDS[o.kind];

  /** Salles sans étage ni position : posées à la suite des autres, sans rien enregistrer tant qu'on n'y touche pas. */
  function placeNewRooms(plan) {
    const firstFloor = [...plan.floors].sort((a, b) => Math.abs(a.level) - Math.abs(b.level))[0];
    for (const r of plan.rooms) r.floor_id = r.floor_id ?? firstFloor.id;
    for (const floor of plan.floors) {
      const rooms = plan.rooms.filter((r) => r.floor_id === floor.id);
      const placed = rooms.filter((r) => r.plan_x_mm !== null && r.plan_z_mm !== null);
      let x = placed.length ? Math.max(...placed.map((r) => planBounds(r).maxX)) + 1000 : 0;
      const z = placed.length ? Math.min(...placed.map((r) => planBounds(r).minZ)) : 0;
      for (const r of rooms.filter((c) => c.plan_x_mm === null || c.plan_z_mm === null)) {
        r.plan_x_mm = x;
        r.plan_z_mm = z;
        r.auto_placed = true;
        x = planBounds(r).maxX + 1000;
      }
    }
    return plan;
  }

  async function reload({ refreshScene = true } = {}) {
    state.plan = placeNewRooms(await api('/plan'));
    if (state.floorId !== 'all' && !state.plan.floors.some((f) => f.id === state.floorId)) state.floorId = pickInitialFloor();
    const sel = state.selection;
    if (sel && !selectionExists(sel)) state.selection = null;
    if (refreshScene) {
      scene?.setPlan(state.plan, state.floorId);
      scene?.setSelection(state.selection);
    }
    renderFloors();
    renderLeft();
    renderDetails();
  }

  function pickInitialFloor() {
    let saved = null;
    try { saved = localStorage.getItem(FLOOR_KEY); } catch { /* stockage indisponible */ }
    if (saved === 'all') return 'all';
    const floors = state.plan.floors;
    return floors.find((f) => String(f.id) === saved)?.id ?? floors.find((f) => f.level === 0)?.id ?? floors[0]?.id ?? null;
  }

  function selectionExists(sel) {
    const r = room(sel.roomId);
    if (!r) return false;
    if (sel.kind === 'unit') return Boolean(unit(sel.id));
    if (sel.kind === 'opening') return Boolean(opening(r, sel.id));
    return true;
  }

  function select(selection) {
    state.selection = selection;
    scene?.setSelection(selection);
    renderLeft();
    renderDetails();
  }

  function setFloor(id) {
    state.floorId = id;
    try { localStorage.setItem(FLOOR_KEY, String(id)); } catch { /* stockage indisponible */ }
    if (state.selection && id !== 'all' && room(state.selection.roomId)?.floor_id !== id) state.selection = null;
    scene?.setPlan(state.plan, id);
    scene?.setSelection(state.selection);
    renderFloors();
    renderLeft();
    renderDetails();
  }

  /** Salle visée par une action : celle de la sélection, ou la seule de l'étage. */
  function currentRoom() {
    if (state.selection) return room(state.selection.roomId);
    const rooms = roomsOnFloor();
    return rooms.length === 1 ? rooms[0] : null;
  }

  // ===== Scène =====

  try {
    const { PlanScene } = await import('../three/plan-scene.js');
    scene = new PlanScene(stage, {
      canEdit: (sel) => (sel.kind === 'unit' ? editFurniture : editRooms),
      onSelect: () => {},
      onPick: (hit) => {
        if (hit.unit) return select({ kind: 'unit', id: hit.unit.id, roomId: hit.unit.room_id });
        if (hit.opening) return select({ kind: 'opening', id: hit.opening.id, roomId: hit.room.id });
        if (hit.room) return select({ kind: 'room', id: hit.room.id, roomId: hit.room.id });
        return select(null);
      },
      onOpen: (hit) => {
        if (hit.unit) return navigate(`/rooms/${hit.unit.room_id}?unit=${hit.unit.id}`);
        if (hit.room) return navigate(`/rooms/${hit.room.id}`);
        return undefined;
      },
      onPlanMenu: (hit, point) => {
        if (hit.unit) select({ kind: 'unit', id: hit.unit.id, roomId: hit.unit.room_id });
        else if (hit.opening) select({ kind: 'opening', id: hit.opening.id, roomId: hit.room.id });
        else if (hit.room) select({ kind: 'room', id: hit.room.id, roomId: hit.room.id });
        const items = hit.unit ? unitMenu(hit.unit) : hit.opening ? openingMenu(hit.room, hit.opening) : hit.room ? roomMenu(hit.room, hit) : floorMenu();
        openContextMenu(items, { x: point.clientX, y: point.clientY, label: 'Actions' });
      },
      onRoomChange: (preview, overlap) => {
        const size = outlineSize(preview.outline);
        showHud(`${preview.name}   ·   ${formatLength(size.width, preview.display_unit)} × ${formatLength(size.depth, preview.display_unit)}   ·   ${preview.plan_rotation_deg}°`, overlap ? `Chevauche « ${overlap.name} »` : null);
      },
      onUnitChange: (c, target, problem) => showHud(`${c.name} dans ${target.name}   ·   X ${formatLength(c.pos_x_mm, target.display_unit)}  Z ${formatLength(c.pos_z_mm, target.display_unit)}   ·   ${c.rotation_deg}°`, problem),
      onOpeningChange: (o, problem, r) => showHud(`Mur ${o.wall_index + 1}   ·   à ${formatLength(o.offset_mm, r.display_unit)}   ·   ${formatLength(o.width_mm, r.display_unit)} × ${formatLength(o.height_mm, r.display_unit)}`, problem),
      onGizmoEnd: (context, candidate) => {
        hud.hidden = true;
        if (context.kind === 'room') return commitRoomGeometry(context.room, candidate);
        if (context.kind === 'unit') return commitUnit(context.unit, candidate.unit, candidate.target);
        return commitOpening(context.room, context.opening, candidate);
      },
    });
    scene.setWallMode('auto');
  } catch (error) {
    stage.append(h('div', { class: 'alert alert-error' }, "La vue 3D n'a pas pu démarrer (WebGL indisponible sur cet appareil). La liste des salles reste utilisable."));
    console.error(error);
  }

  function showHud(text, problem) {
    hud.hidden = false;
    hud.classList.toggle('is-invalid', Boolean(problem));
    clear(hud, h('span', {}, text), problem && h('strong', {}, problem));
  }

  // ===== Salles =====

  function patchRoomEntry(r, changes, text) {
    const previous = Object.fromEntries(Object.keys(changes).map((key) => [key, r[key]]));
    const id = r.id;
    return {
      label: text,
      undo: async () => { await api(`/rooms/${history.id(id)}`, { method: 'PATCH', body: previous }); await reload(); },
      redo: async () => { await api(`/rooms/${history.id(id)}`, { method: 'PATCH', body: changes }); await reload(); },
    };
  }

  async function patchRoom(r, changes, text) {
    try {
      await api(`/rooms/${history.id(r.id)}`, { method: 'PATCH', body: changes });
      history.push(patchRoomEntry(r, changes, text));
      await reload();
    } catch (error) {
      showError(error);
      await reload();
    }
  }

  function commitRoomGeometry(r, c) {
    const changes = {};
    if (c.plan_x_mm !== r.plan_x_mm || r.auto_placed) changes.plan_x_mm = c.plan_x_mm;
    if (c.plan_z_mm !== r.plan_z_mm || r.auto_placed) changes.plan_z_mm = c.plan_z_mm;
    if (c.plan_rotation_deg !== r.plan_rotation_deg) changes.plan_rotation_deg = c.plan_rotation_deg;
    if (JSON.stringify(c.outline) !== JSON.stringify(r.outline)) changes.outline = c.outline;
    if (Object.keys(changes).length === 0) return reload();
    const overlap = scene?.roomOverlap({ ...r, ...c });
    if (overlap) toast(`« ${r.name} » chevauche « ${overlap.name} ». Vérifiez le plan.`, 'info', { action: { label: 'Annuler', run: () => history.undo() } });
    return patchRoom(r, changes, `Modifier « ${r.name} »`);
  }

  function uniqueRoomName(base) {
    const names = new Set(state.plan.rooms.map((r) => r.name.toLowerCase()));
    for (let i = state.plan.rooms.length + 1; ; i++) {
      const name = `${base} ${i}`;
      if (!names.has(name.toLowerCase())) return name;
    }
  }

  /** Une salle apparaît collée à la dernière, déjà sélectionnée : on la déplace et on la renomme ensuite. */
  async function addRoom() {
    if (state.floorId === 'all') setFloor(state.plan.floors.find((f) => f.level === 0)?.id ?? state.plan.floors[0].id);
    const { width, depth, height, wall } = DEFAULT_ROOM;
    const rooms = roomsOnFloor();
    const bounds = rooms.map(planBounds);
    const body = {
      name: uniqueRoomName('Salle'), display_unit: 'cm', outline: [[0, 0], [width, 0], [width, depth], [0, depth]], height_mm: Math.min(height, floorHeight()),
      wall_thickness_mm: wall, door_offset_mm: 500, door_width_mm: 900, door_height_mm: 2050, floor_id: state.floorId,
      plan_x_mm: rooms.length ? Math.max(...bounds.map((b) => b.maxX)) + wall : 0,
      plan_z_mm: rooms.length ? Math.min(...bounds.map((b) => b.minZ)) : 0,
    };
    try {
      const created = await api('/rooms', { method: 'POST', body });
      let id = created.room.id;
      history.pushWithToast({
        label: `Ajouter « ${body.name} »`,
        undo: async () => { await api(`/rooms/${history.id(id)}`, { method: 'DELETE' }); await reload(); },
        redo: async () => {
          const again = await api('/rooms', { method: 'POST', body });
          history.remember(id, again.room.id);
          id = again.room.id;
          await reload();
        },
      }, `« ${body.name} » ajoutée.`);
      state.selection = { kind: 'room', id, roomId: id };
      await reload();
      scene?.frame();
      details.querySelector('input')?.focus({ preventScroll: true });
      details.querySelector('input')?.select?.();
    } catch (error) { showError(error); }
  }

  const floorHeight = () => state.plan.floors.find((f) => f.id === state.floorId)?.height_mm ?? 3000;

  async function duplicateRoom(r) {
    try {
      const copy = await api(`/rooms/${r.id}/duplicate`, { method: 'POST' });
      const b = planBounds(r);
      const size = outlineSize(r.outline);
      const placed = await api(`/rooms/${copy.room.id}`, { method: 'PATCH', body: { plan_x_mm: Math.round(b.maxX + r.wall_thickness_mm), plan_z_mm: Math.round(b.minZ + (r.plan_rotation_deg ? 0 : size.minZ)) } });
      const id = placed.room.id;
      history.pushWithToast({
        label: `Dupliquer « ${r.name} »`,
        undo: async () => { await api(`/rooms/${id}`, { method: 'DELETE' }); await reload(); },
        redo: async () => { throw new Error('Refaites la copie : la salle a été supprimée.'); },
      }, `Salle dupliquée : « ${placed.room.name} ».`);
      state.selection = { kind: 'room', id, roomId: id };
      await reload();
    } catch (error) { showError(error); }
  }

  async function deleteRoom(r) {
    const count = unitsOf(r.id).length;
    const ok = await confirmDialog({
      title: 'Supprimer la salle',
      message: `« ${r.name} »${count ? ` et ses ${plural(count, 'meuble')} seront supprimés` : ' sera supprimée'} définitivement. Cette action ne peut pas être annulée.`,
      confirmLabel: 'Supprimer la salle', danger: true,
    });
    if (!ok) return;
    try {
      await api(`/rooms/${r.id}`, { method: 'DELETE' });
      toast('Salle supprimée.');
      state.selection = null;
      await reload();
    } catch (error) { showError(error); }
  }

  // ===== Étages =====

  function renderFloors() {
    const floors = [...state.plan.floors].sort((a, b) => b.level - a.level);
    clear(floorTabs,
      floors.map((floor) => {
        const button = h('button', {
          class: 'segmented-option', type: 'button', role: 'tab', 'aria-selected': String(state.floorId === floor.id), 'aria-checked': String(state.floorId === floor.id),
          title: floor.name, onclick: () => setFloor(floor.id),
        }, floor.name);
        if (editRooms) contextMenu(button, () => floorItems(floor), { label: `Actions pour ${floor.name}` });
        return button;
      }),
      floors.length > 1 && h('button', { class: 'segmented-option', type: 'button', role: 'tab', 'aria-selected': String(state.floorId === 'all'), 'aria-checked': String(state.floorId === 'all'), title: 'Voir tous les étages empilés', onclick: () => setFloor('all') }, 'Tous'));
  }

  function nextFloorName(direction) {
    const names = new Set(state.plan.floors.map((f) => f.name.toLowerCase()));
    const levels = state.plan.floors.map((f) => f.level);
    const level = direction > 0 ? Math.max(...levels) + 1 : Math.min(...levels) - 1;
    let name = level === 0 ? 'Rez-de-chaussée' : level > 0 ? `Étage ${level}` : `Sous-sol ${-level}`;
    for (let i = 2; names.has(name.toLowerCase()); i++) name = `${level > 0 ? 'Étage' : 'Sous-sol'} ${Math.abs(level)} (${i})`;
    return { name, level };
  }

  async function addFloor(direction) {
    try {
      const { floor } = await api('/floors', { method: 'POST', body: nextFloorName(direction) });
      toast(`« ${floor.name} » ajouté.`);
      state.floorId = floor.id;
      state.selection = null;
      // L'étage est vide : le panneau de gauche propose d'y ajouter une salle
      state.leftTab = 'floor';
      await reload();
    } catch (error) { showError(error); }
  }

  async function renameFloor(floor) {
    const name = await openDialog('Renommer l’étage', (close) => {
      const input = h('input', { name: 'name', required: true, maxlength: 80, value: floor.name });
      const height = h('input', { name: 'height_mm', type: 'number', min: 100, max: 2000, step: 1, value: floor.height_mm / 10 });
      const form = h('form', { class: 'dialog-body', novalidate: true }, field('Nom', input), field('Hauteur d’étage (cm)', height, 'Sert à empiler les étages dans la vue « Tous ».'),
        h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'), h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Enregistrer'))));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            await api(`/floors/${floor.id}`, { method: 'PATCH', body: { name: input.value, height_mm: Math.round(Number(height.value) * 10) } });
            close(input.value);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });
    if (name) await reload();
  }

  async function deleteFloor(floor) {
    if (!await confirmDialog({ title: 'Supprimer l’étage', message: `Supprimer « ${floor.name} » ? Il doit être vide.`, confirmLabel: 'Supprimer', danger: true })) return;
    try {
      await api(`/floors/${floor.id}`, { method: 'DELETE' });
      if (state.floorId === floor.id) state.floorId = null;
      await reload();
    } catch (error) { showError(error); }
  }

  const floorItems = (floor) => [
    { label: 'Renommer…', icon: 'edit', onSelect: () => renameFloor(floor) },
    { label: 'Ajouter un étage au-dessus', icon: 'stairs', onSelect: () => addFloor(1) },
    { label: 'Ajouter un sous-sol', icon: 'stairs', onSelect: () => addFloor(-1) },
    { separator: true },
    { label: 'Supprimer…', icon: 'trash', danger: true, onSelect: () => deleteFloor(floor) },
  ];

  // ===== Ouvertures =====

  const openingFields = ({ kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm }) => ({ kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm });

  function writeOpening(r, id, values) {
    if (id === 'ref') {
      const { offset_mm: door_offset_mm, width_mm: door_width_mm, height_mm: door_height_mm } = values;
      return api(`/rooms/${history.id(r.id)}`, { method: 'PATCH', body: { door_offset_mm, door_width_mm, door_height_mm } });
    }
    return api(`/openings/${history.id(id)}`, { method: 'PATCH', body: values });
  }

  async function commitOpening(r, original, next) {
    const problem = openingProblem(r, openingsOf(r.id), next);
    if (problem) {
      toast(`${problem} Elle revient à sa place.`, 'info');
      return reload();
    }
    const previous = openingFields(original);
    const values = openingFields(next);
    if (JSON.stringify(previous) === JSON.stringify(values)) return reload();
    try {
      await writeOpening(r, original.id, values);
      history.push({
        label: `Déplacer « ${openingName(original)} »`,
        undo: async () => { await writeOpening(r, original.id, previous); await reload(); },
        redo: async () => { await writeOpening(r, original.id, values); await reload(); },
      });
    } catch (error) { showError(error); }
    return reload();
  }

  async function addOpening(kind, at = null) {
    const r = at?.room ?? currentRoom();
    if (!r) return toast('Cliquez d’abord sur une salle, dans le plan ou dans la liste.', 'info');
    const existing = openingsOf(r.id);
    const base = kind === 'window'
      ? { kind, name: '', width_mm: 1200, height_mm: Math.min(1200, r.height_mm - 300), sill_mm: Math.min(900, r.height_mm - 1500) }
      : { kind, name: '', width_mm: 900, height_mm: Math.min(2050, r.height_mm), sill_mm: 0 };
    let wallIndex = null; let offset = null;
    if (at?.point) {
      const local = scene.localPoint(r, at.point);
      const wall = nearestWall(r.outline, local.x, local.z, at.wallIndex);
      wallIndex = wall.index;
      offset = Math.max(0, Math.min(Math.round((wall.along - base.width_mm / 2) / 10) * 10, Math.floor(wall.length) - base.width_mm));
      if (openingProblem(r, existing, { ...base, id: -1, wall_index: wallIndex, offset_mm: offset })) offset = freeOffset(r, existing, wallIndex, { ...base, id: -1 });
    } else {
      for (let i = 1; i <= r.outline.length && offset === null; i++) {
        wallIndex = i % r.outline.length;
        offset = freeOffset(r, existing, wallIndex, { ...base, id: -1 });
      }
    }
    if (offset === null) return toast('Plus de place libre sur ce mur. Essayez un autre mur.', 'info');
    const body = { ...base, wall_index: wallIndex, offset_mm: offset };
    const newestId = (payload) => payload.openings.reduce((a, b) => (a.id > b.id ? a : b)).id;
    try {
      const created = await api(`/rooms/${r.id}/openings`, { method: 'POST', body });
      let id = newestId(created);
      history.push({
        label: `Ajouter ${kind === 'window' ? 'une fenêtre' : 'une porte'}`,
        undo: async () => { await api(`/openings/${history.id(id)}`, { method: 'DELETE' }); await reload(); },
        redo: async () => {
          const again = newestId(await api(`/rooms/${history.id(r.id)}/openings`, { method: 'POST', body }));
          history.remember(id, again);
          id = again;
          await reload();
        },
      });
      setGizmoMode('translate');
      state.selection = { kind: 'opening', id, roomId: r.id };
      await reload();
      toast(`${kind === 'window' ? 'Fenêtre ajoutée' : 'Porte ajoutée'}. Tirez le gizmo : elle suit le mur le plus proche.`);
    } catch (error) { showError(error); }
    return undefined;
  }

  async function deleteOpening(r, o) {
    if (o.id === 'ref') return;
    const body = openingFields(o);
    let id = o.id;
    try {
      await api(`/openings/${history.id(id)}`, { method: 'DELETE' });
      history.pushWithToast({
        label: `Supprimer « ${openingName(o)} »`,
        undo: async () => {
          const restored = await api(`/rooms/${history.id(r.id)}/openings`, { method: 'POST', body });
          const newId = restored.openings.reduce((a, b) => (a.id > b.id ? a : b)).id;
          history.remember(id, newId);
          id = newId;
          await reload();
        },
        redo: async () => { await api(`/openings/${history.id(id)}`, { method: 'DELETE' }); await reload(); },
      }, `« ${openingName(o)} » supprimée.`);
      state.selection = { kind: 'room', id: r.id, roomId: r.id };
      await reload();
    } catch (error) { showError(error); }
  }

  // ===== Meubles =====

  /** Un meuble lâché dans une autre salle y part avec tout son contenu, stock compris. */
  async function commitUnit(u, c, target) {
    const problem = unitProblem(target, unitsOf(target.id).map((x) => ({ ...x, parent_id: null })), null, c);
    if (problem) {
      toast(`${problem} « ${u.name} » revient à sa place.`, 'info');
      return reload();
    }
    const fields = { pos_x_mm: c.pos_x_mm, pos_z_mm: c.pos_z_mm, rotation_deg: c.rotation_deg };
    const moved = target.id !== u.room_id;
    const previous = { room_id: u.room_id, pos_x_mm: u.pos_x_mm, pos_z_mm: u.pos_z_mm, rotation_deg: u.rotation_deg };
    const id = u.id;
    const write = (body) => api(`/storage-units/${history.id(id)}/move`, { method: 'POST', body: { parent_id: null, ...body } });
    try {
      await write({ room_id: target.id, ...fields });
      history.push({
        label: moved ? `Déplacer « ${u.name} » vers « ${target.name} »` : `Déplacer « ${u.name} »`,
        undo: async () => { await write(previous); await reload(); },
        redo: async () => { await write({ room_id: target.id, ...fields }); await reload(); },
      });
      if (moved) {
        toast(`« ${u.name} » est maintenant dans « ${target.name} », avec son contenu.`);
        state.selection = { kind: 'unit', id: u.id, roomId: target.id };
      }
    } catch (error) { showError(error); }
    return reload();
  }

  async function placeInRoom(tree, text, run) {
    const r = currentRoom();
    if (!r) return toast('Cliquez d’abord sur la salle où poser ce rangement.', 'info');
    const size = { width_mm: tree.width_mm, depth_mm: tree.depth_mm, height_mm: tree.height_mm };
    const spot = findFreeSpot(r, unitsOf(r.id), null, size);
    if (!spot) return toast(`Pas assez de place libre dans « ${r.name} » pour « ${tree.name} ».`, 'info');
    try {
      const created = await run(r, { pos_x_mm: spot.pos_x_mm, pos_y_mm: 0, pos_z_mm: spot.pos_z_mm, rotation_deg: 0 });
      state.selection = { kind: 'unit', id: created.id, roomId: r.id };
      setGizmoMode('translate');
      await reload();
      toast(`« ${created.name} » ${text} dans « ${r.name} ». Déplacez-le avec le gizmo.`);
    } catch (error) { showError(error); }
    return undefined;
  }

  const placeTemplate = (template) => placeInRoom(template.tree, 'posé', (r, position) => actions.createTree({ ...withoutIds(template.tree), ...position }, { room_id: r.id }, `Poser « ${template.name} »`));

  function typeTree(type) {
    const count = state.plan.units.filter((u) => u.type_id === type.id).length;
    return {
      type_id: type.id, name: `${type.name} ${String.fromCharCode(65 + (count % 26))}`, width_mm: type.default_width_mm, depth_mm: type.default_depth_mm, height_mm: type.default_height_mm,
      panel_mm: type.default_panel_mm, color: type.default_color, children: [],
    };
  }
  const placeType = (type) => {
    const tree = typeTree(type);
    return placeInRoom(tree, 'posé', (r, position) => actions.createTree({ ...tree, ...position }, { room_id: r.id }, `Poser « ${tree.name} »`));
  };

  async function pasteHere() {
    const value = clipboard.get();
    if (!value) return toast(`Rien à coller : sélectionnez un meuble et faites d’abord ${MOD}+C.`, 'info');
    return placeInRoom(value.tree, value.cut ? 'déplacé' : 'collé', (r, position) => actions.paste({ room_id: r.id }, position));
  }

  async function duplicateUnit(u) {
    try {
      const { tree } = await api(`/storage-units/${u.id}/tree`);
      const clean = withoutIds(tree);
      state.selection = { kind: 'room', id: u.room_id, roomId: u.room_id };
      return placeInRoom(clean, 'dupliqué', (r, position) => actions.createTree({ ...clean, ...position }, { room_id: r.id }, `Dupliquer « ${u.name} »`));
    } catch (error) { showError(error); return undefined; }
  }

  async function removeUnit(u) {
    try {
      state.selection = { kind: 'room', id: u.room_id, roomId: u.room_id };
      await actions.remove(u);
    } catch (error) {
      state.selection = { kind: 'unit', id: u.id, roomId: u.room_id };
      showError(error);
      renderDetails();
    }
  }

  async function saveTemplate(u) {
    const template = await actions.saveAsTemplate(u);
    if (!template) return;
    state.templates = (await api('/storage-templates')).templates;
    state.leftTab = 'library';
    renderLeft();
  }

  // ===== Menus =====

  function roomMenu(r, hit = null) {
    return [
      { label: 'Entrer dans la salle', icon: 'cube', onSelect: () => navigate(`/rooms/${r.id}`) },
      editRooms && { label: hit?.point ? 'Ajouter une porte ici' : 'Ajouter une porte', icon: 'door', onSelect: () => addOpening('door', hit?.point ? { room: r, point: hit.point, wallIndex: hit.wallIndex } : { room: r }) },
      editRooms && { label: hit?.point ? 'Ajouter une fenêtre ici' : 'Ajouter une fenêtre', icon: 'window', onSelect: () => addOpening('window', hit?.point ? { room: r, point: hit.point, wallIndex: hit.wallIndex } : { room: r }) },
      editFurniture && clipboard.get() && { label: `Coller « ${clipboard.get().name} » ici (${MOD}+V)`, icon: 'paste', onSelect: pasteHere },
      { separator: true },
      editRooms && { label: `Dupliquer la salle (${MOD}+D)`, icon: 'copy', onSelect: () => duplicateRoom(r) },
      { label: 'Centrer la vue', icon: 'focus', onSelect: () => scene?.focusSelection() },
      editRooms && { separator: true },
      editRooms && { label: 'Supprimer la salle…', icon: 'trash', danger: true, onSelect: () => deleteRoom(r) },
    ];
  }

  function openingMenu(r, o) {
    return [
      editRooms && { label: 'Déplacer avec le gizmo', icon: 'move', onSelect: () => { setGizmoMode('translate'); select({ kind: 'opening', id: o.id, roomId: r.id }); } },
      { label: 'Entrer dans la salle', icon: 'cube', onSelect: () => navigate(`/rooms/${r.id}`) },
      editRooms && o.id !== 'ref' && { separator: true },
      editRooms && o.id !== 'ref' && { label: 'Supprimer', icon: 'trash', danger: true, onSelect: () => deleteOpening(r, o) },
    ];
  }

  function unitMenu(u) {
    return [
      { label: 'Ouvrir dans la salle', icon: 'cube', onSelect: () => navigate(`/rooms/${u.room_id}?unit=${u.id}`) },
      editFurniture && { separator: true },
      editFurniture && { label: `Copier (${MOD}+C)`, icon: 'copy', onSelect: () => actions.copy(u).catch(showError) },
      editFurniture && { label: `Couper (${MOD}+X)`, icon: 'cut', onSelect: () => actions.copy(u, { cut: true }).catch(showError) },
      editFurniture && { label: `Dupliquer (${MOD}+D)`, icon: 'copy', onSelect: () => duplicateUnit(u) },
      editFurniture && { label: `Enregistrer comme modèle (${MOD}+S)`, icon: 'save', onSelect: () => saveTemplate(u) },
      editFurniture && { separator: true },
      editFurniture && { label: 'Supprimer (Suppr)', icon: 'trash', danger: true, onSelect: () => removeUnit(u) },
    ];
  }

  function floorMenu() {
    return [
      editRooms && { label: 'Ajouter une salle', icon: 'plus', onSelect: () => addRoom() },
      editRooms && { label: 'Ajouter un étage au-dessus', icon: 'stairs', onSelect: () => addFloor(1) },
      editRooms && { label: 'Ajouter un sous-sol', icon: 'stairs', onSelect: () => addFloor(-1) },
      { label: 'Tout voir', icon: 'focus', onSelect: () => scene?.frame() },
    ];
  }

  // ===== Panneau de gauche =====

  function renderLeft() {
    if (!state.plan) return;
    // La bibliothèque sert à poser des rangements : sans ce droit, elle n'a pas lieu d'être
    const tabs = editFurniture && h('div', { class: 'segmented panel-tabs', role: 'tablist', 'aria-label': 'Panneau de gauche' },
      [['floor', 'Étage'], ['library', 'Bibliothèque']].map(([key, text]) => h('button', {
        class: 'segmented-option', type: 'button', role: 'tab', 'aria-selected': String(state.leftTab === key), 'aria-checked': String(state.leftTab === key), onclick: () => { state.leftTab = key; renderLeft(); },
      }, text)));
    clear(left, tabs, state.leftTab === 'library' ? libraryPanel() : floorPanel());
    left.querySelectorAll('.tree-swatch').forEach((el) => { el.style.background = el.dataset.color; });
  }

  function floorPanel() {
    const rooms = roomsOnFloor();
    const sel = state.selection;
    const entry = (selected, onClick, iconName, text, extras = null) => h('li', { class: ['tree-node', selected && 'is-selected'] },
      h('div', { class: 'tree-row' }, h('span', { class: 'tree-toggle' }),
        h('button', { class: 'tree-label', type: 'button', onclick: onClick }, icon(iconName), text), extras));
    const roomNode = (r) => {
      const open = state.expanded.has(r.id) || sel?.roomId === r.id;
      const units = unitsOf(r.id);
      const doors = [referenceDoor(r), ...openingsOf(r.id)];
      const row = h('div', { class: 'tree-row' },
        h('button', { class: 'tree-toggle', type: 'button', 'aria-expanded': String(open), 'aria-label': `${open ? 'Replier' : 'Déplier'} ${r.name}`, onclick: () => { if (state.expanded.has(r.id)) state.expanded.delete(r.id); else state.expanded.add(r.id); renderLeft(); } }, icon('chevronRight')),
        h('button', { class: 'tree-label', type: 'button', onclick: () => select({ kind: 'room', id: r.id, roomId: r.id }), ondblclick: () => navigate(`/rooms/${r.id}`) }, icon('room'), r.name, r.label && h('span', { class: 'badge' }, r.label)),
        h('a', { class: 'tree-eye', href: `/rooms/${r.id}`, title: 'Entrer dans la salle', 'aria-label': `Entrer dans ${r.name}` }, icon('cube')));
      contextMenu(row, () => roomMenu(r), { label: `Actions pour ${r.name}` });
      return h('li', { class: ['tree-node', sel?.kind === 'room' && sel.id === r.id && 'is-selected'] }, row,
        open && h('ul', { class: 'tree-children' },
          units.map((u) => entry(sel?.kind === 'unit' && sel.id === u.id, () => select({ kind: 'unit', id: u.id, roomId: r.id }), 'drawer', u.name)),
          doors.map((o) => entry(sel?.kind === 'opening' && sel.id === o.id && sel.roomId === r.id, () => select({ kind: 'opening', id: o.id, roomId: r.id }), o.kind === 'window' ? 'window' : 'door', openingName(o)))));
    };
    return [
      h('h2', { class: 'panel-subtitle' }, 'Salles', h('span', { class: 'count' }, String(rooms.length))),
      rooms.length
        ? h('ul', { class: 'tree' }, rooms.map(roomNode))
        : emptyState(editRooms ? 'Cet étage est vide. Ajoutez une première salle, puis déplacez-la avec le gizmo.' : 'Aucune salle à cet étage.', {
          iconName: 'room', action: editRooms && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: () => addRoom() }, icon('plus'), 'Ajouter une salle'),
        }),
    ];
  }

  function libraryPanel() {
    const target = currentRoom();
    return h('div', { class: 'library' },
      h('p', { class: 'field-hint' }, target ? `Un clic pose le rangement dans « ${target.name} ».` : 'Cliquez d’abord sur une salle : un clic ici y posera le rangement.'),
      h('h2', { class: 'panel-subtitle' }, 'Mes modèles', h('span', { class: 'count' }, String(state.templates.length))),
      state.templates.length
        ? h('ul', { class: 'plain-list library-list' }, state.templates.map((t) => h('li', { class: 'library-card' },
          h('button', { class: 'library-main', type: 'button', disabled: !editFurniture, onclick: () => placeTemplate(t) },
            templateThumb(t.tree),
            h('span', { class: 'library-text' }, h('strong', {}, t.name), h('span', { class: 'sub' }, templateSummary(t)))))))
        : h('p', { class: 'muted small' }, `Dans une salle, sélectionnez un meuble avec ses tiroirs puis ${MOD}+S : il apparaît ici, prêt à être reposé ailleurs.`),
      editFurniture && [
        h('h2', { class: 'panel-subtitle' }, 'Types de rangement'),
        h('ul', { class: 'plain-list library-types' }, state.types.filter((t) => t.shape === 'cabinet' || t.shape === 'shelf' || t.shape === 'box' || t.shape === 'bin').map((t) => h('li', {},
          h('button', { class: 'tree-label', type: 'button', onclick: () => placeType(t) }, h('span', { class: 'tree-swatch', dataset: { color: t.default_color } }), t.name)))),
      ]);
  }

  // ===== Panneau de droite =====

  function lengthField(text, mm, unitName, onChange, { min = 0, disabled = false } = {}) {
    const input = h('input', { type: 'number', step: UNITS[unitName].step, min: toDisplay(min, unitName), value: toDisplay(mm, unitName), disabled });
    input.addEventListener('change', () => {
      const value = fromDisplay(input.value, unitName);
      if (Number.isFinite(value) && value >= min) onChange(value);
      else input.value = toDisplay(mm, unitName);
    });
    return field(`${text} (${unitName})`, input);
  }

  function renderDetails() {
    if (!state.plan) return;
    const sel = state.selection;
    if (sel?.kind === 'room') return renderRoomDetails(room(sel.id));
    if (sel?.kind === 'unit') return renderUnitDetails(unit(sel.id));
    if (sel?.kind === 'opening') return renderOpeningDetails(room(sel.roomId), opening(room(sel.roomId), sel.id));
    const rooms = roomsOnFloor();
    const floor = state.plan.floors.find((f) => f.id === state.floorId);
    return clear(details,
      h('h2', { class: 'panel-title' }, floor?.name ?? 'Tous les étages'),
      h('dl', { class: 'props' },
        h('dt', {}, 'Salles'), h('dd', {}, String(rooms.length)),
        h('dt', {}, 'Meubles'), h('dd', {}, String(rooms.reduce((n, r) => n + unitsOf(r.id).length, 0))),
        h('dt', {}, 'Portes et fenêtres'), h('dd', {}, String(rooms.reduce((n, r) => n + openingsOf(r.id).length + 1, 0)))),
      h('p', { class: 'alert alert-info', role: 'note' }, icon('info'), h('span', {}, editRooms
        ? 'Cliquez sur une salle, une porte ou un meuble pour le déplacer avec le gizmo. Double-cliquez sur une salle pour y entrer.'
        : 'Double-cliquez sur une salle pour y entrer.')),
      floor && editRooms && h('div', { class: 'detail-actions' },
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => renameFloor(floor) }, icon('edit'), 'Renommer l’étage'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => addFloor(1) }, icon('stairs'), 'Étage au-dessus'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => addFloor(-1) }, icon('stairs'), 'Sous-sol')));
  }

  function renderRoomDetails(r) {
    const u = r.display_unit;
    const size = outlineSize(r.outline);
    const rect = isRectangle(r.outline);
    const name = h('input', { maxlength: 120, value: r.name, disabled: !editRooms });
    name.addEventListener('change', () => patchRoom(r, { name: name.value }, `Renommer « ${r.name} »`));
    const floor = h('select', { disabled: !editRooms }, state.plan.floors.map((f) => h('option', { value: f.id, selected: f.id === r.floor_id }, f.name)));
    floor.addEventListener('change', () => {
      state.floorId = Number(floor.value);
      patchRoom(r, { floor_id: Number(floor.value) }, `Changer l’étage de « ${r.name} »`);
    });
    clear(details,
      h('div', { class: 'detail-header' }, h('h2', { class: 'panel-title' }, r.name), r.label && h('span', { class: 'badge' }, r.label)),
      h('div', { class: 'detail-actions' },
        h('a', { class: 'btn btn-small btn-primary', href: `/rooms/${r.id}` }, icon('cube'), 'Entrer dans la salle'),
        h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Centrer la vue (F)', 'aria-label': 'Centrer la vue', onclick: () => scene?.focusSelection() }, icon('focus')),
        editRooms && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: `Dupliquer (${MOD}+D)`, 'aria-label': 'Dupliquer la salle', onclick: () => duplicateRoom(r) }, icon('copy')),
        editRooms && h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', title: 'Supprimer', 'aria-label': 'Supprimer la salle', onclick: () => deleteRoom(r) }, icon('trash'))),
      h('div', { class: 'panel-form' },
        field('Nom', name),
        field('Étage', floor),
        rect
          ? h('div', { class: 'form-grid' },
            lengthField('Largeur', size.width, u, (mm) => patchRoom(r, { outline: [[0, 0], [mm, 0], [mm, size.depth], [0, size.depth]] }, `Dimensions de « ${r.name} »`), { min: 1000, disabled: !editRooms }),
            lengthField('Profondeur', size.depth, u, (mm) => patchRoom(r, { outline: [[0, 0], [size.width, 0], [size.width, mm], [0, mm]] }, `Dimensions de « ${r.name} »`), { min: 1000, disabled: !editRooms }))
          : h('p', { class: 'field-hint' }, 'Salle à contour libre : sa forme se règle dans ses paramètres, une fois entré dans la salle.'),
        h('div', { class: 'form-grid' },
          lengthField('Hauteur', r.height_mm, u, (mm) => patchRoom(r, { height_mm: mm }, `Hauteur de « ${r.name} »`), { min: 1000, disabled: !editRooms }),
          field('Rotation (°)', (() => {
            const input = h('input', { type: 'number', min: 0, max: 359, step: 5, value: r.plan_rotation_deg, disabled: !editRooms });
            input.addEventListener('change', () => patchRoom(r, { plan_rotation_deg: ((Math.round(Number(input.value)) % 360) + 360) % 360 }, `Pivoter « ${r.name} »`));
            return input;
          })()))),
      h('dl', { class: 'props' },
        h('dt', {}, 'Meubles'), h('dd', {}, String(unitsOf(r.id).length)),
        h('dt', {}, 'Portes et fenêtres'), h('dd', {}, String(openingsOf(r.id).length + 1))),
      editRooms && h('div', { class: 'detail-actions' },
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => addOpening('door', { room: r }) }, icon('door'), 'Ajouter une porte'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => addOpening('window', { room: r }) }, icon('window'), 'Ajouter une fenêtre')),
      editRooms && h('p', { class: 'field-hint' }, 'Gizmo : W déplacer (s’aimante mur contre mur), E pivoter, R dimensions.'));
  }

  function renderOpeningDetails(r, o) {
    const u = r.display_unit;
    const isRef = o.id === 'ref';
    const write = (next) => commitOpening(r, o, { ...o, ...next });
    const name = h('input', { maxlength: 80, value: isRef ? 'Porte principale' : o.name, disabled: !editRooms || isRef });
    name.addEventListener('change', () => write({ name: name.value }));
    const kind = h('select', { disabled: !editRooms || isRef }, Object.entries(OPENING_KINDS).map(([v, t]) => h('option', { value: v, selected: v === o.kind }, t)));
    kind.addEventListener('change', () => write({ kind: kind.value, sill_mm: kind.value === 'window' ? Math.max(o.sill_mm, 900) : 0 }));
    const wall = h('select', { disabled: !editRooms || isRef }, r.outline.map((_, i) => h('option', { value: i, selected: i === o.wall_index }, `Mur ${i + 1}, ${formatLength(wallLength(r.outline, i), u)}`)));
    wall.addEventListener('change', () => write({ wall_index: Number(wall.value) }));
    clear(details,
      h('div', { class: 'detail-header' }, h('h2', { class: 'panel-title' }, openingName(o)), h('span', { class: 'badge' }, OPENING_KINDS[o.kind])),
      h('p', { class: 'muted small' }, `Dans « ${r.name} »`),
      editRooms && h('p', { class: 'field-hint' }, isRef ? 'Tirez le gizmo pour la faire glisser le long de son mur. La porte principale reste sur le mur 1 : elle sert de repère à la salle.' : 'Tirez le gizmo : la porte suit le mur le plus proche et perce le mur en direct.'),
      h('div', { class: 'panel-form' },
        field('Nom', name),
        h('div', { class: 'form-grid' }, field('Type', kind), field('Mur', wall)),
        h('div', { class: 'form-grid' },
          lengthField('Distance', o.offset_mm, u, (mm) => write({ offset_mm: mm }), { disabled: !editRooms }),
          lengthField('Largeur', o.width_mm, u, (mm) => write({ width_mm: mm }), { min: 300, disabled: !editRooms })),
        h('div', { class: 'form-grid' },
          lengthField('Hauteur', o.height_mm, u, (mm) => write({ height_mm: mm }), { min: 500, disabled: !editRooms }),
          !isRef && lengthField('Allège', o.sill_mm, u, (mm) => write({ sill_mm: mm }), { disabled: !editRooms }))),
      editRooms && !isRef && h('div', { class: 'detail-actions' }, h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: () => deleteOpening(r, o) }, icon('trash'), 'Supprimer')));
  }

  function renderUnitDetails(u) {
    const r = room(u.room_id);
    clear(details,
      h('div', { class: 'detail-header' }, h('h2', { class: 'panel-title' }, u.name), h('span', { class: 'badge' }, u.type_name)),
      h('p', { class: 'muted small' }, `Dans « ${r.name} »`),
      h('div', { class: 'detail-actions' },
        h('a', { class: 'btn btn-small btn-primary', href: `/rooms/${u.room_id}?unit=${u.id}` }, icon('cube'), 'Ouvrir dans la salle'),
        editFurniture && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: `Copier (${MOD}+C)`, 'aria-label': `Copier ${u.name}`, onclick: () => actions.copy(u).catch(showError) }, icon('copy')),
        editFurniture && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: `Enregistrer comme modèle (${MOD}+S)`, 'aria-label': `Enregistrer ${u.name} comme modèle`, onclick: () => saveTemplate(u) }, icon('save')),
        editFurniture && h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', title: 'Supprimer (Suppr)', 'aria-label': `Supprimer ${u.name}`, onclick: () => removeUnit(u) }, icon('trash'))),
      h('dl', { class: 'props' },
        h('dt', {}, 'Dimensions (L × P × H)'), h('dd', {}, `${formatLength(u.width_mm, r.display_unit)} × ${formatLength(u.depth_mm, r.display_unit)} × ${formatLength(u.height_mm, r.display_unit)}`),
        h('dt', {}, 'Position'), h('dd', {}, `X ${formatLength(u.pos_x_mm, r.display_unit)} · Z ${formatLength(u.pos_z_mm, r.display_unit)}`),
        h('dt', {}, 'Rotation'), h('dd', {}, `${u.rotation_deg}°`)),
      editFurniture && h('p', { class: 'field-hint' }, 'Tirez le gizmo : le meuble s’aimante aux murs et peut changer de salle. Son contenu le suit. Les tiroirs et le stock se règlent dans la salle.'));
  }

  // ===== Clavier =====

  const onKeyDown = (event) => {
    if (typingOrDialog(event)) return undefined;
    const key = event.key?.toLowerCase();
    const mod = event.ctrlKey || event.metaKey;
    const sel = state.selection;
    const u = sel?.kind === 'unit' ? unit(sel.id) : null;
    const r = sel ? room(sel.roomId) : null;
    const handle = (action) => { event.preventDefault(); action(); };

    if (mod && key === 'z') return handle(() => (event.shiftKey ? history.redo() : history.undo()));
    if (mod && key === 'y') return handle(() => history.redo());
    if (mod && key === 'c' && u) return handle(() => actions.copy(u).catch(showError));
    if (mod && key === 'x' && u) return handle(() => actions.copy(u, { cut: true }).catch(showError));
    if (mod && key === 'v' && editFurniture) return handle(pasteHere);
    if (mod && key === 'd' && u) return handle(() => duplicateUnit(u));
    if (mod && key === 'd' && sel?.kind === 'room' && editRooms) return handle(() => duplicateRoom(r));
    if (mod && key === 's' && u) return handle(() => saveTemplate(u));
    if (mod) return undefined;

    if (event.key === 'Escape') return handle(() => select(null));
    if (event.key === '?') return handle(() => shortcutsDialog());
    if (key === 'f') return handle(() => scene?.focusSelection());
    const modes = { q: 'none', w: 'translate', e: 'rotate', r: 'scale' };
    if (modes[key]) return handle(() => setGizmoMode(modes[key]));
    if (event.key === 'Delete' || event.key === 'Backspace') {
      if (u && editFurniture) return handle(() => removeUnit(u));
      if (sel?.kind === 'opening' && editRooms) return handle(() => deleteOpening(r, opening(r, sel.id)));
      if (sel?.kind === 'room' && editRooms) return handle(() => deleteRoom(r));
    }
    // Flèches : décale la salle sélectionnée, de 10 cm (Maj : 1 m)
    if (sel?.kind === 'room' && editRooms) {
      const step = event.shiftKey ? 1000 : 100;
      const moves = { ArrowLeft: ['plan_x_mm', -step], ArrowRight: ['plan_x_mm', step], ArrowUp: ['plan_z_mm', -step], ArrowDown: ['plan_z_mm', step] };
      const move = moves[event.key];
      if (move) return handle(() => patchRoom(r, { [move[0]]: r[move[0]] + move[1] }, `Déplacer « ${r.name} »`));
    }
    return undefined;
  };
  document.addEventListener('keydown', onKeyDown);
  removeShortcuts = () => document.removeEventListener('keydown', onKeyDown);

  // ===== Chargement =====

  try {
    setTitle('Plan');
    const [types, templates] = await Promise.all([api('/storage-types'), api('/storage-templates')]);
    state.types = types.types;
    state.templates = templates.templates;
    state.plan = placeNewRooms(await api('/plan'));
    state.floorId = pickInitialFloor();
    scene?.setPlan(state.plan, state.floorId);
    scene?.setView(true);
    renderFloors();
    renderLeft();
    renderDetails();
  } catch (error) {
    clear(details, errorState(error, () => location.reload()));
  }
}
