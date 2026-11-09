// Emprunts et retours : mes emprunts, ou tous les emprunts avec la permission history.view.

import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { icon } from '../icons.js';
import { emptyState, errorState, locationLabel, pageHeader, skeleton } from '../ui.js';
import { formatQuantity, returnDialog } from '../stock-dialogs.js';

/** Groupe de boutons à choix unique (plus rapide qu'une liste déroulante pour 2 options). */
function segmented(label, options, value, onChange) {
  const group = h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': label });
  const sync = () => group.querySelectorAll('[role=radio]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.value === value)));
  group.append(...options.map(([v, t]) => h('button', {
    class: 'segmented-option', type: 'button', role: 'radio', dataset: { value: v },
    onclick: () => { if (v !== value) { value = v; sync(); onChange(v); } },
  }, t)));
  sync();
  return { element: group, get value() { return value; } };
}

export function render(view, session) {
  const canAll = session.can('history.view');
  const list = h('div', { class: 'loan-list' }, skeleton('row', 3));
  const scope = canAll ? segmented('Emprunts affichés', [['mine', 'Les miens'], ['all', 'Tout le monde']], 'mine', () => load()) : { value: 'mine' };
  const status = segmented('Statut', [['open', 'En cours'], ['closed', 'Rendus']], 'open', () => load());

  append(view, 
    pageHeader({ title: 'Emprunts', description: 'Ce qui est sorti et doit revenir. Un retour partiel garde l’emprunt ouvert pour le reste.' }),
    h('div', { class: 'toolbar' }, scope.element, status.element),
    list,
  );

  const load = async () => {
    list.setAttribute('aria-busy', 'true');
    try {
      const { loans } = await api(`/loans?scope=${scope.value}&status=${status.value}`);
      clear(list, loans.length ? loans.map((loan) => card(loan)) : h('div', { class: 'card' }, status.value === 'open'
        ? emptyState(scope.value === 'mine' ? 'Vous n’avez rien à rendre.' : 'Aucun objet n’est sorti en ce moment.', { title: 'Aucun emprunt en cours', iconName: 'check' })
        : emptyState('Les emprunts rendus apparaîtront ici.', { title: 'Aucun emprunt rendu', iconName: 'history' })));
    } catch (error) {
      clear(list, errorState(error, load));
    } finally {
      list.removeAttribute('aria-busy');
    }
  };

  function card(loan) {
    const open = loan.status === 'open';
    const mine = loan.user.id === session.me.user.id;
    const returned = Number(loan.quantity) - Number(loan.remaining);
    const bar = h('div', { class: 'progress-bar' });
    bar.style.width = `${Math.round((returned / Number(loan.quantity)) * 100)}%`;
    return h('article', { class: ['loan-card', !open && 'is-closed'] },
      h('div', { class: 'loan-main' },
        h('a', { class: 'loan-title', href: `/items/${loan.item.id}` }, loan.item.name),
        h('div', { class: 'loan-meta' },
          (scope.value === 'all' || !mine) && h('span', {}, icon('users'), loan.user.display_name || loan.user.email),
          h('span', {}, icon('history'), open ? `Pris le ${formatDate(loan.taken_at)}` : `Rendu le ${formatDate(loan.closed_at)}`),
          loan.comment && h('span', {}, loan.comment)),
        loan.origin.path && (loan.origin.room_id
          ? locationLabel(loan.origin.path, { href: `/rooms/${loan.origin.room_id}?unit=${loan.origin.unit_id}`, compact: true })
          : locationLabel(loan.origin.path, { compact: true }))),
      h('div', { class: 'loan-remaining' },
        open
          ? [h('strong', {}, formatQuantity(loan.remaining, loan.item.unit)), h('span', {}, `à rendre sur ${formatQuantity(loan.quantity)}`)]
          : [h('strong', {}, formatQuantity(loan.quantity, loan.item.unit)), h('span', {}, 'rendus')],
        open && returned > 0 && h('div', { class: 'progress', role: 'img', 'aria-label': `${formatQuantity(returned)} déjà rendus` }, bar)),
      open && session.can('stock.take') && (mine || session.can('stock.adjust'))
        ? h('button', { class: 'btn btn-tinted', type: 'button', 'aria-label': `Rendre ${loan.item.name}`, onclick: async () => { if (await returnDialog(loan)) load(); } }, icon('undo'), 'Rendre')
        : h('span'),
    );
  }

  load();
}
