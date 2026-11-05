// Journal des mouvements avec filtres (utilisateur, objet, salle, type, période).

import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { icon } from '../icons.js';
import { emptyState, errorState, field, filterToggle, locationLabel, pageHeader, plural, showError, skeleton } from '../ui.js';
import { formatQuantity } from '../stock-dialogs.js';

const LABELS = { take: 'Emprunt', consume: 'Consommation', return: 'Retour', add: 'Ajout', adjust: 'Correction', move: 'Déplacement' };

export function movementLabel(type) {
  return LABELS[type] ?? type;
}

export function movementRow(m, withItem = true) {
  const unit = m.item.unit;
  const out = ['take', 'consume'].includes(m.type);
  const qty = m.type === 'adjust'
    ? h('span', { class: 'qty-delta' }, `${formatQuantity(m.quantity_before)} → ${formatQuantity(m.quantity_after, unit)}`)
    : h('span', { class: ['qty-delta', out && 'qty-minus', ['return', 'add'].includes(m.type) && 'qty-plus'] },
      `${out ? '−' : m.type === 'move' ? '' : '+'}${formatQuantity(m.quantity, unit)}`);
  const place = m.type === 'move'
    ? h('span', { class: 'movement-places' }, locationLabel(m.from?.path ?? '?'), h('span', { class: 'move-arrow', 'aria-label': 'vers' }, '→'), locationLabel(m.to?.path ?? '?'))
    : ((m.from ?? m.to)?.path ? locationLabel((m.from ?? m.to).path) : '–');
  return h('tr', {},
    h('td', { 'data-label': 'Date', class: 'num' }, formatDate(m.created_at)),
    withItem && h('td', { 'data-label': 'Objet' }, h('a', { href: `/items/${m.item.id}` }, m.item.name), h('span', { class: 'sub' }, m.item.reference)),
    h('td', { 'data-label': 'Mouvement' }, h('span', { class: ['badge', `movement-${m.type}`] }, movementLabel(m.type)), m.comment && h('span', { class: 'sub' }, m.comment)),
    h('td', { 'data-label': 'Quantité' }, qty),
    h('td', { 'data-label': 'Emplacement' }, place),
    h('td', { 'data-label': 'Par' }, m.user ? (m.user.display_name || m.user.email) : '–'),
  );
}

export async function render(view) {
  const params = new URLSearchParams(location.search);
  const type = h('select', { name: 'type' }, h('option', { value: '' }, 'Tous'), Object.entries(LABELS).map(([v, t]) => h('option', { value: v, selected: params.get('type') === v }, t)));
  const user = h('select', { name: 'user_id' }, h('option', { value: '' }, 'Tout le monde'));
  const room = h('select', { name: 'room_id' }, h('option', { value: '' }, 'Toutes'));
  const from = h('input', { type: 'date', name: 'from', value: params.get('from') ?? '' });
  const to = h('input', { type: 'date', name: 'to', value: params.get('to') ?? '' });
  const itemId = params.get('item_id');
  const table = h('div', {}, skeleton('row', 6));
  const pager = h('nav', { class: 'pager', 'aria-label': 'Pages de l’historique' });
  const filterEls = [type, user, room, from, to];
  const toggle = filterToggle('movement-filters');
  const reset = h('button', { class: 'btn btn-small btn-ghost filters-reset', type: 'button', hidden: true, onclick: () => {
    filterEls.forEach((el) => { el.value = ''; });
    page = 1;
    load();
  } }, icon('x'), 'Effacer les filtres');
  let page = 1;

  append(view, 
    pageHeader({ title: 'Historique', description: 'Qui a pris, rendu, ajouté, corrigé ou déplacé quoi, quand, et en quelle quantité.' }),
    itemId && h('p', { class: 'filter-note' }, h('span', { class: 'badge badge-info' }, 'Filtré sur un objet'), h('a', { href: '/movements', class: 'small' }, 'Voir tout l’historique')),
    toggle.element,
    h('div', { class: 'filters', id: 'movement-filters' }, field('Type', type), field('Personne', user), field('Salle', room),
      h('div', { class: 'filters-period' }, field('Du', from), field('Au', to))),
    reset,
    table, pager,
  );

  const load = async () => {
    const q = new URLSearchParams({ page, per_page: 50 });
    for (const el of filterEls) if (el.value) q.set(el.name, el.value);
    reset.hidden = !filterEls.some((el) => el.value);
    toggle.setCount(filterEls.filter((el) => el.value).length);
    if (itemId) q.set('item_id', itemId);
    table.setAttribute('aria-busy', 'true');
    try {
      const data = await api(`/movements?${q}`);
      clear(table, data.movements.length ? h('div', { class: 'card card-flush' }, h('table', { class: 'data-table movements-table' },
        h('thead', {}, h('tr', {}, ['Date', 'Objet', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', { scope: 'col' }, t)))),
        h('tbody', {}, data.movements.map((m) => movementRow(m)))))
        : h('div', { class: 'card' }, reset.hidden
          ? emptyState('Les prises, retours, ajouts et déplacements apparaîtront ici.', { title: 'Aucun mouvement', iconName: 'history' })
          : emptyState('Aucun mouvement pour ces filtres. Élargissez la période ou retirez un filtre.', {
            title: 'Aucun résultat', iconName: 'history', action: h('button', { class: 'btn', type: 'button', onclick: () => reset.click() }, 'Effacer les filtres'),
          })));
      const pages = Math.ceil(data.total / data.per_page);
      clear(pager, h('span', {}, plural(data.total, 'mouvement')), pages > 1 && [
        h('button', { class: 'btn btn-small', type: 'button', disabled: page <= 1, onclick: () => { page--; load(); window.scrollTo({ top: 0 }); } }, icon('chevronLeft'), 'Précédente'),
        h('span', { class: 'num' }, `Page ${page} sur ${pages}`),
        h('button', { class: 'btn btn-small', type: 'button', disabled: page >= pages, onclick: () => { page++; load(); window.scrollTo({ top: 0 }); } }, 'Suivante', icon('chevronRight'))]);
    } catch (error) {
      clear(table, errorState(error, load));
    } finally {
      table.removeAttribute('aria-busy');
    }
  };

  for (const el of filterEls) el.addEventListener('change', () => { page = 1; load(); });
  try {
    const [{ rooms }, users] = await Promise.all([api('/rooms'), api('/users/options')]);
    room.append(...rooms.map((r) => h('option', { value: r.id }, r.name)));
    user.append(...users.users.map((u) => h('option', { value: u.id }, u.display_name || u.email)));
  } catch (error) { showError(error); }
  load();
}
