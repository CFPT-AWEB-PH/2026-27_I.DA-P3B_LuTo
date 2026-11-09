// Actions d'édition partagées par l'éditeur de salle et le plan : modification, suppression, copie,
// collage et modèles de rangement, chacune avec son annulation. Rien ici ne touche au stock : Ctrl+Z ne
// peut jamais faire disparaître un mouvement de stock, et la suppression d'un rangement plein reste refusée.

import { api } from './api.js';
import { clipboard, MOD, SHORTCUTS } from './history.js';
import { h } from './dom.js';
import { busy, field, openDialog, showError, toast } from './ui.js';
import { formatLength } from './spatial.js';

const SVG = 'http://www.w3.org/2000/svg';

/** Toutes les clés modifiées, avec leur valeur d'avant : de quoi annuler une modification. */
export function before(unit, changes) {
  return Object.fromEntries(Object.keys(changes).map((key) => [key, unit[key]]));
}

/** Retire les identifiants d'un arbre : il sert alors de pur gabarit. */
export function withoutIds(tree) {
  const { id, ...rest } = tree;
  return { ...rest, children: (tree.children ?? []).map(withoutIds) };
}

export function createUnitActions({ history, reload, can }) {
  const rightFor = (parentId) => can(parentId === null ? 'furniture.edit' : 'storage.edit');

  /** Modifie un rangement et l'inscrit dans l'historique. */
  async function patch(unit, changes, label = `Modifier « ${unit.name} »`) {
    const previous = before(unit, changes);
    const id = unit.id;
    await api(`/storage-units/${history.id(id)}`, { method: 'PATCH', body: changes });
    history.push({
      label,
      undo: async () => { await api(`/storage-units/${history.id(id)}`, { method: 'PATCH', body: previous }); await reload(); },
      redo: async () => { await api(`/storage-units/${history.id(id)}`, { method: 'PATCH', body: changes }); await reload(); },
    });
    await reload();
  }

  /** Inscrit dans l'historique un rangement qui vient d'être créé. */
  function recordCreated(id, tree, target, label, onDone) {
    let currentId = id;
    history.push({
      label,
      undo: async () => { await api(`/storage-units/${currentId}`, { method: 'DELETE' }); await reload(); },
      redo: async () => {
        const created = await api('/storage-units/tree', { method: 'POST', body: { ...target, tree } });
        currentId = created.unit.id;
        await reload();
        onDone?.(currentId);
      },
    });
  }

  /** Crée un arbre de rangements, avec annulation. */
  async function createTree(tree, target, label) {
    const created = await api('/storage-units/tree', { method: 'POST', body: { ...target, tree } });
    recordCreated(created.unit.id, tree, target, label);
    await reload();
    return created.unit;
  }

  /** Suppression annulable : l'arbre est lu avant d'être supprimé. */
  async function remove(unit) {
    const { tree } = await api(`/storage-units/${unit.id}/tree`);
    await api(`/storage-units/${unit.id}`, { method: 'DELETE' });
    const target = unit.parent_id === null ? { room_id: unit.room_id } : { parent_id: unit.parent_id };
    let currentId = unit.id;
    const entry = {
      label: `Supprimer « ${unit.name} »`,
      undo: async () => {
        const body = unit.parent_id === null ? target : { parent_id: history.id(unit.parent_id) };
        const created = await api('/storage-units/tree', { method: 'POST', body: { ...body, tree } });
        // Toute l'arborescence a changé d'identifiants : les entrées plus anciennes doivent suivre
        for (const [oldId, newId] of Object.entries(created.ids)) history.remember(Number(oldId), newId);
        currentId = created.unit.id;
        await reload();
      },
      redo: async () => { await api(`/storage-units/${currentId}`, { method: 'DELETE' }); await reload(); },
    };
    history.pushWithToast(entry, `« ${unit.name} » supprimé.`);
    await reload();
  }

  /** Copie : l'arbre part au presse-papiers (structure seulement, jamais le stock). */
  async function copy(unit, { cut = false } = {}) {
    const { tree } = await api(`/storage-units/${unit.id}/tree`);
    clipboard.set({ tree: withoutIds(tree), name: unit.name, cut: cut ? { id: unit.id, room_id: unit.room_id, parent_id: unit.parent_id, position: { pos_x_mm: unit.pos_x_mm, pos_y_mm: unit.pos_y_mm, pos_z_mm: unit.pos_z_mm, rotation_deg: unit.rotation_deg } } : null });
    toast(cut ? `« ${unit.name} » coupé : collez-le où il doit aller (${MOD}+V), il y part avec son contenu.` : `« ${unit.name} » copié (structure, sans le stock).`, 'info');
  }

  /**
   * Colle à l'emplacement donné. Après un Couper, le rangement est déplacé avec son contenu ;
   * après un Copier, il est créé de nouveau, sans le stock.
   */
  async function paste(target, position) {
    const value = clipboard.get();
    if (!value) return null;
    if (!value.cut) return createTree({ ...value.tree, ...position }, target, `Coller « ${value.name} »`);

    const { id, room_id: fromRoom, parent_id: fromParent, position: previous } = value.cut;
    // Vers la salle elle-même : il sort de ce qui le contenait
    const body = { ...('room_id' in target ? { parent_id: null } : {}), ...target, ...position };
    const moved = await api(`/storage-units/${history.id(id)}/move`, { method: 'POST', body });
    clipboard.clearCut();
    history.push({
      label: `Déplacer « ${value.name} »`,
      undo: async () => {
        await api(`/storage-units/${history.id(id)}/move`, { method: 'POST', body: { ...(fromParent === null ? { room_id: fromRoom, parent_id: null } : { parent_id: history.id(fromParent) }), ...previous } });
        await reload();
      },
      redo: async () => { await api(`/storage-units/${history.id(id)}/move`, { method: 'POST', body }); await reload(); },
    });
    await reload();
    return moved.unit;
  }

  /** Enregistre un rangement comme modèle réutilisable. Résout avec le modèle créé. */
  function saveAsTemplate(unit) {
    return openDialog('Enregistrer comme modèle', (close) => {
      const name = h('input', { name: 'name', required: true, maxlength: 120, value: unit.name });
      const description = h('input', { name: 'description', maxlength: 1000, placeholder: 'Facultatif' });
      const form = h('form', { class: 'dialog-body', novalidate: true },
        h('p', { class: 'field-hint' }, `« ${unit.name} » sera enregistré avec tous ses tiroirs et bacs, leurs dimensions et leurs étiquettes. Le stock n'est pas repris.`),
        field('Nom du modèle', name),
        field('Description', description),
        h('div', { class: 'dialog-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'),
          h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Enregistrer le modèle'))));
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        busy(event.submitter, async () => {
          try {
            const { template } = await api('/storage-templates', { method: 'POST', body: { name: name.value, description: description.value, unit_id: unit.id } });
            toast(`Modèle « ${template.name} » enregistré dans la bibliothèque.`);
            close(template);
          } catch (error) { showError(error, form); }
        });
      });
      return form;
    });
  }

  return { rightFor, patch, recordCreated, createTree, remove, copy, paste, saveAsTemplate };
}

