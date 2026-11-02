// Fenêtres communes du stock : choix d'un emplacement, prise, retour, ajout, correction, déplacement,
// création ou modification d'un objet. Toutes les vérifications sont refaites par le serveur.

import { api } from './api.js';
import { h } from './dom.js';
import { busy, field, openDialog, showError, toast } from './ui.js';

export function formatQuantity(value, unit = '') {
  const text = Number(value ?? 0).toLocaleString('fr-CH', { maximumFractionDigits: 3 });
  return unit ? `${text} ${unit}` : text;
}

function quantityInput(name, value = '', min = 0.001) {
  return h('input', { name, type: 'number', inputmode: 'decimal', step: 'any', min, required: true, value });
}

function parseQuantity(input) {
  return Number(String(input.value).replace(',', '.'));
}

/** Choix d'un rangement pouvant recevoir des objets : salle puis arborescence. Résout avec { id, path }. */
export function pickUnit({ title = 'Choisir un emplacement', roomId = null } = {}) {
  return openDialog(title, (close) => {
    const roomSelect = h('select', { 'aria-label': 'Salle' }, h('option', { value: '' }, 'Choisir une salle…'));
    const tree = h('div', { class: 'picker-tree' }, h('p', { class: 'muted small' }, 'Choisissez une salle.'));

    const loadRoom = async (id) => {
      if (!id) return;
      tree.replaceChildren(h('p', { class: 'muted small' }, 'Chargement…'));
      try {
        const { units } = await api(`/rooms/${id}`);
        const node = (u) => {
          const kids = units.filter((c) => c.parent_id === u.id);
          return h('li', {},
            u.can_hold_items
              ? h('button', { class: 'tree-label', type: 'button', onclick: () => close({ id: u.id, path: u.path }) }, u.name, u.label && h('span', { class: 'badge' }, u.label))
              : h('span', { class: 'tree-label is-disabled' }, u.name),
            kids.length > 0 && h('ul', {}, kids.map(node)));
        };
        const roots = units.filter((u) => u.parent_id === null);
        tree.replaceChildren(roots.length ? h('ul', { class: 'tree' }, roots.map(node)) : h('p', { class: 'muted small' }, 'Cette salle ne contient aucun rangement.'));
      } catch (error) { showError(error); }
    };
    roomSelect.addEventListener('change', () => loadRoom(roomSelect.value));
    api('/rooms').then(({ rooms }) => {
      roomSelect.append(...rooms.map((r) => h('option', { value: r.id, selected: r.id === roomId }, r.name)));
      if (roomId) loadRoom(roomId);
    }).catch(showError);

    return h('div', { class: 'dialog-body' }, field('Salle', roomSelect), tree,
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler')));
  }, { wide: true });
}

/** Choix d'un objet existant par recherche. Résout avec l'objet ({ id, name, unit, … }). */
export function pickItem(title = 'Choisir un objet') {
  return openDialog(title, (close) => {
    const input = h('input', { type: 'search', placeholder: 'Nom ou référence…', autocomplete: 'off', 'aria-label': 'Rechercher un objet' });
    const list = h('ul', { class: 'plain-list pick-list' });
    let timer;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        try {
          const { items } = await api(`/items?q=${encodeURIComponent(input.value.trim())}&per_page=15`);
          list.replaceChildren(...items.map((i) => h('li', {}, h('button', { class: 'tree-label', type: 'button', onclick: () => close(i) },
            h('strong', {}, i.name), h('span', { class: 'muted small' }, ` ${i.reference} · ${formatQuantity(i.total_quantity, i.unit)}`)))));
        } catch (error) { showError(error); }
      }, 200);
    });
    setTimeout(() => input.focus(), 0);
    return h('div', { class: 'dialog-body' }, input, list,
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler')));
  });
}

