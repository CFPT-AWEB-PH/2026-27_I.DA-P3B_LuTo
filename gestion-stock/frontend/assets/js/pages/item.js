// Fiche d'un objet : informations, emplacements avec actions, emprunts en cours, historique.

import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { icon } from '../icons.js';
import { busy, confirmDialog, emptyState, errorState, locationLabel, menuButton, plural, showError, skeleton, toast } from '../ui.js';
import { addDialog, adjustDialog, formatQuantity, itemDialog, moveDialog, returnDialog, takeDialog, unitLabel } from '../stock-dialogs.js';
import { navigate, setTitle } from '../app.js';
import { movementRow } from './movements.js';
import { categoryBadge, modelThumb } from './items.js';

export async function render(view, session, params) {
  const id = Number(params.id);
  const can = (p) => session.can(p);
  const back = h('a', { class: 'back-link', href: '/items' }, icon('arrowLeft'), 'Objets');
  clear(view, back, skeleton('text', 2), h('div', { class: 'stack' }, skeleton('row', 3)));

  const load = async () => {
    let data;
    try {
      data = await api(`/items/${id}`);
    } catch (error) {
      if (error.status === 404) {
        clear(view, back, emptyState('Cet objet a peut-être été supprimé, ou l’adresse est incorrecte.', {
          title: 'Objet introuvable', iconName: 'search', action: h('a', { class: 'btn btn-primary', href: '/items' }, 'Retour au catalogue'),
        }));
      } else {
        clear(view, back, errorState(error, load));
      }
      return;
    }
    const { item, fields, open_loans: loans } = data;
    setTitle(item.name);
    const after = () => load();
    const mainLocation = item.locations[0];
    const filled = fields.filter((f) => f.value !== null && f.value !== '');

    // Une seule action principale : prendre. En tête quand il n'y a qu'un emplacement, sinon sur chaque ligne
    const single = item.locations.length === 1;
    const canTake = can('stock.take') && item.is_active;
    const take = (l) => async () => { if (await takeDialog(item, l)) after(); };
    const empty = Number(item.total_quantity) === 0;

    clear(view,
      back,
      h('header', { class: 'item-hero' },
        item.photo_url
          ? h('img', { class: 'item-visual', src: item.photo_url, alt: `Photo : ${item.name}`, width: 88, height: 88, decoding: 'async' })
          : modelVisual(item),
        h('div', { class: 'item-hero-main' },
          h('h1', {}, item.name),
          h('div', { class: 'item-meta' },
            h('span', { class: 'item-ref' }, item.reference),
            item.category && categoryBadge(item.category),
            h('span', { class: 'badge badge-outline' }, item.is_consumable ? 'Consommable' : 'À rendre après usage'),
            !item.is_active && h('span', { class: 'badge badge-warn' }, 'Archivé'))),
        h('div', { class: 'detail-actions' },
          canTake && single && h('button', { class: 'btn btn-primary', type: 'button', onclick: take(mainLocation) }, icon('take'), 'Prendre'),
          // Objet archivé : la restauration devient l'action principale
          can('items.edit') && !item.is_active && h('button', { class: 'btn btn-primary', type: 'button', onclick: (e) => busy(e.currentTarget, () => restore(item, after)) }, icon('undo'), 'Restaurer l’objet'),
          can('stock.add') && item.is_active && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await addDialog(item)) after(); } }, icon('plus'), 'Ajouter du stock'),
          can('items.edit') && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await itemDialog(item)) after(); } }, icon('edit'), 'Modifier'),
          can('items.edit') && moreMenu(item, after),
        ),
      ),

      h('div', { class: 'item-layout' },
        h('div', { class: 'item-main-col' },
          h('section', { class: 'card card-flush locations-card', 'aria-labelledby': 'item-locations' },
            h('div', { class: 'section-header' },
              h('h2', { id: 'item-locations' }, 'Où le trouver',
                item.locations.length > 1 && h('span', { class: 'count' }, plural(item.locations.length, 'emplacement')))),
            item.locations.length
              ? h('ul', { class: 'location-rows' }, item.locations.map((l) => h('li', { class: 'location-row' },
                h('div', { class: 'location-row-main' }, locationLabel(l.path, { href: `/rooms/${l.room_id}?unit=${l.unit_id}` })),
                h('span', { class: 'location-row-qty' }, formatQuantity(l.quantity, item.unit)),
                h('div', { class: 'location-row-actions' },
                  canTake && !single && h('button', { class: 'btn btn-small btn-tinted', type: 'button', 'aria-label': `Prendre ici : ${l.path}`, onclick: take(l) }, icon('take'), 'Prendre'),
                  // Actions moins fréquentes, nommées plutôt que figurées par des icônes ambiguës
                  (can('stock.move') || can('stock.adjust')) && menuButton({
                    label: `Autres actions pour ${l.path}`,
                    items: [
                      can('stock.move') && { label: 'Déplacer…', icon: 'move', onSelect: async () => { if (await moveDialog(item, l)) after(); } },
                      can('stock.adjust') && { label: 'Corriger la quantité…', icon: 'edit', onSelect: async () => { if (await adjustDialog(item, l)) after(); } },
                    ],
                  }),
                ))))
              : emptyState(can('stock.add') ? 'Aucun stock enregistré. Ajoutez-en pour indiquer où il se trouve.' : 'Aucun stock enregistré pour cet objet.', {
                iconName: 'pin',
                action: can('stock.add') && item.is_active && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await addDialog(item)) after(); } }, icon('plus'), 'Ajouter du stock'),
              })),

          loans.length > 0 && h('section', { class: 'card loans-card', 'aria-labelledby': 'item-loans' },
            h('div', { class: 'section-header' }, h('h2', { id: 'item-loans' }, can('history.view') ? 'Emprunts en cours' : 'Mes emprunts en cours')),
            h('ul', { class: 'plain-list' }, loans.map((loan) => h('li', { class: 'list-row' },
              h('div', { class: 'list-row-main' },
                h('strong', {}, loan.user.display_name || loan.user.email),
                h('span', { class: 'small muted' }, `${formatQuantity(loan.remaining, item.unit)} à rendre sur ${formatQuantity(loan.quantity)}, pris le ${formatDate(loan.taken_at)}`),
                loan.comment && h('span', { class: 'small muted' }, `« ${loan.comment} »`)),
              (loan.user.id === session.me.user.id || can('stock.adjust')) && can('stock.take')
                && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await returnDialog({ ...loan, item })) after(); } }, icon('undo'), 'Rendre'))))),

          can('history.view') && historySection(item),
        ),

        h('aside', { class: 'item-aside', 'aria-label': 'Informations' },
          h('section', { class: ['card', 'stock-card', item.is_low && 'is-low', empty && 'is-empty'], 'aria-labelledby': 'item-stock' },
            h('h2', { class: 'stock-label', id: 'item-stock' }, 'En stock',
              item.is_low && h('span', { class: 'badge badge-warn' }, icon('alert'), empty ? 'Épuisé' : 'Stock bas')),
            h('p', { class: 'stock-figure' },
              h('span', { class: 'qty-num' }, formatQuantity(item.total_quantity)),
              h('span', { class: 'qty-unit' }, unitLabel(item.unit, item.total_quantity))),
            h('dl', { class: 'props props-divided' },
              !item.is_consumable && [h('dt', {}, 'Emprunté'), h('dd', {}, formatQuantity(item.on_loan, item.unit))],
              item.min_quantity !== null && [h('dt', {}, 'Alerte sous'), h('dd', {}, formatQuantity(item.min_quantity, item.unit))],
              [h('dt', {}, 'Emplacements'), h('dd', {}, String(item.locations.length))])),

          (item.description || filled.length > 0) && h('section', { class: 'card about-card', 'aria-labelledby': 'item-about' },
            h('div', { class: 'section-header' }, h('h2', { id: 'item-about' }, filled.length ? 'Caractéristiques' : 'Description')),
            item.description && h('p', { class: 'item-description' }, item.description),
            filled.length > 0 && h('dl', { class: ['props', item.description && 'props-divided'] }, filled.flatMap((f) => [
              h('dt', {}, f.name), h('dd', {}, f.type === 'boolean' ? (f.value === '1' ? 'Oui' : 'Non') : f.value)]))),

          h('p', { class: 'meta-footer' }, `Créé le ${formatDate(item.created_at)}, modifié le ${formatDate(item.updated_at)}.`),
        ),
      ),
    );
  };

  /** Actions rares regroupées : photo, suppression ou restauration. */
  function moreMenu(item, after) {
    const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true });
    const menu = menuButton({
      label: 'Autres actions sur l’objet',
      items: [
        { label: item.photo_url ? 'Changer la photo…' : 'Ajouter une photo…', icon: 'image', onSelect: () => input.click() },
        item.is_active && { label: 'Supprimer l’objet…', icon: 'trash', danger: true, onSelect: () => remove(item) },
      ],
    });
    const trigger = menu.querySelector('button');
    input.addEventListener('change', () => {
      const file = input.files[0];
      input.value = '';
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) return showError(new Error('Photo trop lourde : 5 Mo au maximum. Réduisez-la ou choisissez-en une autre.'));
      const form = new FormData();
      form.append('photo', file);
      busy(trigger, async () => {
        try {
          const res = await fetch(`/api/items/${item.id}/photo`, { method: 'POST', body: form, headers: { 'X-CSRF-Token': session.me.csrf_token }, credentials: 'same-origin' });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data?.error?.fields?.photo ?? data?.error?.message ?? 'La photo n’a pas pu être envoyée. Réessayez.');
          toast('Photo enregistrée.');
          after();
        } catch (error) { showError(error); }
      });
    });
    menu.append(input);
    return menu;
  }

  async function restore(item, after) {
    try {
      await api(`/items/${item.id}/restore`, { method: 'POST' });
      toast('Objet restauré : il réapparaît dans le catalogue.');
      after();
    } catch (error) { showError(error); }
  }

  async function remove(item) {
    // Le serveur refuse la suppression tant qu'il reste du stock : on le dit avant plutôt qu'après
    if (Number(item.total_quantity) > 0) {
      return showError(new Error(`Il reste ${formatQuantity(item.total_quantity, item.unit)} en stock. Ramenez chaque emplacement à zéro avec « Corriger la quantité », puis supprimez l’objet.`));
    }
    const ok = await confirmDialog({
      title: 'Supprimer l’objet',
      message: 'S’il a un historique, il sera archivé : il disparaîtra du catalogue mais son historique restera consultable, et vous pourrez le restaurer.',
      confirmLabel: 'Supprimer l’objet', danger: true,
    });
    if (!ok) return;
    try {
      const r = await api(`/items/${item.id}`, { method: 'DELETE' });
      toast(r.archived ? 'Objet archivé.' : 'Objet supprimé.');
      navigate('/items');
    } catch (error) { showError(error); }
  }

  function historySection(item) {
    const body = h('div', {}, skeleton('text', 4));
    const loadHistory = () => api(`/movements?item_id=${item.id}&per_page=10`).then((data) => {
      clear(body, data.movements.length
        ? [h('table', { class: 'data-table movements-table' },
          h('thead', {}, h('tr', {}, ['Date', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', { scope: 'col' }, t)))),
          h('tbody', {}, data.movements.map((m) => movementRow(m, false))))]
        : emptyState('Aucun mouvement enregistré pour cet objet.', { iconName: 'history' }));
      if (data.total > 10) body.append(h('p', { class: 'section-footer' }, h('a', { class: 'section-link', href: `/movements?item_id=${item.id}` }, `Voir les ${data.total} mouvements`, icon('chevronRight'))));
    }).catch((error) => clear(body, errorState(error, loadHistory)));
    loadHistory();
    return h('section', { class: 'card card-flush history-card', 'aria-labelledby': 'item-history' },
      h('div', { class: 'section-header' }, h('h2', { id: 'item-history' }, 'Historique')), body);
  }

  await load();
}

/** Sans photo : le modèle 3D de l'objet, comme dans le catalogue. */
function modelVisual(item) {
  const visual = modelThumb(item);
  visual.classList.replace('item-thumb', 'item-visual');
  return visual;
}
