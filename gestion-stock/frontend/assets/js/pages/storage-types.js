import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { busy, confirmDialog, field, openDialog, showError, toast } from '../ui.js';
import { SHAPE_LABELS } from '../spatial.js';

export function render(view) {
  const list = h('div', { class: 'list-container' }, h('p', { class: 'muted' }, 'Chargement…'));
  view.append(
    h('div', { class: 'page-header page-header-actions' },
      h('div', {}, h('h1', {}, 'Types de rangement'),
        h('p', { class: 'muted' }, 'La forme détermine le rendu 3D et le volume intérieur. Les types fournis ne peuvent pas être supprimés, mais leurs valeurs par défaut sont modifiables.')),
      h('button', { class: 'btn btn-primary', type: 'button', onclick: () => edit(null).then((ok) => ok && load()) }, 'Nouveau type')),
    list,
  );

  const load = async () => {
    try {
      const { types } = await api('/storage-types');
      clear(list, h('table', { class: 'data-table' },
        h('thead', {}, h('tr', {}, h('th', {}, 'Type'), h('th', {}, 'Forme'), h('th', {}, 'Dimensions par défaut (mm)'), h('th', {}, 'Utilisations'), h('th', {}, h('span', { class: 'sr-only' }, 'Actions')))),
        h('tbody', {}, types.map((t) => h('tr', {},
          h('td', { 'data-label': 'Type' }, h('span', { class: 'cell-title' }, t.name, t.is_builtin && h('span', { class: 'badge' }, 'fourni'))),
          h('td', { 'data-label': 'Forme' }, SHAPE_LABELS[t.shape]),
          h('td', { 'data-label': 'Dimensions par défaut (mm)' }, `${t.default_width_mm} × ${t.default_depth_mm} × ${t.default_height_mm}, parois ${t.default_panel_mm}`),
          h('td', { 'data-label': 'Utilisations' }, String(t.usage_count)),
          h('td', { class: 'cell-actions' },
            h('button', { class: 'btn btn-small', type: 'button', onclick: () => edit(t).then((ok) => ok && load()) }, 'Modifier'),
            !t.is_builtin && h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: async () => {
              if (!await confirmDialog({ title: 'Supprimer le type', message: `Supprimer « ${t.name} » ?`, confirmLabel: 'Supprimer', danger: true })) return;
              try {
                await api(`/storage-types/${t.id}`, { method: 'DELETE' });
                toast('Type supprimé.');
                load();
              } catch (error) { showError(error); }
            } }, 'Supprimer')),
        )))));
    } catch (error) {
      showError(error);
    }
  };
  load();
}

function edit(type) {
  return openDialog(type ? `Modifier « ${type.name} »` : 'Nouveau type de rangement', (close) => {
    const num = (name, value) => h('input', { name, type: 'number', min: 0, step: 1, value, required: true });
    const name = h('input', { name: 'name', required: true, maxlength: 80, value: type?.name ?? '' });
    const shape = h('select', { name: 'shape', disabled: type?.is_builtin || type?.usage_count > 0 },
      Object.entries(SHAPE_LABELS).map(([v, t]) => h('option', { value: v, selected: v === (type?.shape ?? 'box') }, t)));
    const w = num('default_width_mm', type?.default_width_mm ?? 300);
    const d = num('default_depth_mm', type?.default_depth_mm ?? 300);
    const hh = num('default_height_mm', type?.default_height_mm ?? 200);
    const p = num('default_panel_mm', type?.default_panel_mm ?? 3);
    const color = h('input', { name: 'default_color', type: 'color', value: type?.default_color ?? '#b08d5c' });
    const contain = h('input', { type: 'checkbox', checked: type?.can_contain_units ?? true });
    const hold = h('input', { type: 'checkbox', checked: type?.can_hold_items ?? true });

    const form = h('form', { class: 'dialog-body', novalidate: true },
      h('div', { class: 'form-grid' }, field('Nom', name), field('Forme', shape)),
      h('div', { class: 'form-grid form-grid-4' }, field('Largeur (mm)', w), field('Profondeur (mm)', d), field('Hauteur (mm)', hh), field('Parois (mm)', p)),
      field('Couleur par défaut', color),
      h('label', { class: 'checkbox' }, contain, 'Peut contenir d\'autres rangements'),
      h('label', { class: 'checkbox' }, hold, 'Peut contenir des objets'),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, 'Enregistrer')));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const body = {
        name: name.value, default_width_mm: Number(w.value), default_depth_mm: Number(d.value), default_height_mm: Number(hh.value),
        default_panel_mm: Number(p.value), default_color: color.value, can_contain_units: contain.checked, can_hold_items: hold.checked,
      };
      if (!shape.disabled) body.shape = shape.value;
      busy(event.submitter, async () => {
        try {
          await api(type ? `/storage-types/${type.id}` : '/storage-types', { method: type ? 'PATCH' : 'POST', body });
          toast('Type enregistré.');
          close(true);
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  }, { wide: true });
}
