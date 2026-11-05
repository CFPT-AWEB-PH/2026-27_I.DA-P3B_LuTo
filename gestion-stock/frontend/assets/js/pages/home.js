// Accueil : trouver un objet (tâche principale), puis ce qui attend l'utilisateur :
// ses emprunts à rendre, le stock à réapprovisionner, les salles.

import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { icon } from '../icons.js';
import { emptyState, errorState, finePointer, locationLabel, plural, skeleton } from '../ui.js';
import { formatQuantity, returnDialog } from '../stock-dialogs.js';
import { navigate } from '../app.js';
import { searchBox } from '../search-box.js';
import { movementRow } from './movements.js';
import { planThumb, roomMenuItems } from '../room-actions.js';
import { contextMenu } from '../context-menu.js';

function greeting() {
  const hour = new Date().getHours();
  return hour < 5 || hour >= 18 ? 'Bonsoir' : 'Bonjour';
}

export function render(view, session) {
  const { user } = session.me;
  const firstName = (user.display_name || '').split(' ')[0];
  const can = (p) => session.can(p);

  // Même composant que la barre supérieure (suggestions instantanées), en grand
  const searchForm = searchBox(navigate, { id: 'home', variant: 'hero', placeholder: 'Ex. multimètre, bécher, câble…' });

  const loans = h('div', {}, skeleton('text', 3));
  const low = h('div', {}, skeleton('text', 4));
  const rooms = h('div', { class: 'home-rooms' });
  const kpis = h('div', { class: 'kpi-row', 'aria-label': 'Chiffres clés' });
  const activity = h('div', {}, skeleton('row', 3));

  append(view, 
    h('section', { class: 'home-hero' },
      h('h1', {}, firstName ? `${greeting()} ${firstName}` : greeting()),
      h('p', {}, can('stock.view') ? 'Trouvez un objet et son emplacement exact, à la salle et au tiroir près.' : 'Consultez les salles et leurs rangements.'),
      can('stock.view') && searchForm),
    kpis,
    h('div', { class: 'home-grid' },
      can('stock.view') && h('section', { class: 'card', 'aria-labelledby': 'home-loans' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-loans' }, 'Mes emprunts en cours'),
          h('a', { class: 'section-link', href: '/loans' }, 'Tous les emprunts', icon('chevronRight'))),
        loans),
      can('stock.view') && h('section', { class: 'card', 'aria-labelledby': 'home-low' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-low' }, 'À réapprovisionner'),
          h('a', { class: 'section-link', href: '/items?low=1' }, 'Voir la liste', icon('chevronRight'))),
        low)),
    can('history.view') && h('section', { class: 'card card-flush home-activity', 'aria-labelledby': 'home-activity' },
      h('div', { class: 'section-header' },
        h('h2', { id: 'home-activity' }, 'Derniers mouvements'),
        h('a', { class: 'section-link', href: '/movements' }, 'Tout l’historique', icon('chevronRight'))),
      activity),
    can('rooms.view') && h('section', { class: 'card card-muted', 'aria-labelledby': 'home-rooms' },
      h('div', { class: 'section-header' },
        h('h2', { id: 'home-rooms' }, 'Salles'),
        h('a', { class: 'section-link', href: '/plan' }, 'Voir le plan', icon('chevronRight'))),
      rooms),
  );
  if (can('stock.view')) {
    // Pas de focus automatique au toucher : il ouvrirait le clavier par-dessus la page
    if (finePointer()) setTimeout(() => searchForm.querySelector('input').focus({ preventScroll: true }), 0);
    loadLoans();
    loadLow();
  }
  if (can('rooms.view')) loadRooms();
  if (can('history.view')) loadActivity();
  loadKpis();

  /** Chiffres clés : chaque tuile mène à la liste correspondante. Une panne ici n'empêche pas le reste de l'accueil. */
  async function loadKpis() {
    let data;
    try {
      data = await api('/dashboard');
    } catch {
      kpis.hidden = true;
      return;
    }
    const tile = (label, value, href, tone = null) => h('a', { class: ['kpi', tone && `kpi-${tone}`], href },
      h('span', { class: 'kpi-value' }, Number(value).toLocaleString('fr-CH')), h('span', { class: 'kpi-label' }, label));
    clear(kpis,
      data.stock && tile('Objets au catalogue', data.stock.items, '/items'),
      data.stock && tile('À réapprovisionner', data.stock.low, '/items?low=1', data.stock.low > 0 ? 'warn' : null),
      data.activity
        ? tile(`Emprunts en cours (${plural(data.activity.borrowers, 'personne')})`, data.activity.open_loans, '/loans')
        : data.stock && tile('Mes emprunts en cours', data.stock.my_open_loans, '/loans'),
      data.activity && tile('Mouvements aujourd’hui', data.activity.movements_today, '/movements'),
      data.spaces && tile(`Salles (${plural(data.spaces.units, 'rangement')})`, data.spaces.rooms, '/plan'));
  }

  async function loadActivity() {
    try {
      const { movements } = await api('/movements?per_page=6');
      clear(activity, movements.length
        ? h('table', { class: 'data-table' },
          h('thead', {}, h('tr', {}, ['Date', 'Objet', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', {}, t)))),
          h('tbody', {}, movements.map((m) => movementRow(m))))
        : emptyState('Les prises, retours et déplacements apparaîtront ici.', { iconName: 'history' }));
    } catch (error) {
      clear(activity, errorState(error, loadActivity));
    }
  }

  async function loadLoans() {
    clear(loans, skeleton('text', 3));
    try {
      const { loans: list } = await api('/loans?scope=mine&status=open');
      clear(loans, list.length
        ? list.slice(0, 5).map((loan) => h('div', { class: 'home-loan' },
          h('div', { class: 'home-loan-main' },
            h('a', { href: `/items/${loan.item.id}` }, loan.item.name),
            h('span', { class: 'small muted' }, `${formatQuantity(loan.remaining, loan.item.unit)} à rendre, pris le ${formatDate(loan.taken_at, false)}`),
            loan.origin.path && locationLabel(loan.origin.path)),
          can('stock.take') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await returnDialog(loan)) loadLoans(); } }, icon('undo'), 'Rendre')))
        : emptyState('Rien à rendre. Les objets que vous prenez apparaîtront ici.', { iconName: 'check' }));
    } catch (error) {
      clear(loans, errorState(error, loadLoans));
    }
  }

  async function loadLow() {
    clear(low, skeleton('text', 4));
    try {
      const { items, total } = await api('/items?low=1&per_page=6&sort=quantity');
      clear(low, items.length
        ? [h('ul', { class: 'plain-list' }, items.map((item) => h('li', { class: 'low-row' },
          h('span', { class: 'dot', 'aria-hidden': 'true' }),
          h('a', { href: `/items/${item.id}` }, item.name),
          h('span', { class: 'low-qty' },
            h('strong', {}, formatQuantity(item.total_quantity)),
            h('span', {}, ` / min. ${formatQuantity(item.min_quantity, item.unit)}`))))),
        total > items.length && h('p', { class: 'small muted' }, `Et ${plural(total - items.length, 'autre objet', 'autres objets')}.`)]
        : emptyState('Tous les objets suivis sont au-dessus de leur stock minimal.', { iconName: 'check' }));
    } catch (error) {
      clear(low, errorState(error, loadLow));
    }
  }

  async function loadRooms() {
    try {
      const { rooms: list } = await api('/rooms');
      clear(rooms, list.length
        ? list.slice(0, 8).map((room) => {
          const link = h('a', { class: 'quick-room', href: `/rooms/${room.id}` },
            planThumb(room),
            h('div', {}, h('strong', {}, room.name), h('span', {}, plural(room.unit_count, 'rangement'))));
          contextMenu(link, () => roomMenuItems(room, session, loadRooms), { label: `Actions pour ${room.name}` });
          return link;
        })
        : h('p', { class: 'muted' }, can('rooms.edit') ? h('a', { href: '/plan' }, 'Créer la première salle sur le plan') : 'Aucune salle pour le moment.'));
    } catch (error) {
      clear(rooms, errorState(error, loadRooms));
    }
  }
}
