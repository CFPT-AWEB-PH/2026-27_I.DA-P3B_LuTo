import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { confirmDialog, emptyState, showError, toast } from '../ui.js';

export async function render(view, session) {
  const search = h('input', { type: 'search', placeholder: 'Nom ou adresse…', 'aria-label': 'Rechercher un utilisateur' });
  const roleFilter = h('select', { 'aria-label': 'Filtrer par rôle' }, h('option', { value: '' }, 'Tous les rôles'));
  const activeFilter = h('select', { 'aria-label': 'Filtrer par statut' },
    h('option', { value: '' }, 'Tous les statuts'),
    h('option', { value: '1' }, 'Actifs'),
    h('option', { value: '0' }, 'Désactivés'),
  );
  const list = h('div', { class: 'list-container' }, h('p', { class: 'muted' }, 'Chargement…'));

  view.append(
    h('div', { class: 'page-header' },
      h('h1', {}, 'Utilisateurs'),
      h('p', { class: 'muted' }, "Les comptes sont créés à la première connexion, avec le rôle par défaut. L'accès se règle dans la liste d'autorisation."),
    ),
    h('div', { class: 'toolbar' }, search, roleFilter, activeFilter),
    list,
  );

  let roles = [];
  try {
    roles = (await api('/roles')).roles;
  } catch (error) {
    return showError(error);
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
      clear(list, users.length ? table(users, roles, session, load) : emptyState('Aucun utilisateur ne correspond.'));
    } catch (error) {
      showError(error);
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

  roleSelect.addEventListener('change', async () => {
    const role = roles.find((r) => r.id === Number(roleSelect.value));
    const ok = await confirmDialog({
      title: 'Changer le rôle',
      message: `Attribuer le rôle « ${role.name} » à ${user.display_name || user.email} ?`,
      confirmLabel: 'Changer le rôle',
    });
    if (!ok) {
      roleSelect.value = user.role.id;
      return;
    }
    try {
      await api(`/users/${user.id}`, { method: 'PATCH', body: { role_id: role.id } });
      toast('Rôle modifié.');
      reload();
    } catch (error) {
      roleSelect.value = user.role.id;
      showError(error);
    }
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
        confirmLabel: activate ? 'Réactiver' : 'Désactiver',
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
      h('div', { class: 'cell-title' }, user.display_name || '–', isSelf && h('span', { class: 'badge' }, 'vous')),
      h('div', { class: 'muted small' }, user.email)),
    h('td', { 'data-label': 'Rôle' }, roleSelect),
    h('td', { 'data-label': 'Statut' },
      h('span', { class: ['status', user.is_active ? 'status-ok' : 'status-off'] }, user.is_active ? 'Actif' : 'Désactivé')),
    h('td', { 'data-label': 'Dernière connexion' }, formatDate(user.last_login_at)),
    h('td', { class: 'cell-actions' }, toggle),
  );
}
