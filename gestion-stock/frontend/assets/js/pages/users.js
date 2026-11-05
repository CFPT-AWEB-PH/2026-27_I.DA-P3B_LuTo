import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { confirmDialog, emptyState, errorState, pageHeader, skeleton, showError, toast } from '../ui.js';
import { icon } from '../icons.js';

export async function render(view, session) {
  const search = h('input', { type: 'search', placeholder: 'Nom ou adresse e-mail…', 'aria-label': 'Rechercher un utilisateur' });
  const roleFilter = h('select', { 'aria-label': 'Filtrer par rôle' }, h('option', { value: '' }, 'Tous les rôles'));
  const activeFilter = h('select', { 'aria-label': 'Filtrer par statut' },
    h('option', { value: '' }, 'Tous les statuts'),
    h('option', { value: '1' }, 'Actifs'),
    h('option', { value: '0' }, 'Désactivés'),
  );
  const list = h('div', { class: 'list-container' }, skeleton('row', 4));

  append(view, 
    pageHeader({ title: 'Utilisateurs', description: 'Un compte est créé à la première connexion, avec le rôle par défaut. Qui peut se connecter se règle dans Accès autorisés.' }),
    h('div', { class: 'toolbar' }, h('div', { class: 'toolbar-search' }, icon('search'), search), roleFilter, activeFilter),
    list,
  );

  let roles = [];
  try {
    roles = (await api('/roles')).roles;
  } catch (error) {
    return clear(list, errorState(error, () => location.reload()));
  }
  roleFilter.append(...roles.map((r) => h('option', { value: r.id }, r.name)));

  let timer;
  const load = async () => {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('q', search.value.trim());
    if (roleFilter.value) params.set('role_id', roleFilter.value);
    if (activeFilter.value) params.set('active', activeFilter.value);
    try {
      const { users } = await api(`/users?${params}`);
      clear(list, users.length ? h('div', { class: 'card card-flush' }, table(users, roles, session, load)) : h('div', { class: 'card' }, emptyState('Aucun utilisateur ne correspond à ces critères.', { iconName: 'users' })));
    } catch (error) {
      clear(list, errorState(error, load));
    }
  };
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
  });
  roleFilter.addEventListener('change', load);
  activeFilter.addEventListener('change', load);
  load();
}

function table(users, roles, session, reload) {
  return h('table', { class: 'data-table' },
    h('thead', {}, h('tr', {},
      h('th', {}, 'Utilisateur'), h('th', {}, 'Rôle'), h('th', {}, 'Statut'),
      h('th', {}, 'Dernière connexion'), h('th', {}, h('span', { class: 'sr-only' }, 'Actions')),
    )),
    h('tbody', {}, users.map((user) => row(user, roles, session, reload))),
  );
}

function row(user, roles, session, reload) {
  const isSelf = user.id === session.me.user.id;
  const roleSelect = h('select', { 'aria-label': `Rôle de ${user.email}` },
    roles.map((r) => h('option', { value: r.id, selected: r.id === user.role.id }, r.name)));

  // Changement immédiat, annulable depuis la notification (plutôt qu'une confirmation à chaque fois)
  const setRole = async (role, previous) => {
    try {
      await api(`/users/${user.id}`, { method: 'PATCH', body: { role_id: role.id } });
      return true;
    } catch (error) {
      roleSelect.value = previous.id;
      showError(error);
      return false;
    }
  };
  roleSelect.addEventListener('change', async () => {
    const previous = user.role;
    const role = roles.find((r) => r.id === Number(roleSelect.value));
    roleSelect.disabled = true;
    const ok = await setRole(role, previous);
    roleSelect.disabled = false;
    if (!ok) return;
    user.role = role;
    toast(`${user.display_name || user.email} a maintenant le rôle « ${role.name} ».`, 'success', {
      action: { label: 'Annuler le changement', run: async () => { if (await setRole(previous, role)) { toast('Rôle précédent rétabli.'); reload(); } } },
    });
  });

  const toggle = h('button', {
    class: ['btn', 'btn-small', user.is_active ? 'btn-danger-ghost' : ''],
    type: 'button',
    disabled: isSelf && user.is_active,
    title: isSelf ? 'Vous ne pouvez pas désactiver votre propre compte' : null,
    onclick: async () => {
      const activate = !user.is_active;
      const ok = await confirmDialog({
        title: activate ? 'Réactiver le compte' : 'Désactiver le compte',
        message: activate
          ? `${user.email} pourra de nouveau se connecter.`
          : `${user.email} sera immédiatement déconnecté et ne pourra plus se connecter.`,
        confirmLabel: activate ? 'Réactiver le compte' : 'Désactiver le compte',
        danger: !activate,
      });
      if (!ok) return;
      try {
        await api(`/users/${user.id}`, { method: 'PATCH', body: { is_active: activate } });
        toast(activate ? 'Compte réactivé.' : 'Compte désactivé.');
        reload();
      } catch (error) {
        showError(error);
      }
    },
  }, user.is_active ? 'Désactiver' : 'Réactiver');

  return h('tr', { class: !user.is_active && 'is-inactive' },
    h('td', { 'data-label': 'Utilisateur' },
      h('div', { class: 'cell-title' }, user.display_name || user.email, isSelf && h('span', { class: 'badge badge-accent' }, 'Vous')),
      user.display_name && h('span', { class: 'sub' }, user.email)),
    h('td', { 'data-label': 'Rôle' }, roleSelect),
    h('td', { 'data-label': 'Statut' },
      h('span', { class: ['status', user.is_active ? 'status-ok' : 'status-off'] }, user.is_active ? 'Actif' : 'Désactivé')),
    h('td', { 'data-label': 'Dernière connexion', class: 'num' }, user.last_login_at ? formatDate(user.last_login_at) : 'Jamais'),
    h('td', { class: 'cell-actions' }, toggle),
  );
}