/** Bouton qui ouvre le sélecteur d'emplacement et mémorise le choix. */
function unitField(label, initial = null, hint = null) {
  let value = initial;
  const text = h('span', { class: 'unit-choice-text' }, initial?.path ?? 'Aucun emplacement choisi');
  const hidden = h('input', { type: 'hidden', name: 'unit_id' });
  const button = h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
    const picked = await pickUnit();
    if (picked) { value = picked; text.textContent = picked.path; }
  } }, 'Choisir…');
  const wrapper = field(label, h('div', { class: 'unit-choice' }, text, button, hidden), hint);
  return { element: wrapper, get value() { return value; } };
}

function operationDialog(title, build) {
  return openDialog(title, (close) => {
    const { body, submit, label = 'Valider' } = build();
    const form = h('form', { class: 'dialog-body', novalidate: true }, ...body,
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, label)));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      busy(event.submitter, async () => {
        try {
          close(await submit());
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  });
}

/** Prise d'un objet dans un emplacement précis. */
export function takeDialog(item, location) {
  return operationDialog(`Prendre « ${item.name} »`, () => {
    const quantity = quantityInput('quantity', 1);
    quantity.max = location.quantity;
    const comment = h('input', { name: 'comment', maxlength: 500, placeholder: 'Facultatif (projet, classe…)' });
    const returnable = h('input', { type: 'checkbox', checked: !item.is_consumable });
    return {
      label: 'Prendre',
      body: [
        h('p', { class: 'path' }, location.path),
        h('p', {}, 'Disponible ici : ', h('strong', {}, formatQuantity(location.quantity, item.unit))),
        field(`Quantité (${item.unit})`, quantity),
        field('Commentaire', comment),
        h('label', { class: 'checkbox' }, returnable, 'À rendre (ouvre un emprunt à mon nom)'),
      ],
      submit: async () => {
        const r = await api('/stock/take', { method: 'POST', body: {
          item_id: item.id, unit_id: location.unit_id, quantity: parseQuantity(quantity), comment: comment.value, returnable: returnable.checked,
        } });
        toast(`${formatQuantity(parseQuantity(quantity), item.unit)} pris. Reste ici : ${formatQuantity(r.remaining, item.unit)}.`);
        return r;
      },
    };
  });
}

/** Retour (partiel ou total) d'un emprunt. */
export function returnDialog(loan) {
  const unit = loan.item?.unit ?? '';
  return operationDialog(`Rendre « ${loan.item?.name ?? 'objet'} »`, () => {
    const quantity = quantityInput('quantity', loan.remaining);
    quantity.max = loan.remaining;
    const target = unitField('Emplacement de retour', loan.origin.unit_id ? { id: loan.origin.unit_id, path: loan.origin.path } : null,
      "Par défaut, l'emplacement d'origine.");
    const comment = h('input', { name: 'comment', maxlength: 500 });
    return {
      label: 'Enregistrer le retour',
      body: [
        h('p', {}, 'Reste à rendre : ', h('strong', {}, formatQuantity(loan.remaining, unit))),
        field(`Quantité rendue (${unit})`, quantity, 'Un retour partiel laisse l\'emprunt ouvert pour le reste.'),
        target.element,
        field('Commentaire', comment),
      ],
      submit: async () => {
        const body = { quantity: parseQuantity(quantity), comment: comment.value };
        if (target.value && target.value.id !== loan.origin.unit_id) body.unit_id = target.value.id;
        const r = await api(`/loans/${loan.id}/return`, { method: 'POST', body });
        toast(r.status === 'closed' ? 'Retour enregistré, emprunt clôturé.' : `Retour enregistré. Reste à rendre : ${formatQuantity(r.remaining_to_return, unit)}.`);
        return r;
      },
    };
  });
}

/** Réapprovisionnement (location facultative : sinon, choix de l'emplacement). */
export function addDialog(item, location = null) {
  return operationDialog(`Ajouter du stock : « ${item.name} »`, () => {
    const quantity = quantityInput('quantity', '');
    const target = unitField('Emplacement', location ? { id: location.unit_id, path: location.path } : null);
    const comment = h('input', { name: 'comment', maxlength: 500, placeholder: 'Facultatif (commande, fournisseur…)' });
    return {
      label: 'Ajouter',
      body: [target.element, field(`Quantité ajoutée (${item.unit})`, quantity), field('Commentaire', comment)],
      submit: async () => {
        if (!target.value) throw Object.assign(new Error('Choisissez un emplacement.'), { fields: { unit_id: 'Emplacement obligatoire.' } });
        const r = await api('/stock/add', { method: 'POST', body: { item_id: item.id, unit_id: target.value.id, quantity: parseQuantity(quantity), comment: comment.value } });
        toast(`Stock mis à jour : ${formatQuantity(r.quantity, item.unit)} à cet emplacement.`);
        return r;
      },
    };
  });
}

/** Correction : quantité réellement constatée, commentaire obligatoire. */
export function adjustDialog(item, location) {
  return operationDialog(`Corriger le stock : « ${item.name} »`, () => {
    const quantity = quantityInput('quantity', location.quantity, 0);
    const comment = h('input', { name: 'comment', required: true, minlength: 3, maxlength: 500, placeholder: 'Motif obligatoire (inventaire, casse…)' });
    return {
      label: 'Corriger',
      body: [
        h('p', { class: 'path' }, location.path),
        h('p', {}, 'Stock enregistré : ', h('strong', {}, formatQuantity(location.quantity, item.unit))),
        field(`Quantité réelle constatée (${item.unit})`, quantity),
        field('Motif', comment),
      ],
      submit: async () => {
        const r = await api('/stock/adjust', { method: 'POST', body: { item_id: item.id, unit_id: location.unit_id, quantity: parseQuantity(quantity), comment: comment.value } });
        toast('Correction enregistrée.');
        return r;
      },
    };
  });
}

/** Déplacement vers un autre rangement. */
export function moveDialog(item, location) {
  return operationDialog(`Déplacer « ${item.name} »`, () => {
    const quantity = quantityInput('quantity', location.quantity);
    quantity.max = location.quantity;
    const target = unitField('Nouvel emplacement');
    const comment = h('input', { name: 'comment', maxlength: 500 });
    return {
      label: 'Déplacer',
      body: [
        h('p', {}, 'Depuis : ', h('span', { class: 'path' }, location.path)),
        field(`Quantité (${item.unit}, disponible : ${formatQuantity(location.quantity)})`, quantity),
        target.element,
        field('Commentaire', comment),
      ],
      submit: async () => {
        if (!target.value) throw Object.assign(new Error('Choisissez la destination.'), { fields: { unit_id: 'Destination obligatoire.' } });
        const r = await api('/stock/move', { method: 'POST', body: {
          item_id: item.id, from_unit_id: location.unit_id, to_unit_id: target.value.id, quantity: parseQuantity(quantity), comment: comment.value,
        } });
        toast('Déplacement enregistré.');
        return r;
      },
    };
  });
}

/** Création ou modification d'un objet (avec champs personnalisés et stock initial). */
export async function itemDialog(item = null, { canAddStock = false, location = null } = {}) {
  const [{ categories }, { fields }] = await Promise.all([api('/categories'), api('/fields')]);
  let values = {};
  if (item) {
    const detail = await api(`/items/${item.id}`);
    values = Object.fromEntries(detail.fields.filter((f) => f.value !== null).map((f) => [f.id, f.value]));
  }
  return operationDialog(item ? `Modifier « ${item.name} »` : 'Nouvel objet', () => {
    const name = h('input', { name: 'name', required: true, maxlength: 160, value: item?.name ?? '' });
    const reference = h('input', { name: 'reference', required: true, maxlength: 60, value: item?.reference ?? '' });
    const description = h('textarea', { name: 'description', rows: 3, maxlength: 5000 }, item?.description ?? '');
    const category = h('select', { name: 'category_id' }, h('option', { value: '' }, 'Sans catégorie'),
      categories.map((c) => h('option', { value: c.id, selected: c.id === item?.category?.id }, c.name)));
    const unit = h('input', { name: 'unit', required: true, maxlength: 20, value: item?.unit ?? 'pièce', list: 'unit-suggestions' });
    const units = h('datalist', { id: 'unit-suggestions' }, ['pièce', 'paquet', 'boîte', 'm', 'cm', 'kg', 'g', 'l', 'ml', 'rouleau', 'feuille'].map((u) => h('option', { value: u })));
    const min = h('input', { name: 'min_quantity', type: 'number', step: 'any', min: 0, value: item?.min_quantity ?? '' });
    const consumable = h('input', { type: 'checkbox', checked: item?.is_consumable ?? false });
    const custom = h('div', { class: 'custom-fields' });
    const initialQuantity = h('input', { name: 'initial_stock', type: 'number', step: 'any', min: 0, placeholder: '0' });
    const initialUnit = !item && canAddStock ? unitField('Emplacement initial', location) : null;

    const renderCustom = () => {
      const cat = Number(category.value) || null;
      custom.replaceChildren(...fields.filter((f) => f.category_id === null || f.category_id === cat).map((f) => {
        const value = values[f.id] ?? '';
        let input;
        if (f.field_type === 'boolean') input = h('select', {}, h('option', { value: '' }, '–'), h('option', { value: '1', selected: value === '1' }, 'Oui'), h('option', { value: '0', selected: value === '0' }, 'Non'));
        else if (f.field_type === 'select') input = h('select', {}, h('option', { value: '' }, '–'), f.options.map((o) => h('option', { value: o, selected: o === value }, o)));
        else input = h('input', { type: f.field_type === 'number' ? 'number' : f.field_type === 'date' ? 'date' : 'text', step: 'any', value, maxlength: 500 });
        input.name = `field_${f.id}`;
        input.dataset.fieldId = f.id;
        input.addEventListener('change', () => { values[f.id] = input.value; });
        return field(f.name + (f.is_required ? ' *' : ''), input);
      }));
    };
    category.addEventListener('change', renderCustom);
    renderCustom();

    return {
      label: item ? 'Enregistrer' : 'Créer',
      body: [
        h('div', { class: 'form-grid' }, field('Nom', name), field('Référence unique', reference)),
        field('Description', description),
        h('div', { class: 'form-grid form-grid-3' }, field('Catégorie', category), field('Unité', unit), field('Stock minimal (alerte)', min)),
        units,
        h('label', { class: 'checkbox' }, consumable, 'Consommable (une prise n\'est pas à rendre)'),
        custom,
        initialUnit && h('fieldset', { class: 'form-section' }, h('legend', {}, 'Stock initial (facultatif)'),
          h('div', { class: 'form-grid' }, initialUnit.element, field('Quantité', initialQuantity))),
      ],
      submit: async () => {
        const body = {
          name: name.value, reference: reference.value, description: description.value, unit: unit.value,
          category_id: Number(category.value) || null, is_consumable: consumable.checked,
          min_quantity: min.value === '' ? null : Number(min.value),
          fields: Object.fromEntries([...custom.querySelectorAll('[data-field-id]')].map((el) => [el.dataset.fieldId, el.value])),
        };
        if (initialUnit?.value && Number(initialQuantity.value) > 0) {
          body.unit_id = initialUnit.value.id;
          body.initial_stock = Number(initialQuantity.value);
        }
        const r = await api(item ? `/items/${item.id}` : '/items', { method: item ? 'PATCH' : 'POST', body });
        toast(item ? 'Objet enregistré.' : 'Objet créé.');
        return r.item;
      },
    };
  });
}