/** Dessin de face d'un modèle : le meuble et ses subdivisions, pour le reconnaître dans la liste. */
export function templateThumb(tree) {
  const svg = document.createElementNS(SVG, 'svg');
  const w = tree.width_mm; const height = tree.height_mm;
  const pad = Math.max(w, height) * 0.04;
  svg.setAttribute('viewBox', `${-pad} ${-pad} ${w + 2 * pad} ${height + 2 * pad}`);
  svg.setAttribute('class', 'template-thumb');
  svg.setAttribute('aria-hidden', 'true');
  const rect = (x, y, rw, rh, color) => {
    const el = document.createElementNS(SVG, 'rect');
    Object.entries({ x, y: height - y - rh, width: rw, height: rh, fill: color ?? '#d9d9d9', stroke: 'rgb(0 0 0 / 35%)', 'stroke-width': Math.max(w, height) / 120, rx: Math.max(w, height) / 200 })
      .forEach(([k, v]) => el.setAttribute(k, v));
    svg.append(el);
  };
  rect(0, 0, w, height, tree.color);
  const offset = tree.panel_mm ?? 0;
  for (const child of tree.children ?? []) rect(offset + child.pos_x_mm, offset + child.pos_y_mm, child.width_mm, child.height_mm, child.color);
  return svg;
}

/** Résumé d'un modèle : « 100 × 75 × 110 cm, 12 rangements ». */
export function templateSummary(template, unit = 'cm') {
  const { tree, node_count: count } = template;
  return `${formatLength(tree.width_mm, unit)} × ${formatLength(tree.depth_mm, unit)} × ${formatLength(tree.height_mm, unit)}${count > 1 ? `, ${count} rangements` : ''}`;
}

/** Aide-mémoire des raccourcis clavier. */
export function shortcutsDialog() {
  return openDialog('Raccourcis clavier', (close) => h('div', { class: 'dialog-body' },
    h('div', { class: 'shortcuts' }, SHORTCUTS.map(([title, lines]) => h('section', {},
      h('h3', { class: 'panel-subtitle' }, title),
      h('dl', { class: 'shortcut-list' }, lines.map(([keys, text]) => [
        h('dt', {}, keys.map((key, i) => [i > 0 && ' ou ', h('kbd', {}, key)])),
        h('dd', {}, text),
      ]))))),
    h('div', { class: 'dialog-actions' }, h('button', { class: 'btn btn-primary', type: 'button', onclick: () => close() }, 'Fermer'))));
}
