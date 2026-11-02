import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { busy, confirmDialog, field, openDialog, showError, toast } from '../ui.js';

export async function render(view) {
  const list = h('div', { class: 'role-grid' }, h('p', { class: 'muted' }, 'Chargement…'));
  const addButton = h('button', { class: 'btn btn-primary', type: 'button' }, 'Nouveau rôle');

  view.append(
    h('div', { class: 'page-header page-header-actions' },
      h('div', {},
        h('h1', {}, 'Rôles et permissions'),
        h('p', { class: 'muted' }, 'Le rôle par défaut est attribué à chaque nouvel utilisateur. Le rôle administrateur possède toujours toutes les permissions.'),
      ),
      addButton,
    ),
    list,
  );

  let permissions;
  try {
    permissions = (await api('/permissions')).permissions;
  } catch (error) {
    return showError(error);
  }

  const load = async () => {
    try {
      const { roles } = await api('/roles');
      clear(list, roles.map((role) => card(role, permissions, load)));
    } catch (error) {
      showError(error);
    }
  };
  addButton.addEventListener('click', () => editRole(null, permissions).then((saved) => saved && load()));
  load();
}

function card(role, permissions, reload) {
  const labels = permissions.filter((p) => role.permissions.includes(p.code)).map((p) => p.label);

  const actions = h('div', { class: 'card-actions' },
    h('button', {
      class: 'btn btn-small', type: 'button',
      onclick: () => editRole(role, permissions).then((saved) => saved && reload()),
    }, 'Modifier'),
    !role.is_default && h('button', {
      class: 'btn btn-small', type: 'button',
      onclick: async () => {
        const ok = await confirmDialog({
          title: 'Rôle par défaut',
          message: `Les nouveaux utilisateurs recevront le rôle « ${role.name} ».`,
          confirmLabel: 'Définir par défaut',
        });
        if (!ok) return;
        try {
          await api(`/roles/${role.id}`, { method: 'PATCH', body: { is_default: true } });
          toast('Rôle par défaut modifié.');
          reload();
        } catch (error) {
          showError(error);
        }
      },
    }, 'Définir par défaut'),
    !role.is_protected && !role.is_default && h('button', {
      class: 'btn btn-small btn-danger-ghost', type: 'button',
      onclick: async () => {
        const ok = await confirmDialog({
          title: 'Supprimer le rôle',
          message: `Supprimer définitivement le rôle « ${role.name} » ?`,
          confirmLabel: 'Supprimer',
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
      },
    }, 'Supprimer'),
  );

  return h('article', { class: 'card role-card' },
    h('div', { class: 'role-card-header' },
      h('h2', {}, role.name),
      role.is_protected && h('span', { class: 'badge badge-accent' }, 'Protégé'),
      role.is_default && h('span', { class: 'badge' }, 'Par défaut'),
    ),
    role.description && h('p', { class: 'muted' }, role.description),
    h('p', { class: 'small' }, `${role.user_count} utilisateur(s) · ${role.permissions.length} permission(s)`),
    h('ul', { class: 'permission-summary' }, labels.map((label) => h('li', {}, label))),
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
          permissions.filter((p) => p.category === category).map((p) => h('label', { class: 'checkbox' },
            h('input', {
              type: 'checkbox', value: p.code, 'data-permission': '',
              checked: isProtected || (role?.permissions.includes(p.code) ?? false),
            }),
            p.label)),
        )),
      ),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, 'Enregistrer'),
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
