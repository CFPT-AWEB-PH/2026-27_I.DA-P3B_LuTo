// Catalogue et recherche : résultats issus de la base (recherche plein texte côté serveur).

import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { icon } from '../icons.js';
import { emptyState, errorState, filterToggle, finePointer, locationLabel, menuButton, pageHeader, plural, showError, skeleton } from '../ui.js';
import { formatQuantity, itemDialog, takeDialog, unitLabel } from '../stock-dialogs.js';
import { navigate } from '../app.js';
import { contextMenu } from '../context-menu.js';
import { itemMenuItems } from '../item-menu.js';

export async function render(view, session) {
  const params = new URLSearchParams(location.search);
  const search = h('input', { type: 'search', placeholder: 'Nom, référence, salle, tiroir, étiquette…', value: params.get('q') ?? '', 'aria-label': 'Rechercher un objet', enterkeyhint: 'search' });
  const category = h('select', { 'aria-label': 'Catégorie' }, h('option', { value: '' }, 'Toutes les catégories'));
  const room = h('select', { 'aria-label': 'Salle' }, h('option', { value: '' }, 'Toutes les salles'));
  const sort = h('select', { 'aria-label': 'Trier par' },
    [['relevance', 'Les plus pertinents'], ['name', 'Nom (A → Z)'], ['reference', 'Référence'], ['quantity', 'Quantité'], ['updated', 'Modifiés récemment']]
      .map(([v, t]) => h('option', { value: v, selected: v === (params.get('sort') ?? 'relevance') }, t)));
  const low = h('input', { type: 'checkbox', checked: params.get('low') === '1' });
  const archived = h('input', { type: 'checkbox', checked: params.get('archived') === '1' });
  const results = h('div', { class: 'results' }, skeleton('row', 5));
  const summary = h('p', { class: 'result-summary', 'aria-live': 'polite' });
  const pager = h('nav', { class: 'pager', 'aria-label': 'Pages de résultats' });
  let page = Number(params.get('page') ?? 1);

  // Mobile : filtres repliés derrière un bouton qui indique combien sont actifs
  const filters = filterToggle('item-filters');
  const syncFilterCount = () => filters.setCount([category.value, room.value, sort.value !== 'relevance', low.checked, archived.checked].filter(Boolean).length);

  const create = async () => {
    const item = await itemDialog(null, { canAddStock: session.can('stock.add') });
    if (item) navigate(`/items/${item.id}`);
  };

  append(view, 
    pageHeader({
      title: 'Objets',
      description: 'Cherchez par nom, référence, catégorie ou emplacement : salle, armoire, tiroir, étiquette.',
      actions: session.can('items.edit') && h('button', { class: 'btn btn-primary', type: 'button', onclick: create }, icon('plus'), 'Nouvel objet'),
    }),
    h('div', { class: 'toolbar toolbar-filters' },
      h('div', { class: 'toolbar-search' }, icon('search'), search),
      filters.element,
      h('div', { class: 'filter-panel', id: 'item-filters' },
        category, room, sort,
        h('label', { class: 'chip-toggle' }, low, 'Stock bas'),
        session.can('items.edit') && h('label', { class: 'chip-toggle' }, archived, 'Archivés'))),
    summary, results, pager,
  );

  try {
    const [{ categories }, { rooms }] = await Promise.all([api('/categories'), api('/rooms')]);
    category.append(...categories.map((c) => h('option', { value: c.id, selected: String(c.id) === params.get('category_id') }, c.name)));
    room.append(...rooms.map((r) => h('option', { value: r.id, selected: String(r.id) === params.get('room_id') }, r.name)));
  } catch (error) { showError(error); }

  const filtered = () => Boolean(search.value.trim() || category.value || room.value || low.checked || archived.checked);
  const reset = () => {
    search.value = ''; category.value = ''; room.value = ''; low.checked = false; archived.checked = false; page = 1;
    load();
    search.focus();
  };

  let sequence = 0;
  const load = async () => {
    const query = new URLSearchParams();
    if (search.value.trim()) query.set('q', search.value.trim());
    if (category.value) query.set('category_id', category.value);
    if (room.value) query.set('room_id', room.value);
    if (sort.value !== 'relevance') query.set('sort', sort.value);
    if (low.checked) query.set('low', '1');
    // La liste ne renvoie pas is_active : le filtre « Archivés » détermine l'état des résultats
    const archivedOnly = archived.checked;
    if (archivedOnly) query.set('archived', '1');
    if (page > 1) query.set('page', page);
    syncFilterCount();
    history.replaceState(null, '', `/items${query.size ? `?${query}` : ''}`);
    const current = ++sequence;
    results.setAttribute('aria-busy', 'true');
    try {
      const data = await api(`/items?${query}`);
      if (current !== sequence) return; // une réponse plus récente est déjà affichée
      summary.textContent = data.total ? plural(data.total, 'objet') : '';
      clear(results, data.items.length
        ? data.items.map((item) => card(item, !archivedOnly))
        : h('div', { class: 'card' }, filtered()
          ? emptyState(search.value.trim() ? `Aucun objet ne correspond à « ${search.value.trim()} ». Vérifiez l’orthographe ou essayez une référence.` : 'Aucun objet ne correspond à ces filtres.', {
            title: 'Aucun résultat', iconName: 'search', action: h('button', { class: 'btn', type: 'button', onclick: reset }, 'Effacer la recherche et les filtres'),
          })
          : emptyState(session.can('items.edit') ? 'Ajoutez un premier objet pour commencer l’inventaire.' : 'Le catalogue est vide pour le moment.', {
            title: 'Aucun objet', iconName: 'box',
            action: session.can('items.edit') && h('button', { class: 'btn btn-primary', type: 'button', onclick: create }, icon('plus'), 'Nouvel objet'),
          })));
      const pages = Math.ceil(data.total / data.per_page);
      clear(pager, pages > 1 && [
        h('button', { class: 'btn btn-small', type: 'button', disabled: page <= 1, onclick: () => { page--; load(); window.scrollTo({ top: 0 }); } }, icon('chevronLeft'), 'Précédente'),
        h('span', { class: 'num' }, `Page ${page} sur ${pages}`),
        h('button', { class: 'btn btn-small', type: 'button', disabled: page >= pages, onclick: () => { page++; load(); window.scrollTo({ top: 0 }); } }, 'Suivante', icon('chevronRight')),
      ]);
    } catch (error) {
      if (current === sequence) clear(results, errorState(error, load));
    } finally {
      if (current === sequence) results.removeAttribute('aria-busy');
    }
  };

  let timer;
  search.addEventListener('input', () => { clearTimeout(timer); page = 1; timer = setTimeout(load, 250); });
  for (const el of [category, room, sort, low, archived]) el.addEventListener('change', () => { page = 1; load(); });
  load();
  if (finePointer()) search.focus();

  function card(item, active) {
    const main = item.locations[0];
    const empty = Number(item.total_quantity) === 0;
    const menuItems = () => itemMenuItems(item, session, { reload: load, active });
    const element = h('article', { class: ['item-card', item.is_low && 'is-low', empty && 'is-empty'] },
      item.photo_url
        ? h('img', { class: 'item-thumb', src: item.photo_url, alt: '', loading: 'lazy', decoding: 'async' })
        : modelThumb(item),
      h('div', { class: 'item-main' },
        h('h2', { class: 'item-name' }, h('a', { href: `/items/${item.id}` }, item.name)),
        h('div', { class: 'item-meta' },
          h('span', { class: 'item-ref' }, item.reference),
          item.category && categoryBadge(item.category),
          item.is_low && h('span', { class: 'badge badge-warn' }, icon('alert'), empty ? 'Épuisé' : 'Stock bas'),
          !active && h('span', { class: 'badge' }, 'Archivé')),
        item.locations.length > 0 && h('ul', { class: 'location-list', 'aria-label': 'Emplacements' },
          item.locations.slice(0, 2).map((l) => h('li', {},
            locationLabel(l.path, { href: `/rooms/${l.room_id}?unit=${l.unit_id}`, quantity: item.locations.length > 1 ? formatQuantity(l.quantity) : null }))),
          item.locations.length > 2 && h('li', { class: 'location-more' }, `+ ${plural(item.locations.length - 2, 'autre emplacement', 'autres emplacements')}`))),
      h('div', { class: 'item-side' },
        h('p', { class: 'item-qty' },
          h('span', { class: 'qty-num' }, formatQuantity(item.total_quantity)),
          h('span', { class: 'qty-unit' }, unitLabel(item.unit, item.total_quantity)),
          h('span', { class: 'sr-only' }, 'en stock')),
        h('div', { class: 'item-actions' },
          main && session.can('stock.take') && active && h('button', {
            class: 'btn btn-small btn-primary', type: 'button', 'aria-label': `Prendre ${item.name}`,
            onclick: async () => { if (await takeDialog(item, main)) load(); },
          }, icon('take'), 'Prendre'),
          // Alternative visible au clic droit et à l'appui long
          menuButton({ label: `Autres actions pour ${item.name}`, items: menuItems().filter((e) => e && !e.separator) }))),
    );
    contextMenu(element, menuItems, { label: `Actions pour ${item.name}` });
    return element;
  }
}

/** Sans photo : aperçu du modèle 3D de l'objet. L'icône reste affichée pendant le rendu, ou si WebGL manque. */
function modelThumb(item) {
  const thumb = h('div', { class: 'item-thumb item-thumb-empty', 'aria-hidden': 'true' }, icon('box'));
  import('../three/item-thumb.js')
    .then(({ itemThumbUrl }) => itemThumbUrl(item))
    .then((url) => { if (url) thumb.replaceChildren(h('img', { class: 'item-thumb-model', src: url, alt: '', decoding: 'async' })); })
    .catch((error) => console.warn('Vignette 3D indisponible', error));
  return thumb;
}

/** Pastille de catégorie : la couleur passe par le CSSOM (la CSP interdit les attributs style). */
export function categoryBadge(category) {
  const dot = h('span', { class: 'dot', 'aria-hidden': 'true' });
  dot.style.background = category.color;
  return h('span', { class: 'badge category-badge' }, dot, category.name);
}
