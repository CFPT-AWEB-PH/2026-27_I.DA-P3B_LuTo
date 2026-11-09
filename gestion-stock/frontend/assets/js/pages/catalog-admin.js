// Catégories et champs personnalisés du catalogue.

import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { busy, checkbox, confirmDialog, emptyState, errorState, field, openDialog, pageHeader, plural, showError, skeleton, toast } from '../ui.js';
import { icon } from '../icons.js';

const TYPES = { text: 'Texte', number: 'Nombre', date: 'Date', boolean: 'Oui / non', select: 'Liste de choix' };

export function render(view) {
  const categories = h('div', { class: 'list-container' }, skeleton('text', 3));
  const fields = h('div', { class: 'list-container' }, skeleton('text', 3));
  let categoryList = [];
  const categoryCount = h('span', { class: 'count' });
  const fieldCount = h('span', { class: 'count' });

  append(view, 
    pageHeader({ title: 'Catégories et champs', description: 'Classez les objets et ajoutez les informations utiles à votre inventaire (marque, date d’achat…).' }),
    h('section', { class: 'card', 'aria-labelledby': 'cat-title' },
      h('div', { class: 'section-header' }, h('h2', { id: 'cat-title' }, 'Catégories', categoryCount),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => editCategory(null) }, icon('plus'), 'Nouvelle catégorie')),
      categories),
    h('section', { class: 'card', 'aria-labelledby': 'field-title' },
      h('div', { class: 'section-header' }, h('h2', { id: 'field-title' }, 'Champs personnalisés', fieldCount),
        h('button', { class: 'btn btn-small', type: 'button', onclick: () => editField(null) }, icon('plus'), 'Nouveau champ')),
      h('p', { class: 'field-hint section-hint' }, 'Un champ s’applique à tous les objets ou à une seule catégorie. Les champs recherchables sont pris en compte par la recherche.'),
      fields),
  );

  const load = async () => {
    try {
      const [c, f] = await Promise.all([api('/categories'), api('/fields')]);
      categoryList = c.categories;
      categoryCount.textContent = c.categories.length ? String(c.categories.length) : '';
      fieldCount.textContent = f.fields.length ? String(f.fields.length) : '';
      clear(categories, c.categories.length
        ? h('ul', { class: 'plain-list category-list' }, c.categories.map((cat) => {
          const dot = h('span', { class: 'category-dot', 'aria-hidden': 'true' });
          dot.style.background = cat.color; // CSSOM : la CSP interdit les attributs style
          return h('li', { class: 'category-row' },
            dot,
            h('div', { class: 'category-row-main' },
              h('strong', {}, cat.name),
              h('span', { class: 'sub' },
                h('a', { href: `/items?category_id=${cat.id}`, title: `Voir les objets de « ${cat.name} »` }, plural(cat.item_count, 'objet')),
                cat.description && ` · ${cat.description}`)),
            rowActions(`la catégorie « ${cat.name} »`, () => editCategory(cat), () => deleteCategory(cat)));
        }))
        : emptyState('Créez des catégories pour regrouper les objets (Électricité, Verrerie…).', { iconName: 'tag' }));
      clear(fields, f.fields.length ? h('table', { class: 'data-table fields-table' },
        h('thead', {}, h('tr', {}, ['Champ', 'Type', 'S’applique à', 'Recherche', ''].map((t, i) => h('th', {}, i === 4 ? h('span', { class: 'sr-only' }, 'Actions') : t)))),
        h('tbody', {}, f.fields.map((fd) => h('tr', {},
          h('td', { 'data-label': 'Champ' }, h('span', { class: 'cell-title' }, fd.name, fd.is_required && h('span', { class: 'badge' }, 'obligatoire'))),
          h('td', { 'data-label': 'Type' }, TYPES[fd.field_type], fd.options && h('div', { class: 'muted small' }, fd.options.join(', '))),
          h('td', { 'data-label': 'S’applique à' }, fd.category_name ?? 'Tous les objets'),
          h('td', { 'data-label': 'Recherche' }, fd.is_searchable ? 'Recherchable' : '–'),
          h('td', { class: 'cell-actions' }, rowActions(`le champ « ${fd.name} »`, () => editField(fd), () => deleteField(fd))))))) : emptyState('Aucun champ personnalisé pour le moment. Ajoutez-en pour noter une marque, une date d’achat, un numéro de série…', { iconName: 'listCheck', compact: true }));
    } catch (error) { clear(categories, errorState(error, load)); clear(fields); }
  };

  /** Modifier (bouton) et supprimer (icône) : un seul rouge discret par ligne, au lieu d'un mot rouge partout. */
  const rowActions = (what, onEdit, onDelete) => h('span', { class: 'list-row-actions' },
    h('button', { class: 'btn btn-small', type: 'button', 'aria-label': `Modifier ${what}`, onclick: onEdit }, icon('edit'), h('span', { class: 'btn-label' }, 'Modifier')),
    h('button', { class: 'icon-btn icon-btn-small btn-danger-ghost', type: 'button', 'aria-label': `Supprimer ${what}`, title: 'Supprimer', onclick: onDelete }, icon('trash')));

  const deleteCategory = async (cat) => {
    const message = cat.item_count
      ? `Les ${plural(cat.item_count, 'objet')} de « ${cat.name} » deviendront sans catégorie, et ses champs propres seront supprimés.`
      : `« ${cat.name} » ne contient aucun objet. Ses champs propres seront supprimés.`;
    if (!await confirmDialog({ title: 'Supprimer la catégorie', message, confirmLabel: 'Supprimer la catégorie', danger: true })) return;
    try { await api(`/categories/${cat.id}`, { method: 'DELETE' }); toast('Catégorie supprimée.'); load(); } catch (e) { showError(e); }
  };
  const deleteField = async (fd) => {
    if (!await confirmDialog({ title: 'Supprimer le champ', message: `Supprimer « ${fd.name} » et toutes ses valeurs ?`, confirmLabel: 'Supprimer le champ', danger: true })) return;
    try { await api(`/fields/${fd.id}`, { method: 'DELETE' }); toast('Champ supprimé.'); load(); } catch (e) { showError(e); }
  };

  const editCategory = (cat) => openDialog(cat ? `Modifier « ${cat.name} »` : 'Nouvelle catégorie', (close) => {
    const name = h('input', { name: 'name', required: true, maxlength: 80, value: cat?.name ?? '' });
    const description = h('input', { name: 'description', maxlength: 255, value: cat?.description ?? '' });
    const color = h('input', { name: 'color', type: 'color', value: cat?.color ?? '#6b7280' });
    const form = h('form', { class: 'dialog-body', novalidate: true }, field('Nom', name), field('Description', description), field('Couleur', color),
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Annuler'), h('button', { class: 'btn btn-primary', type: 'submit' }, 'Enregistrer')));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      busy(e.submitter, async () => {
        try {
          await api(cat ? `/categories/${cat.id}` : '/categories', { method: cat ? 'PATCH' : 'POST', body: { name: name.value, description: description.value, color: color.value } });
          toast('Catégorie enregistrée.');
          close(true);
          load();
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  });

  const editField = (fd) => openDialog(fd ? `Modifier « ${fd.name} »` : 'Nouveau champ', (close) => {
    const name = h('input', { name: 'name', required: true, maxlength: 80, value: fd?.name ?? '' });
    const type = h('select', { name: 'field_type', disabled: !!fd }, Object.entries(TYPES).map(([v, t]) => h('option', { value: v, selected: v === (fd?.field_type ?? 'text') }, t)));
    const options = h('textarea', { name: 'options', rows: 3, placeholder: 'Une option par ligne' }, (fd?.options ?? []).join('\n'));
    const optionsField = field('Options', options);
    const category = h('select', { name: 'category_id' }, h('option', { value: '' }, 'Tous les objets'),
      categoryList.map((c) => h('option', { value: c.id, selected: c.id === fd?.category_id }, c.name)));
    const required = h('input', { type: 'checkbox', checked: fd?.is_required ?? false });
    const searchable = h('input', { type: 'checkbox', checked: fd?.is_searchable ?? true });
    const sync = () => { optionsField.hidden = type.value !== 'select'; };
    type.addEventListener('change', sync);
    sync();
    const form = h('form', { class: 'dialog-body', novalidate: true },
      h('div', { class: 'form-grid' }, field('Nom', name), field('Type', type)), optionsField, field('S\'applique à', category),
      checkbox(required, 'Obligatoire'), checkbox(searchable, 'Recherchable', 'La recherche d’objets tient compte de sa valeur.'),
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Annuler'), h('button', { class: 'btn btn-primary', type: 'submit' }, 'Enregistrer')));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const body = { name: name.value, category_id: Number(category.value) || null, is_required: required.checked, is_searchable: searchable.checked };
      if (!fd) body.field_type = type.value;
      if (type.value === 'select') body.options = options.value.split('\n').map((o) => o.trim()).filter(Boolean);
      busy(e.submitter, async () => {
        try {
          await api(fd ? `/fields/${fd.id}` : '/fields', { method: fd ? 'PATCH' : 'POST', body });
          toast('Champ enregistré.');
          close(true);
          load();
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  });

  load();
}
