// Emprunts et retours : mes emprunts, ou tous les emprunts avec la permission history.view.

import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { emptyState, showError } from '../ui.js';
import { formatQuantity, returnDialog } from '../stock-dialogs.js';

export function render(view, session) {
  const canAll = session.can('history.view');
  const scope = h('select', { 'aria-label': 'Emprunts affichés' },
    h('option', { value: 'mine' }, 'Mes emprunts'), canAll && h('option', { value: 'all' }, 'Tous les emprunts'));
  const status = h('select', { 'aria-label': 'Statut' }, h('option', { value: 'open' }, 'En cours'), h('option', { value: 'closed' }, 'Clôturés'));
  const list = h('div', { class: 'list-container' });

  view.append(
    h('div', { class: 'page-header' }, h('h1', {}, 'Emprunts et retours'),
      h('p', { class: 'muted' }, 'Les retours partiels laissent l\'emprunt ouvert pour la quantité restante.')),
    h('div', { class: 'toolbar' }, scope, status),
    list,
  );

  const load = async () => {
    try {
      const { loans } = await api(`/loans?scope=${scope.value}&status=${status.value}`);
      clear(list, loans.length ? h('table', { class: 'data-table' },
        h('thead', {}, h('tr', {}, ['Objet', 'Emprunteur', 'Pris le', 'Quantité', 'Reste à rendre', 'Origine', ''].map((t) => h('th', {}, t)))),
        h('tbody', {}, loans.map((loan) => h('tr', {},
          h('td', { 'data-label': 'Objet' }, h('a', { href: `/items/${loan.item.id}` }, loan.item.name), h('div', { class: 'muted small' }, loan.item.reference)),
          h('td', { 'data-label': 'Emprunteur' }, loan.user.display_name || loan.user.email),
          h('td', { 'data-label': 'Pris le' }, formatDate(loan.taken_at), loan.comment && h('div', { class: 'muted small' }, loan.comment)),
          h('td', { 'data-label': 'Quantité' }, formatQuantity(loan.quantity, loan.item.unit)),
          h('td', { 'data-label': 'Reste à rendre' }, loan.status === 'open' ? h('strong', {}, formatQuantity(loan.remaining, loan.item.unit)) : `rendu le ${formatDate(loan.closed_at)}`),
          h('td', { 'data-label': 'Origine' }, loan.origin.room_id
            ? h('a', { class: 'path', href: `/rooms/${loan.origin.room_id}?unit=${loan.origin.unit_id}`, title: 'Voir en 3D' }, loan.origin.path)
            : h('span', { class: 'path' }, loan.origin.path || '–')),
          h('td', { class: 'cell-actions' }, loan.status === 'open' && session.can('stock.take')
            && (loan.user.id === session.me.user.id || session.can('stock.adjust'))
            && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => { if (await returnDialog(loan)) load(); } }, 'Rendre')),
        )))) : emptyState(status.value === 'open' ? 'Aucun emprunt en cours.' : 'Aucun emprunt clôturé.'));
    } catch (error) { showError(error); }
  };
  scope.addEventListener('change', load);
  status.addEventListener('change', load);
  load();
}
