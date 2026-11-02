// Fiche d'un objet : informations, emplacements avec actions, emprunts en cours, historique.

import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { confirmDialog, emptyState, showError, toast } from '../ui.js';
import { addDialog, adjustDialog, formatQuantity, itemDialog, moveDialog, returnDialog, takeDialog } from '../stock-dialogs.js';
import { navigate } from '../app.js';
import { movementRow } from './movements.js';

export async function render(view, session, params) {
  const id = Number(params.id);
  const can = (p) => session.can(p);

  const load = async () => {
    let data;
    try {
      data = await api(`/items/${id}`);
    } catch (error) {
      clear(view, h('h1', {}, 'Objet introuvable'), h('p', {}, h('a', { href: '/items' }, '← Retour au catalogue')));
      return showError(error);
    }
    const { item, fields, open_loans: loans } = data;
    document.title = `${item.name} · Gestion de stock`;
    const after = () => load();

    clear(view,
      h('a', { class: 'btn btn-small btn-ghost', href: '/items' }, '← Objets'),
      h('div', { class: 'item-header' },
        item.photo_url ? h('img', { class: 'item-photo', src: item.photo_url, alt: `Photo de ${item.name}` }) : null,
        h('div', { class: 'item-header-main' },
          h('h1', {}, item.name),
          h('p', { class: 'muted' }, `Réf. ${item.reference}`, item.category && ` · ${item.category.name}`, !item.is_active && ' · archivé'),
          h('div', { class: 'stat-row' },
            stat('En stock', formatQuantity(item.total_quantity, item.unit), item.is_low && 'warn'),
            stat('En prêt', formatQuantity(item.on_loan, item.unit)),
            item.min_quantity !== null && stat('Stock minimal', formatQuantity(item.min_quantity, item.unit)),
            stat('Type', item.is_consumable ? 'Consommable' : 'À rendre')),
          h('div', { class: 'detail-actions' },
            can('items.edit') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await itemDialog(item)) after(); } }, 'Modifier'),
            can('stock.add') && item.is_active && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await addDialog(item)) after(); } }, 'Ajouter du stock'),
            can('items.edit') && photoButton(item, after),
            can('items.edit') && (item.is_active
              ? h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: () => remove(item) }, 'Supprimer')
              : h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
                try { await api(`/items/${item.id}/restore`, { method: 'POST' }); toast('Objet restauré.'); after(); } catch (e) { showError(e); }
              } }, 'Restaurer')),
          ),
        ),
      ),
      item.description && h('p', { class: 'item-description' }, item.description),

      h('section', { class: 'card' },
        h('h2', {}, 'Emplacements'),
        item.locations.length ? h('table', { class: 'data-table' },
          h('thead', {}, h('tr', {}, h('th', {}, 'Emplacement exact'), h('th', {}, 'Quantité'), h('th', {}, h('span', { class: 'sr-only' }, 'Actions')))),
          h('tbody', {}, item.locations.map((l) => h('tr', {},
            h('td', { 'data-label': 'Emplacement' }, h('a', { href: `/rooms/${l.room_id}?unit=${l.unit_id}`, title: 'Ouvrir la salle en 3D et mettre en évidence ce rangement' }, l.path)),
            h('td', { 'data-label': 'Quantité' }, h('strong', {}, formatQuantity(l.quantity, item.unit))),
            h('td', { class: 'cell-actions' },
              h('a', { class: 'btn btn-small', href: `/rooms/${l.room_id}?unit=${l.unit_id}` }, 'Voir en 3D'),
              can('stock.take') && item.is_active && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => { if (await takeDialog(item, l)) after(); } }, 'Prendre'),
              can('stock.move') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await moveDialog(item, l)) after(); } }, 'Déplacer'),
              can('stock.adjust') && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await adjustDialog(item, l)) after(); } }, 'Corriger'),
            )))),
        ) : emptyState('Aucun stock enregistré pour cet objet.')),

      loans.length > 0 && h('section', { class: 'card' },
        h('h2', {}, can('history.view') ? 'Emprunts en cours' : 'Mes emprunts en cours'),
        h('ul', { class: 'plain-list' }, loans.map((loan) => h('li', { class: 'list-row' },
          h('span', {}, h('strong', {}, loan.user.display_name || loan.user.email), ` · ${formatQuantity(loan.remaining, item.unit)} à rendre sur ${formatQuantity(loan.quantity)} · pris le ${formatDate(loan.taken_at)}`,
            loan.comment && h('span', { class: 'muted' }, ` · ${loan.comment}`)),
          (loan.user.id === session.me.user.id || can('stock.adjust')) && can('stock.take')
            && h('button', { class: 'btn btn-small btn-primary', type: 'button', onclick: async () => { if (await returnDialog({ ...loan, item })) after(); } }, 'Rendre'))))),

      fields.some((f) => f.value !== null) && h('section', { class: 'card' },
        h('h2', {}, 'Caractéristiques'),
        h('dl', { class: 'props' }, fields.filter((f) => f.value !== null).flatMap((f) => [
          h('dt', {}, f.name), h('dd', {}, f.type === 'boolean' ? (f.value === '1' ? 'Oui' : 'Non') : f.value)]))),

      can('history.view') && historySection(item),
      h('p', { class: 'muted small' }, `Créé le ${formatDate(item.created_at)} · modifié le ${formatDate(item.updated_at)}`),
    );
  };

  function stat(label, value, tone = null) {
    return h('div', { class: ['stat', tone && `stat-${tone}`] }, h('div', { class: 'stat-label' }, label), h('div', { class: 'stat-value' }, value));
  }

  function photoButton(item, after) {
    const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', class: 'sr-only' });
    input.addEventListener('change', async () => {
      const file = input.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) return showError(new Error('Photo trop volumineuse (5 Mo maximum).'));
      const form = new FormData();
      form.append('photo', file);
      try {
        const res = await fetch(`/api/items/${item.id}/photo`, { method: 'POST', body: form, headers: { 'X-CSRF-Token': session.me.csrf_token }, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data?.error?.fields?.photo ?? data?.error?.message ?? 'Envoi impossible.');
        toast('Photo enregistrée.');
        after();
      } catch (error) { showError(error); }
    });
    return h('label', { class: 'btn btn-small' }, input, item.photo_url ? 'Changer la photo' : 'Ajouter une photo');
  }

  async function remove(item) {
    const ok = await confirmDialog({
      title: 'Supprimer l\'objet',
      message: 'Un objet sans historique est supprimé ; sinon il est archivé (l\'historique reste consultable). Le stock doit être à zéro.',
      confirmLabel: 'Supprimer', danger: true,
    });
    if (!ok) return;
    try {
      const r = await api(`/items/${item.id}`, { method: 'DELETE' });
      toast(r.archived ? 'Objet archivé.' : 'Objet supprimé.');
      navigate('/items');
    } catch (error) { showError(error); }
  }

  function historySection(item) {
    const body = h('div', {}, h('p', { class: 'muted small' }, 'Chargement…'));
    api(`/movements?item_id=${item.id}&per_page=20`).then((data) => {
      clear(body, data.movements.length
        ? [h('table', { class: 'data-table' },
          h('thead', {}, h('tr', {}, h('th', {}, 'Date'), h('th', {}, 'Mouvement'), h('th', {}, 'Quantité'), h('th', {}, 'Emplacement'), h('th', {}, 'Par'))),
          h('tbody', {}, data.movements.map((m) => movementRow(m, false)))),
        data.total > 20 && h('p', {}, h('a', { href: `/movements?item_id=${item.id}` }, `Voir les ${data.total} mouvements`))]
        : emptyState('Aucun mouvement.'));
    }).catch(showError);
    return h('section', { class: 'card' }, h('h2', {}, 'Historique'), body);
  }

  await load();
}
