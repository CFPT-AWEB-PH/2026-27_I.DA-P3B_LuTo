// Fiche d'un objet : informations, emplacements avec actions, emprunts en cours, historique.

import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { icon } from '../icons.js';
import { busy, confirmDialog, emptyState, errorState, locationLabel, plural, showError, skeleton, toast } from '../ui.js';
import { addDialog, adjustDialog, formatQuantity, itemDialog, moveDialog, returnDialog, takeDialog } from '../stock-dialogs.js';
import { navigate, setTitle } from '../app.js';
import { movementRow } from './movements.js';
import { categoryBadge } from './items.js';

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

    clear(view,
      back,
      h('header', { class: 'item-header' },
        item.photo_url && h('img', { class: 'item-photo', src: item.photo_url, alt: `Photo : ${item.name}` }),
        h('div', { class: 'item-header-main' },
          h('h1', {}, item.name),
          h('div', { class: 'item-meta' },
            h('span', { class: 'item-ref' }, item.reference),
            item.category && categoryBadge(item.category),
            h('span', { class: 'badge badge-outline' }, item.is_consumable ? 'Consommable' : 'À rendre après usage'),
            !item.is_active && h('span', { class: 'badge badge-warn' }, 'Archivé')),
          h('div', { class: 'stat-row' },
            stat('En stock', formatQuantity(item.total_quantity, item.unit), item.is_low ? 'warn' : 'primary'),
            !item.is_consumable && stat('Emprunté', formatQuantity(item.on_loan, item.unit)),
            item.min_quantity !== null && stat('Alerte sous', formatQuantity(item.min_quantity, item.unit))),
          h('div', { class: 'detail-actions' },
            can('stock.take') && item.is_active && mainLocation && item.locations.length === 1
              && h('button', { class: 'btn btn-primary', type: 'button', onclick: async () => { if (await takeDialog(item, mainLocation)) after(); } }, icon('take'), 'Prendre'),
            can('stock.add') && item.is_active && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await addDialog(item)) after(); } }, icon('plus'), 'Ajouter du stock'),
            can('items.edit') && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await itemDialog(item)) after(); } }, icon('edit'), 'Modifier'),
            can('items.edit') && photoButton(item, after),
            can('items.edit') && (item.is_active
              ? h('button', { class: 'btn btn-danger-ghost', type: 'button', onclick: () => remove(item) }, icon('trash'), 'Supprimer')
              : h('button', { class: 'btn', type: 'button', onclick: (e) => busy(e.currentTarget, async () => {
                try { await api(`/items/${item.id}/restore`, { method: 'POST' }); toast('Objet restauré.'); after(); } catch (err) { showError(err); }
              }) }, icon('undo'), 'Restaurer')),
          ),
        ),
      ),
      item.description && h('p', { class: 'item-description' }, item.description),

      h('section', { class: 'card card-flush', 'aria-labelledby': 'item-locations' },
        h('div', { class: 'section-header' },
          h('h2', { id: 'item-locations' }, 'Où le trouver'),
          item.locations.length > 1 && h('span', { class: 'small muted' }, plural(item.locations.length, 'emplacement'))),
        item.locations.length
          ? h('ul', { class: 'location-rows' }, item.locations.map((l) => h('li', { class: 'location-row' },
            h('div', { class: 'location-row-main' }, locationLabel(l.path, { href: `/rooms/${l.room_id}?unit=${l.unit_id}` })),
            h('span', { class: 'location-row-qty' }, formatQuantity(l.quantity, item.unit)),
            h('div', { class: 'location-row-actions' },
              can('stock.take') && item.is_active && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => { if (await takeDialog(item, l)) after(); } }, icon('take'), 'Prendre'),
              can('stock.move') && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Déplacer', 'aria-label': `Déplacer depuis ${l.path}`, onclick: async () => { if (await moveDialog(item, l)) after(); } }, icon('move')),
              can('stock.adjust') && h('button', { class: 'icon-btn icon-btn-small', type: 'button', title: 'Corriger la quantité', 'aria-label': `Corriger la quantité à ${l.path}`, onclick: async () => { if (await adjustDialog(item, l)) after(); } }, icon('edit')),
              h('a', { class: 'icon-btn icon-btn-small', href: `/rooms/${l.room_id}?unit=${l.unit_id}`, title: 'Voir en 3D', 'aria-label': `Voir ${l.path} en 3D` }, icon('cube')),
            ))))
          : emptyState(can('stock.add') ? 'Aucun stock enregistré. Ajoutez-en pour indiquer où il se trouve.' : 'Aucun stock enregistré pour cet objet.', {
            iconName: 'pin',
            action: can('stock.add') && item.is_active && h('button', { class: 'btn', type: 'button', onclick: async () => { if (await addDialog(item)) after(); } }, icon('plus'), 'Ajouter du stock'),
          })),

      loans.length > 0 && h('section', { class: 'card', 'aria-labelledby': 'item-loans' },
        h('div', { class: 'section-header' }, h('h2', { id: 'item-loans' }, can('history.view') ? 'Emprunts en cours' : 'Mes emprunts en cours')),
        h('ul', { class: 'plain-list' }, loans.map((loan) => h('li', { class: 'list-row' },
          h('div', { class: 'list-row-main' },
            h('strong', {}, loan.user.display_name || loan.user.email),
            h('span', { class: 'small muted' }, `${formatQuantity(loan.remaining, item.unit)} à rendre sur ${formatQuantity(loan.quantity)}, pris le ${formatDate(loan.taken_at)}`),
            loan.comment && h('span', { class: 'small muted' }, loan.comment)),
          (loan.user.id === session.me.user.id || can('stock.adjust')) && can('stock.take')
            && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await returnDialog({ ...loan, item })) after(); } }, icon('undo'), 'Rendre'))))),

      filled.length > 0 && h('section', { class: 'card', 'aria-labelledby': 'item-fields' },
        h('div', { class: 'section-header' }, h('h2', { id: 'item-fields' }, 'Caractéristiques')),
        h('dl', { class: 'props' }, filled.flatMap((f) => [
          h('dt', {}, f.name), h('dd', {}, f.type === 'boolean' ? (f.value === '1' ? 'Oui' : 'Non') : f.value)]))),

      can('history.view') && historySection(item),
      h('p', { class: 'meta-footer' }, `Créé le ${formatDate(item.created_at)}, modifié le ${formatDate(item.updated_at)}.`),
    );
  };

  function stat(label, value, tone = null) {
    return h('div', { class: ['stat', tone && `stat-${tone}`] }, h('div', { class: 'stat-label' }, label), h('div', { class: 'stat-value' }, value));
  }

  function photoButton(item, after) {
    const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', class: 'sr-only' });
    const label = h('label', { class: 'btn' }, input, icon('image'), h('span', {}, item.photo_url ? 'Changer la photo' : 'Ajouter une photo'));
    input.addEventListener('change', () => {
      const file = input.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) return showError(new Error('Photo trop lourde : 5 Mo au maximum.'));
      const form = new FormData();
      form.append('photo', file);
      busy(label, async () => {
        try {
          const res = await fetch(`/api/items/${item.id}/photo`, { method: 'POST', body: form, headers: { 'X-CSRF-Token': session.me.csrf_token }, credentials: 'same-origin' });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data?.error?.fields?.photo ?? data?.error?.message ?? 'La photo n’a pas pu être envoyée.');
          toast('Photo enregistrée.');
          after();
        } catch (error) { showError(error); }
      });
    });
    // Le libellé n'est pas focalisable : Entrée ou Espace ouvrent le sélecteur de fichier
    input.addEventListener('focus', () => label.classList.add('is-focused'));
    input.addEventListener('blur', () => label.classList.remove('is-focused'));
    return label;
  }

  async function remove(item) {
    const ok = await confirmDialog({
      title: 'Supprimer l’objet',
      message: 'S’il a un historique, il sera archivé et l’historique restera consultable. Son stock doit être à zéro.',
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
        ? [h('table', { class: 'data-table' },
          h('thead', {}, h('tr', {}, ['Date', 'Mouvement', 'Quantité', 'Emplacement', 'Par'].map((t) => h('th', { scope: 'col' }, t)))),
          h('tbody', {}, data.movements.map((m) => movementRow(m, false))))]
        : emptyState('Aucun mouvement enregistré pour cet objet.', { iconName: 'history' }));
      if (data.total > 10) body.append(h('p', { class: 'section-footer' }, h('a', { class: 'section-link', href: `/movements?item_id=${item.id}` }, `Voir les ${data.total} mouvements`, icon('chevronRight'))));
    }).catch((error) => clear(body, errorState(error, loadHistory)));
    loadHistory();
    return h('section', { class: 'card card-flush', 'aria-labelledby': 'item-history' },
      h('div', { class: 'section-header' }, h('h2', { id: 'item-history' }, 'Historique')), body);
  }

  await load();
}
