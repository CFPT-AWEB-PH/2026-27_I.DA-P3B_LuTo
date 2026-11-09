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

/** « Lundi 9 novembre » : repère discret au-dessus de la salutation. */
function today() {
  const text = new Intl.DateTimeFormat('fr-CH', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Étiquette d'emplacement d'un emprunt, liée à la vue 3D quand le rangement existe encore. */
function originLabel(origin) {
  return locationLabel(origin.path, { href: origin.room_id ? `/rooms/${origin.room_id}?unit=${origin.unit_id}` : null, compact: true });
}

/** Nombre de cellules du bandeau de chiffres, connu avant la réponse : le squelette a la forme du résultat. */
function kpiCount(can) {
  return [can('stock.view'), can('stock.view') && can('stock.add'), can('stock.view') || can('history.view'), can('history.view'), can('rooms.view')]
    .filter(Boolean).length;
}

const SVG = 'http://www.w3.org/2000/svg';

export function render(view, session) {
  const { user } = session.me;
  const firstName = (user.display_name || '').split(' ')[0];
  const can = (p) => session.can(p);

  // Même composant que la barre supérieure (suggestions instantanées), en grand
  const searchForm = searchBox(navigate, { id: 'home', variant: 'hero', placeholder: 'Ex. multimètre, bécher, câble…' });

  const loans = h('div', { class: 'stagger' }, skeleton('text', 3));
  const loansCount = h('span', { class: 'count' });
  const low = h('div', {}, skeleton('text', 4));
  const lowCount = h('span', { class: 'count' });
  // Squelettes à la forme du contenu final : rien ne saute quand les données arrivent
  const rooms = h('div', { class: 'home-rooms' }, Array.from({ length: 3 }, () => h('div', { class: 'quick-room quick-room-skeleton', 'aria-hidden': 'true' },
    h('span', { class: 'skeleton plan-thumb' }),
    h('span', { class: 'quick-room-text' }, h('span', { class: 'skeleton skeleton-text' }), h('span', { class: 'skeleton skeleton-text' })))));
  const kpis = h('nav', { class: 'kpi-row', 'aria-label': 'Chiffres clés', 'aria-busy': 'true' },
    Array.from({ length: kpiCount(can) }, () => h('div', { class: 'kpi kpi-skeleton', 'aria-hidden': 'true' },
      h('span', { class: 'skeleton skeleton-kpi-value' }), h('span', { class: 'skeleton skeleton-kpi-label' }), h('span', { class: 'skeleton skeleton-kpi-sub' }))));
  const activity = h('div', {}, skeleton('text', 4));

  append(view, 
    h('section', { class: 'home-hero' },
      h('p', { class: 'home-date' }, today()),
      h('h1', {}, firstName ? `${greeting()} ${firstName}` : greeting()),
      h('p', { class: 'home-lead' }, can('stock.view') ? 'Que cherchez-vous ? Un nom, une référence ou une étiquette suffit.' : 'Consultez les salles et leurs rangements.'),
      can('stock.view') && searchForm),
    kpis,
    h('div', { class: 'home-grid' },
      can('stock.view') && h('section', { class: 'card', 'aria-labelledby': 'home-loans' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-loans' }, 'À rendre', loansCount),
          h('a', { class: 'section-link', href: '/loans' }, 'Mes emprunts', icon('chevronRight'))),
        loans),
      // Réapprovisionner ne concerne que ceux qui peuvent ajouter du stock
      can('stock.add') && h('section', { class: 'card', 'aria-labelledby': 'home-low' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'home-low' }, 'À réapprovisionner', lowCount),
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
    } finally {
      kpis.removeAttribute('aria-busy');
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
        ? h('table', { class: 'data-table movements-table' },
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
      loansCount.textContent = list.length ? String(list.length) : '';
      // Nom et quantité à gauche, « Rendre » à droite ; l'emplacement d'origine dessous (toute la largeur au téléphone)
      clear(loans, list.length
        ? [list.slice(0, 5).map((loan) => h('div', { class: 'home-loan' },
          h('div', { class: 'home-loan-main' },
            h('a', { href: `/items/${loan.item.id}` }, loan.item.name),
            h('span', { class: 'home-loan-meta' },
              h('strong', {}, formatQuantity(loan.remaining, loan.item.unit)), ' à rendre ',
              h('span', { class: 'nowrap' }, `· pris le ${formatDate(loan.taken_at, false)}`))),
          can('stock.take') && h('button', {
            class: 'btn btn-small btn-tinted', type: 'button', 'aria-label': `Rendre ${loan.item.name}`,
            onclick: async () => { if (await returnDialog(loan)) { loadLoans(); loadKpis(); } },
          }, icon('undo'), 'Rendre'),
          loan.origin.path && h('div', { class: 'home-loan-loc' }, originLabel(loan.origin)))),
        list.length > 5 && h('p', { class: 'card-footnote' }, h('a', { href: '/loans' }, `Et ${plural(list.length - 5, 'autre emprunt')}`))]
        : emptyState('Rien à rendre. Ce que vous prenez apparaîtra ici.', { iconName: 'check', compact: true, tone: 'success' }));
    } catch (error) {
      clear(loans, errorState(error, loadLoans));
    }
  }

  async function loadLow() {
    clear(low, skeleton('text', 4));
    try {
      const { items, total } = await api('/items?low=1&per_page=6&sort=quantity');
      lowCount.textContent = total ? String(total) : '';
      clear(low, items.length
        ? [h('ul', { class: 'plain-list' }, items.map((item) => h('li', { class: 'low-row' },
          h('span', { class: ['dot', Number(item.total_quantity) === 0 && 'is-empty'], 'aria-hidden': 'true' }),
          h('a', { href: `/items/${item.id}` }, item.name),
          h('span', { class: 'low-qty' },
            h('strong', {}, formatQuantity(item.total_quantity)),
            h('span', {}, ` / min. ${formatQuantity(item.min_quantity, item.unit)}`))))),
        total > items.length && h('p', { class: 'card-footnote' }, h('a', { href: '/items?low=1' }, `Et ${plural(total - items.length, 'autre objet', 'autres objets')}`))]
        : emptyState('Tout est au-dessus du seuil d’alerte.', { iconName: 'check', compact: true, tone: 'success' }));
    } catch (error) {
      clear(low, errorState(error, loadLow));
    }
  }

  async function loadRooms() {
    try {
      // Le plan donne les meubles posés au sol : la miniature montre la salle telle qu'on la connaît
      const [{ rooms: list }, plan] = await Promise.all([api('/rooms'), api('/plan').catch(() => null)]);
      const unitsByRoom = new Map();
      for (const unit of plan?.units ?? []) {
        if (unit.parent_id !== null) continue;
        if (!unitsByRoom.has(unit.room_id)) unitsByRoom.set(unit.room_id, []);
        unitsByRoom.get(unit.room_id).push(unit);
      }
      clear(rooms, list.length
        ? list.slice(0, 8).map((room) => {
          const thumb = planThumb(room);
          for (const unit of unitsByRoom.get(room.id) ?? []) {
            // Même repère que la scène 3D : coin d'origine du meuble, rotation autour de ce coin (sens inverse en SVG)
            const rect = document.createElementNS(SVG, 'rect');
            Object.entries({
              x: unit.pos_x_mm, y: unit.pos_z_mm, width: unit.width_mm, height: unit.depth_mm, class: 'plan-thumb-unit',
              transform: `rotate(${-unit.rotation_deg} ${unit.pos_x_mm} ${unit.pos_z_mm})`,
            }).forEach(([k, v]) => rect.setAttribute(k, v));
            thumb.insertBefore(rect, thumb.lastChild);
          }
          const link = h('a', { class: 'quick-room', href: `/rooms/${room.id}` },
            thumb,
            h('div', { class: 'quick-room-text' },
              h('strong', {}, room.name),
              h('span', {}, [room.label, plural(room.unit_count, 'rangement')].filter(Boolean).join(' · '))));
          contextMenu(link, () => roomMenuItems(room, session, loadRooms), { label: `Actions pour ${room.name}` });
          return link;
        })
        : h('p', { class: 'muted' }, can('rooms.edit') ? h('a', { href: '/plan' }, 'Créer la première salle sur le plan') : 'Aucune salle pour le moment.'));
    } catch (error) {
      clear(rooms, errorState(error, loadRooms));
    }
  }
}
