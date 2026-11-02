// Journal des mouvements avec filtres (utilisateur, objet, salle, type, période).

import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { emptyState, field, showError } from '../ui.js';
import { formatQuantity } from '../stock-dialogs.js';

const LABELS = { take: 'Prise (emprunt)', consume: 'Prise (consommation)', return: 'Retour', add: 'Ajout', adjust: 'Correction', move: 'Déplacement' };

export function movementLabel(type) {
  return LABELS[type] ?? type;
}

export function movementRow(m, withItem = true) {
  const unit = m.item.unit;
  const qty = m.type === 'adjust'
    ? `${formatQuantity(m.quantity_before)} → ${formatQuantity(m.quantity_after, unit)}`
    : `${['take', 'consume'].includes(m.type) ? '−' : m.type === 'move' ? '' : '+'}${formatQuantity(m.quantity, unit)}`;
  const place = m.type === 'move'
    ? [h('span', { class: 'path' }, m.from?.path ?? '?'), ' → ', h('span', { class: 'path' }, m.to?.path ?? '?')]
    : h('span', { class: 'path' }, (m.from ?? m.to)?.path ?? '–');
  return h('tr', {},
    h('td', { 'data-label': 'Date' }, formatDate(m.created_at)),
    withItem && h('td', { 'data-label': 'Objet' }, h('a', { href: `/items/${m.item.id}` }, m.item.name), h('div', { class: 'muted small' }, m.item.reference)),
    h('td', { 'data-label': 'Mouvement' }, h('span', { class: ['badge', `movement-${m.type}`] }, movementLabel(m.type)), m.comment && h('div', { class: 'muted small' }, m.comment)),
    h('td', { 'data-label': 'Quantité' }, h('strong', {}, qty)),
    h('td', { 'data-label': 'Emplacement' }, place),
    h('td', { 'data-label': 'Par' }, m.user ? (m.user.display_name || m.user.email) : '–'),
  );
}

export async function render(view) {
  const params = new URLSearchParams(location.search);
  const type = h('select', { name: 'type' }, h('option', { value: '' }, 'Tous'), Object.entries(LABELS).map(([v, t]) => h('option', { value: v, selected: params.get('type') === v }, t)));
  const user = h('select', { name: 'user_id' }, h('option', { value: '' }, 'Tous'));
  const room = h('select', { name: 'room_id' }, h('option', { value: '' }, 'Toutes'));
  const from = h('input', { type: 'date', name: 'from', value: params.get('from') ?? '' });
  const to = h('input', { type: 'date', name: 'to', value: params.get('to') ?? '' });
  const itemId = params.get('item_id');
  const table = h('div', { class: 'list-container' });
  const pager = h('div', { class: 'pager' });
  let page = 1;

  view.append(
    h('div', { class: 'page-header' }, h('h1', {}, 'Historique des mouvements'),
      h('p', { class: 'muted' }, 'Qui a pris, rendu, ajouté, corrigé ou déplacé quoi, quand et en quelle quantité.')),
    itemId && h('p', {}, 'Filtré sur un objet. ', h('a', { href: '/movements' }, 'Retirer ce filtre')),
    h('div', { class: 'filters' }, field('Type', type), field('Utilisateur', user), field('Salle', room), field('Du', from), field('Au', to)),
    table, pager,
  );

  const load = async () => {
    const q = new URLSearchParams({ page, per_page: 50 });
    for (const el of [type, user, room, from, to]) if (el.value) q.set(el.name, el.value);
    if (itemId) q.set('item_id', itemId);
    try {
      const data = await api(`/movements?${q}`);
      clear(table, data.movements.length ? h('table', { class: 'data-table' },
        h('thead', {}, h('tr', {}, ['Date', 'Objet', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', {}, t)))),
        h('tbody', {}, data.movements.map((m) => movementRow(m)))) : emptyState('Aucun mouvement pour ces filtres.'));
      const pages = Math.ceil(data.total / data.per_page);
      clear(pager, h('span', { class: 'muted small' }, `${data.total} mouvement(s)`), pages > 1 && [
        h('button', { class: 'btn btn-small', type: 'button', disabled: page <= 1, onclick: () => { page--; load(); } }, '← Précédent'),
        h('span', { class: 'muted small' }, `Page ${page} / ${pages}`),
        h('button', { class: 'btn btn-small', type: 'button', disabled: page >= pages, onclick: () => { page++; load(); } }, 'Suivant →')]);
    } catch (error) { showError(error); }
  };

  for (const el of [type, user, room, from, to]) el.addEventListener('change', () => { page = 1; load(); });
  try {
    const [{ rooms }, users] = await Promise.all([api('/rooms'), api('/users/options')]);
    room.append(...rooms.map((r) => h('option', { value: r.id }, r.name)));
    user.append(...users.users.map((u) => h('option', { value: u.id }, u.display_name || u.email)));
  } catch (error) { showError(error); }
  load();
}
