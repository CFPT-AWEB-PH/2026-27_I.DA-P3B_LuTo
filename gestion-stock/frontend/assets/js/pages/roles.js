import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { busy, checkbox, confirmDialog, errorState, field, menuButton, openDialog, pageHeader, plural, showError, skeleton, toast } from '../ui.js';
import { icon } from '../icons.js';

export async function render(view) {
  const list = h('div', { class: 'role-grid' }, skeleton('card', 3));
  const addButton = h('button', { class: 'btn btn-primary', type: 'button' }, icon('plus'), 'Nouveau rôle');

  append(view, 
    pageHeader({ title: 'Rôles et permissions', description: 'Le rôle par défaut est donné à chaque nouvel utilisateur. Le rôle administrateur a toujours toutes les permissions.', actions: addButton }),
    list,
  );

  let permissions;
  try {
    permissions = (await api('/permissions')).permissions;
  } catch (error) {
    return clear(list, errorState(error, () => location.reload()));
  }

  const load = async () => {
    try {
      const { roles } = await api('/roles');
      clear(list, roles.map((role) => card(role, permissions, load)));
    } catch (error) {
      clear(list, errorState(error, load));
    }
  };
  addButton.addEventListener('click', () => editRole(null, permissions).then((saved) => saved && load()));
  load();
}

function card(role, permissions, reload) {
  // Permissions regroupées comme dans la fenêtre de modification : « Stock 4/6 » puis le détail
  const categories = [...new Set(permissions.map((p) => p.category))];
  const groups = categories.map((category) => {
    const all = permissions.filter((p) => p.category === category);
    const granted = all.filter((p) => role.permissions.includes(p.code));
    return { category, all, granted };
  }).filter((g) => g.granted.length > 0);

  const makeDefault = async () => {
    const ok = await confirmDialog({
      title: 'Rôle par défaut',
      message: `Les nouveaux utilisateurs recevront le rôle « ${role.name} ».`,
      confirmLabel: 'Utiliser par défaut',
    });
    if (!ok) return;
    try {
      await api(`/roles/${role.id}`, { method: 'PATCH', body: { is_default: true } });
      toast('Rôle par défaut modifié.');
      reload();
    } catch (error) {
      showError(error);
    }
  };
  const remove = async () => {
    const ok = await confirmDialog({
      title: 'Supprimer le rôle',
      message: role.user_count
        ? `${plural(role.user_count, 'utilisateur a', 'utilisateurs ont')} ce rôle : le serveur refusera tant qu'ils l'ont. Changez-leur d'abord de rôle.`
        : `Le rôle « ${role.name} » sera supprimé définitivement.`,
      confirmLabel: 'Supprimer le rôle',
      danger: true,
    });
    if (!ok) return;
    try {
      await api(`/roles/${role.id}`, { method: 'DELETE' });
      toast('Rôle supprimé.');
      reload();
    } catch (error) {
      showError(error);
    }
  };
  const more = [
    !role.is_default && { label: 'Utiliser par défaut', icon: 'check', onSelect: makeDefault },
    !role.is_protected && !role.is_default && { label: 'Supprimer le rôle…', icon: 'trash', danger: true, onSelect: remove },
  ].filter(Boolean);

  const actions = h('div', { class: 'card-actions' },
    h('button', { class: 'btn btn-small', type: 'button', onclick: () => editRole(role, permissions).then((saved) => saved && reload()) },
      icon('edit'), role.is_protected ? 'Renommer' : 'Modifier les permissions'),
    more.length > 0 && menuButton({ label: `Autres actions pour ${role.name}`, items: more }));

  return h('article', { class: ['card', 'role-card', role.is_protected && 'is-protected'] },
    h('div', { class: 'role-card-header' },
      h('h2', {}, role.name),
      role.is_protected && h('span', { class: 'badge badge-accent' }, 'Protégé'),
      role.is_default && h('span', { class: 'badge' }, 'Par défaut'),
    ),
    role.description && h('p', { class: 'role-description' }, role.description),
    h('p', { class: 'role-count' },
      h('a', { href: `/admin/users?role_id=${role.id}` }, plural(role.user_count, 'utilisateur')),
      ` · ${role.permissions.length} / ${permissions.length} permissions`),
    role.is_protected
      ? h('p', { class: 'role-all' }, icon('check'), 'Toutes les permissions, toujours')
      : groups.length
        ? h('dl', { class: 'permission-groups' }, groups.map((g) => [
          h('dt', {}, g.category, h('span', { class: 'count' }, `${g.granted.length}/${g.all.length}`)),
          h('dd', {}, h('ul', { class: 'permission-summary' }, g.granted.map((p) => h('li', {}, p.label)))),
        ]))
        : h('p', { class: 'muted small' }, 'Aucune permission : ce rôle ne peut rien consulter.'),
    actions,
  );
}

function editRole(role, permissions) {
  const isProtected = role?.is_protected ?? false;
  return openDialog(role ? `Modifier « ${role.name} »` : 'Nouveau rôle', (close) => {
    const name = h('input', { name: 'name', required: true, maxlength: 80, value: role?.name ?? '' });
    const description = h('input', { name: 'description', maxlength: 255, value: role?.description ?? '' });
    const categories = [...new Set(permissions.map((p) => p.category))];

    const form = h('form', { class: 'dialog-body', novalidate: true },
      field('Nom', name),
      field('Description', description),
      h('fieldset', { class: 'permission-fieldset', name: 'permissions', disabled: isProtected },
        h('legend', {}, 'Permissions'),
        isProtected && h('p', { class: 'field-hint' }, 'Le rôle administrateur possède toujours toutes les permissions.'),
        categories.map((category) => h('div', { class: 'permission-group' },
          h('h3', {}, category),
          permissions.filter((p) => p.category === category).map((p) => checkbox(
            h('input', {
              type: 'checkbox', value: p.code, 'data-permission': '',
              checked: isProtected || (role?.permissions.includes(p.code) ?? false),
            }),
            p.label)),
        )),
      ),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, role ? 'Enregistrer le rôle' : 'Créer le rôle')),
      ),
    );

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const body = { name: name.value, description: description.value };
      if (!isProtected) {
        body.permissions = [...form.querySelectorAll('[data-permission]:checked')].map((el) => el.value);
      }
      busy(event.submitter, async () => {
        try {
          await api(role ? `/roles/${role.id}` : '/roles', { method: role ? 'PATCH' : 'POST', body });
          toast(role ? 'Rôle enregistré.' : 'Rôle créé.');
          close(true);
        } catch (error) {
          showError(error, form);
        }
      });
    });
    return form;
  }, { wide: true });
}
