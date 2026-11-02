// Catalogue et recherche : résultats issus de la base (recherche plein texte côté serveur).

import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { emptyState, showError } from '../ui.js';
import { formatQuantity, itemDialog, takeDialog } from '../stock-dialogs.js';
import { navigate } from '../app.js';

export async function render(view, session) {
  const params = new URLSearchParams(location.search);
  const search = h('input', { type: 'search', placeholder: 'Nom, référence, emplacement, étiquette…', value: params.get('q') ?? '', 'aria-label': 'Rechercher un objet' });
  const category = h('select', { 'aria-label': 'Catégorie' }, h('option', { value: '' }, 'Toutes les catégories'));
  const room = h('select', { 'aria-label': 'Salle' }, h('option', { value: '' }, 'Toutes les salles'));
  const sort = h('select', { 'aria-label': 'Tri' },
    [['relevance', 'Pertinence'], ['name', 'Nom'], ['reference', 'Référence'], ['quantity', 'Quantité'], ['updated', 'Modification récente']]
      .map(([v, t]) => h('option', { value: v, selected: v === (params.get('sort') ?? 'relevance') }, t)));
  const low = h('input', { type: 'checkbox', checked: params.get('low') === '1' });
  const archived = h('input', { type: 'checkbox', checked: params.get('archived') === '1' });
  const results = h('div', { class: 'results' });
  const summary = h('p', { class: 'muted small', 'aria-live': 'polite' });
  const pager = h('div', { class: 'pager' });
  let page = Number(params.get('page') ?? 1);

  view.append(
    h('div', { class: 'page-header page-header-actions' },
      h('div', {}, h('h1', {}, 'Objets'), h('p', { class: 'muted' }, 'Recherchez par nom, référence, catégorie, salle, armoire, tiroir, boîte, étiquette ou champ personnalisé.')),
      session.can('items.edit') && h('button', { class: 'btn btn-primary', type: 'button', onclick: async () => {
        const item = await itemDialog(null, { canAddStock: session.can('stock.add') });
        if (item) navigate(`/items/${item.id}`);
      } }, 'Nouvel objet')),
    h('div', { class: 'toolbar' }, search, category, room, sort,
      h('label', { class: 'checkbox toolbar-check' }, low, 'Stock bas'),
      session.can('items.edit') && h('label', { class: 'checkbox toolbar-check' }, archived, 'Archivés')),
    summary, results, pager,
  );

  try {
    const [{ categories }, { rooms }] = await Promise.all([api('/categories'), api('/rooms')]);
    category.append(...categories.map((c) => h('option', { value: c.id, selected: String(c.id) === params.get('category_id') }, c.name)));
    room.append(...rooms.map((r) => h('option', { value: r.id, selected: String(r.id) === params.get('room_id') }, r.name)));
  } catch (error) { showError(error); }

  let sequence = 0;
  const load = async () => {
    const query = new URLSearchParams();
    if (search.value.trim()) query.set('q', search.value.trim());
    if (category.value) query.set('category_id', category.value);
    if (room.value) query.set('room_id', room.value);
    if (sort.value !== 'relevance') query.set('sort', sort.value);
    if (low.checked) query.set('low', '1');
    if (archived.checked) query.set('archived', '1');
    if (page > 1) query.set('page', page);
    history.replaceState(null, '', `/items${query.size ? `?${query}` : ''}`);
    const current = ++sequence;
    results.setAttribute('aria-busy', 'true');
    try {
      const data = await api(`/items?${query}`);
      if (current !== sequence) return; // une réponse plus récente est déjà affichée
      summary.textContent = `${data.total} objet(s)`;
      clear(results, data.items.length ? data.items.map((item) => card(item, session)) : emptyState(search.value ? 'Aucun objet ne correspond à cette recherche.' : 'Aucun objet.'));
      const pages = Math.ceil(data.total / data.per_page);
      clear(pager, pages > 1 && [
        h('button', { class: 'btn btn-small', type: 'button', disabled: page <= 1, onclick: () => { page--; load(); } }, '← Précédent'),
        h('span', { class: 'muted small' }, `Page ${page} / ${pages}`),
        h('button', { class: 'btn btn-small', type: 'button', disabled: page >= pages, onclick: () => { page++; load(); } }, 'Suivant →'),
      ]);
    } catch (error) {
      showError(error);
    } finally {
      results.removeAttribute('aria-busy');
    }
  };

  let timer;
  search.addEventListener('input', () => { clearTimeout(timer); page = 1; timer = setTimeout(load, 250); });
  for (const el of [category, room, sort, low, archived]) el.addEventListener('change', () => { page = 1; load(); });
  load();
  search.focus();

  async function refresh() { await load(); }

  function card(item, s) {
    const main = item.locations[0];
    return h('article', { class: ['card', 'item-card', item.is_low && 'is-low'] },
      item.photo_url ? h('img', { class: 'item-thumb', src: item.photo_url, alt: '', loading: 'lazy' }) : h('div', { class: 'item-thumb item-thumb-empty', 'aria-hidden': 'true' }, '▣'),
      h('div', { class: 'item-main' },
        h('div', { class: 'item-title' },
          h('a', { href: `/items/${item.id}` }, item.name),
          item.category && categoryBadge(item.category),
          item.is_low && h('span', { class: 'badge badge-warn' }, 'Stock bas')),
        h('div', { class: 'muted small' }, `Réf. ${item.reference}`),
        item.locations.length
          ? h('ul', { class: 'location-list' }, item.locations.slice(0, 3).map((l) => h('li', {},
            h('a', { href: `/rooms/${l.room_id}?unit=${l.unit_id}`, title: 'Voir en 3D' }, l.path), ' · ', h('strong', {}, formatQuantity(l.quantity, item.unit)))),
            item.locations.length > 3 && h('li', { class: 'muted' }, `+ ${item.locations.length - 3} autre(s) emplacement(s)`))
          : h('p', { class: 'muted small' }, 'Aucun stock enregistré.')),
      h('div', { class: 'item-side' },
        h('div', { class: 'item-qty' }, formatQuantity(item.total_quantity), h('span', { class: 'muted small' }, ` ${item.unit}`)),
        h('div', { class: 'item-actions' },
          h('a', { class: 'btn btn-small', href: `/items/${item.id}` }, 'Fiche'),
          main && h('a', { class: 'btn btn-small', href: `/rooms/${main.room_id}?unit=${main.unit_id}` }, 'Voir en 3D'),
          main && s.can('stock.take') && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => { if (await takeDialog(item, main)) refresh(); } }, 'Prendre'))),
    );
  }
}

/** Pastille colorée : la couleur passe par le CSSOM (la CSP interdit les attributs style). */
export function categoryBadge(category) {
  const badge = h('span', { class: 'badge category-badge' }, category.name);
  badge.style.borderColor = category.color;
  badge.style.color = category.color;
  return badge;
}
