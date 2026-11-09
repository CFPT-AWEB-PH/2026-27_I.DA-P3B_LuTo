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

  const loans = h('div', { class: 'stagger' }, skeleton('text', 3));
  const low = h('div', {}, skeleton('text', 4));
  const rooms = h('div', { class: 'home-rooms' });
  const kpis = h('nav', { class: 'kpi-row', 'aria-label': 'Chiffres clés' });
  const activity = h('div', {}, skeleton('text', 4));

  append(view, 
    h('section', { class: 'home-hero' },
      h('h1', {}, firstName ? `${greeting()} ${firstName}` : greeting()),
      h('p', {}, can('stock.view') ? 'Que cherchez-vous ? Un nom, une référence ou une étiquette suffit.' : 'Consultez les salles et leurs rangements.'),
      can('stock.view') && searchForm),
    kpis,
    h('div', { class: 'home-grid' },
      can('stock.view') && h('section', { class: 'card', 'aria-labelledby': 'home-loans' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-loans' }, 'À rendre'),
          h('a', { class: 'section-link', href: '/loans' }, 'Mes emprunts', icon('chevronRight'))),
        loans),
      // Réapprovisionner ne concerne que ceux qui peuvent ajouter du stock
      can('stock.add') && h('section', { class: 'card', 'aria-labelledby': 'home-low' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-low' }, 'À réapprovisionner'),
          h('a', { class: 'section-link', href: '/items?low=1' }, 'Tout voir', icon('chevronRight'))),
        low)),
    can('history.view') && h('section', { class: 'card card-flush home-activity', 'aria-labelledby': 'home-activity' },
      h('div', { class: 'section-header' },
        h('h2', { id: 'home-activity' }, 'Derniers mouvements'),
        h('a', { class: 'section-link', href: '/movements' }, 'Historique', icon('chevronRight'))),
      activity),
    can('rooms.view') && h('section', { class: 'home-rooms-section', 'aria-labelledby': 'home-rooms' },
      h('div', { class: 'section-header' },
        h('h2', { id: 'home-rooms' }, 'Salles'),
        h('a', { class: 'section-link', href: '/plan' }, 'Voir le plan', icon('chevronRight'))),
      rooms),
  );
  if (can('stock.view')) {
    // Pas de focus automatique au toucher : il ouvrirait le clavier par-dessus la page
    if (finePointer()) setTimeout(() => searchForm.querySelector('input').focus({ preventScroll: true }), 0);
    loadLoans();
    if (can('stock.add')) loadLow();
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
    // Libellé court au-dessus, précision dessous : pas de parenthèses qui font passer le libellé sur deux lignes
    const tile = (label, value, href, { tone = null, sub = null } = {}) => h('a', { class: ['kpi', tone && `kpi-${tone}`], href },
      h('span', { class: 'kpi-value' }, Number(value).toLocaleString('fr-CH')),
      h('span', { class: 'kpi-label' }, label),
      sub && h('span', { class: 'kpi-sub' }, sub));
    clear(kpis,
      data.stock && tile('Objets', data.stock.items, '/items', { sub: 'au catalogue' }),
      data.stock && can('stock.add') && tile('À réapprovisionner', data.stock.low, '/items?low=1', { tone: data.stock.low > 0 ? 'warn' : null, sub: data.stock.low > 0 ? 'sous le seuil' : 'tout est en ordre' }),
      data.activity
        ? tile('Emprunts en cours', data.activity.open_loans, '/loans', { sub: plural(data.activity.borrowers, 'personne') })
        : data.stock && tile('Mes emprunts', data.stock.my_open_loans, '/loans', { sub: 'en cours' }),
      data.activity && tile('Mouvements', data.activity.movements_today, '/movements', { sub: 'aujourd’hui' }),
      data.spaces && tile('Salles', data.spaces.rooms, '/plan', { sub: plural(data.spaces.units, 'rangement') }));
  }

  async function loadActivity() {
    try {
      const { movements } = await api('/movements?per_page=6');
      clear(activity, movements.length
        ? h('table', { class: 'data-table' },
          h('thead', {}, h('tr', {}, ['Date', 'Objet', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', {}, t)))),
          h('tbody', {}, movements.map((m) => movementRow(m))))
        : emptyState('Les prises, retours et déplacements apparaîtront ici.', { iconName: 'history', compact: true }));
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
            loan.origin.path && locationLabel(loan.origin.path, { compact: true })),
          can('stock.take') && h('button', {
            class: 'btn btn-small btn-tinted', type: 'button', 'aria-label': `Rendre ${loan.item.name}`,
            onclick: async () => { if (await returnDialog(loan)) { loadLoans(); loadKpis(); } },
          }, icon('undo'), 'Rendre')))
        : emptyState('Rien à rendre. Ce que vous prenez apparaîtra ici.', { iconName: 'check', compact: true, tone: 'success' }));
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
          h('span', { class: ['dot', Number(item.total_quantity) === 0 && 'is-empty'], 'aria-hidden': 'true' }),
          h('a', { href: `/items/${item.id}` }, item.name),
          h('span', { class: 'low-qty' },
            h('strong', {}, formatQuantity(item.total_quantity)),
            h('span', {}, ` / min. ${formatQuantity(item.min_quantity, item.unit)}`))))),
        total > items.length && h('p', { class: 'card-footnote' }, `Et ${plural(total - items.length, 'autre objet', 'autres objets')}.`)]
        : emptyState('Tout est au-dessus du seuil d’alerte.', { iconName: 'check', compact: true, tone: 'success' }));
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
