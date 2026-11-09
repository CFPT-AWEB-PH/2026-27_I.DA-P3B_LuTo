import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { busy, checkbox, confirmDialog, errorState, field, openDialog, pageHeader, plural, skeleton, showError, toast } from '../ui.js';
import { icon } from '../icons.js';
import { SHAPE_LABELS } from '../spatial.js';
import { unitPreview } from '../three/preview.js';

export function render(view) {
  const list = h('div', { class: 'list-container' }, skeleton('row', 4));
  append(view, 
    pageHeader({
      title: 'Types de rangement',
      description: 'La forme définit le rendu 3D et le volume intérieur. Les types fournis ne se suppriment pas, mais leurs valeurs par défaut se modifient.',
      actions: h('button', { class: 'btn btn-primary', type: 'button', onclick: () => edit(null).then((ok) => ok && load()) }, icon('plus'), 'Nouveau type'),
    }),
    list,
  );

  const load = async () => {
    try {
      const { types } = await api('/storage-types');
      const remove = async (t) => {
        if (!await confirmDialog({ title: 'Supprimer le type', message: `Supprimer « ${t.name} » ? Aucun rangement ne l’utilise.`, confirmLabel: 'Supprimer le type', danger: true })) return;
        try {
          await api(`/storage-types/${t.id}`, { method: 'DELETE' });
          toast('Type supprimé.');
          load();
        } catch (error) { showError(error); }
      };
      const open = (t) => edit(t).then((ok) => ok && load());
      clear(list, h('div', { class: 'card card-flush' }, h('table', { class: 'data-table types-table' },
        h('thead', {}, h('tr', {}, h('th', {}, 'Type'), h('th', {}, 'Forme'), h('th', {}, 'Dimensions par défaut'), h('th', { class: 'cell-num' }, 'Utilisé'), h('th', {}, h('span', { class: 'sr-only' }, 'Actions')))),
        h('tbody', {}, types.map((t) => {
          const swatch = h('span', { class: 'tree-swatch', 'aria-hidden': 'true' });
          swatch.style.background = t.default_color; // CSSOM : la CSP interdit les attributs style
          return h('tr', {},
            h('td', { 'data-label': 'Type' }, h('span', { class: 'cell-title' }, swatch, t.name, t.is_builtin && h('span', { class: 'badge', title: 'Type fourni : il ne se supprime pas' }, 'fourni'))),
            h('td', { 'data-label': 'Forme' }, SHAPE_LABELS[t.shape]),
            h('td', { 'data-label': 'Dimensions par défaut', class: 'num' },
              `${t.default_width_mm} × ${t.default_depth_mm} × ${t.default_height_mm} mm`, h('span', { class: 'sub' }, `L × P × H, parois ${t.default_panel_mm} mm`)),
            h('td', { 'data-label': 'Utilisé', class: 'cell-num' }, t.usage_count ? plural(t.usage_count, 'fois', 'fois') : h('span', { class: 'muted' }, 'jamais')),
            h('td', { class: 'cell-actions' }, h('span', { class: 'list-row-actions' },
              h('button', { class: 'btn btn-small', type: 'button', 'aria-label': `Modifier le type « ${t.name} »`, onclick: () => open(t) }, icon('edit'), h('span', { class: 'btn-label' }, 'Modifier')),
              !t.is_builtin && (t.usage_count
                ? h('button', { class: 'icon-btn icon-btn-small', type: 'button', disabled: true, 'aria-label': `Suppression impossible : « ${t.name} » est utilisé`, title: 'Utilisé par des rangements : suppression impossible' }, icon('trash'))
                : h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', 'aria-label': `Supprimer le type « ${t.name} »`, title: 'Supprimer', onclick: () => remove(t) }, icon('trash'))))));
        })))));
    } catch (error) {
      clear(list, errorState(error, load));
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
    const live = unitPreview();
    const drawPreview = () => {
      const size = [w, d, hh].map((input) => Number(input.value));
      if (size.every((v) => v > 0)) live.show({ shape: shape.value, width_mm: size[0], depth_mm: size[1], height_mm: size[2], panel_mm: Number(p.value) || 0, color: color.value, label: name.value });
    };

    const form = h('form', { class: 'dialog-body', novalidate: true },
      h('div', { class: 'form-grid' }, field('Nom', name), field('Forme', shape)),
      h('div', { class: 'form-grid form-grid-4' }, field('Largeur (mm)', w), field('Profondeur (mm)', d), field('Hauteur (mm)', hh), field('Parois (mm)', p)),
      field('Couleur par défaut', color),
      live.element,
      checkbox(contain, 'Peut contenir d’autres rangements', 'Ex. une armoire contient des tiroirs.'),
      checkbox(hold, 'Peut recevoir des objets', 'Décochez pour un meuble qui ne sert que de support.'),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, type ? 'Enregistrer le type' : 'Créer le type'))));
    form.addEventListener('input', drawPreview);
    drawPreview();
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
          toast(type ? 'Type enregistré.' : 'Type créé.');
          close(true);
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  }, { wide: true });
}
