import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { busy, confirmDialog, emptyState, errorState, field, openDialog, pageHeader, plural, skeleton, showError, toast } from '../ui.js';
import { icon } from '../icons.js';
import { credentialsDialog } from '../credentials.js';
import { peopleFromRows, readSpreadsheet } from '../spreadsheet.js';

export async function render(view, session) {
  const search = h('input', { type: 'search', placeholder: 'Nom ou adresse e-mail…', 'aria-label': 'Rechercher un utilisateur' });
  const roleFilter = h('select', { 'aria-label': 'Filtrer par rôle' }, h('option', { value: '' }, 'Tous les rôles'));
  const activeFilter = h('select', { 'aria-label': 'Filtrer par statut' },
    h('option', { value: '' }, 'Tous les statuts'),
    h('option', { value: '1' }, 'Actifs'),
    h('option', { value: '0' }, 'Désactivés'),
  );
  const groupFilter = h('select', { 'aria-label': 'Filtrer par groupe' }, h('option', { value: '' }, 'Tous les groupes'));
  const list = h('div', { class: 'list-container' }, skeleton('row', 4));

  append(view, 
    pageHeader({
      title: 'Utilisateurs',
      description: 'Importez une liste de classe : chaque personne reçoit un identifiant et un mot de passe, sur une fiche à imprimer. Les comptes par adresse e-mail se règlent dans Accès autorisés.',
      actions: h('button', { class: 'btn btn-primary', type: 'button', onclick: () => importDialog(roles, load) }, icon('take'), 'Importer une liste Excel'),
    }),
    h('div', { class: 'toolbar' }, h('div', { class: 'toolbar-search' }, icon('search'), search), roleFilter, groupFilter, activeFilter),
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
    if (groupFilter.value) params.set('group', groupFilter.value);
    if (search.value.trim()) params.set('q', search.value.trim());
    if (roleFilter.value) params.set('role_id', roleFilter.value);
    if (activeFilter.value) params.set('active', activeFilter.value);
    try {
      const { users, groups } = await api(`/users?${params}`);
      const selected = groupFilter.value;
      clear(groupFilter, h('option', { value: '' }, 'Tous les groupes'), groups.map((g) => h('option', { value: g, selected: g === selected }, g)));
      groupFilter.hidden = groups.length === 0;
      const withPassword = users.filter((u) => u.username && u.is_active);
      clear(list,
        withPassword.length > 0 && h('div', { class: 'list-actions' },
          h('button', { class: 'btn btn-small', type: 'button', onclick: (e) => busy(e.currentTarget, () => regenerate(withPassword, groupFilter.value)) }, icon('refresh'), `Nouvelles fiches pour ${withPassword.length > 1 ? `ces ${withPassword.length} comptes` : 'ce compte'}`)),
        users.length ? h('div', { class: 'card card-flush' }, table(users, roles, session, load)) : h('div', { class: 'card' }, emptyState('Aucun utilisateur ne correspond à ces critères.', { iconName: 'users' })));
    } catch (error) {
      clear(list, errorState(error, load));
    }
  };
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
  });
  roleFilter.addEventListener('change', load);
  groupFilter.addEventListener('change', load);
  activeFilter.addEventListener('change', load);
  load();
}

/** Lit le fichier, montre ce qui sera créé, puis crée les comptes par petits lots avec leur fiche. */
function importDialog(roles, reload) {
  const assignable = roles.filter((r) => !r.is_protected);
  return openDialog('Importer une liste Excel', (close) => {
    const fileInput = h('input', { type: 'file', accept: '.xls,.xlsx,.csv', 'aria-label': 'Fichier à importer' });
    const role = h('select', {}, assignable.map((r) => h('option', { value: r.id, selected: r.is_default }, r.name)));
    const preview = h('div', {});
    const submit = h('button', { class: 'btn btn-primary', type: 'submit', disabled: true }, h('span', {}, 'Créer les comptes'));
    let people = [];
    fileInput.addEventListener('change', async () => {
      people = [];
      submit.disabled = true;
      const file = fileInput.files[0];
      if (!file) return clear(preview);
      try {
        const result = peopleFromRows(await readSpreadsheet(await file.arrayBuffer(), file.name));
        if (result.error) return clear(preview, h('p', { class: 'alert alert-error', role: 'alert' }, icon('alertCircle'), h('span', {}, result.error)));
        people = result.people;
        submit.disabled = false;
        submit.firstChild.textContent = `Créer ${plural(people.length, 'compte')}`;
        return clear(preview,
          h('div', { class: 'table-scroll import-preview' }, h('table', { class: 'data-table' },
            h('thead', {}, h('tr', {}, h('th', {}, 'Nom'), h('th', {}, 'Prénom'), h('th', {}, 'Groupe'))),
            h('tbody', {}, people.map((p) => h('tr', {}, h('td', {}, p.last_name), h('td', {}, p.first_name), h('td', {}, p.group || '–')))))),
          result.ignored.length > 0 && h('p', { class: 'field-hint' }, `Non repris, jamais envoyé au serveur : ${result.ignored.join(', ')}.`),
          h('p', { class: 'field-hint' }, 'Une personne déjà importée est ignorée : son compte et son mot de passe ne changent pas.'));
      } catch (error) {
        return clear(preview, h('p', { class: 'alert alert-error', role: 'alert' }, icon('alertCircle'), h('span', {}, error.message ?? 'Fichier illisible.')));
      }
    });
    const form = h('form', { class: 'dialog-body', novalidate: true },
      field('Fichier (.xls, .xlsx ou .csv)', fileInput, 'Il faut au moins les colonnes « Nom » et « Prénom ». Le fichier est lu dans votre navigateur.'),
      field('Rôle des nouveaux comptes', role),
      preview,
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Annuler'), submit));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      busy(event.submitter, async () => {
        const created = [];
        let skipped = 0;
        try {
          for (let i = 0; i < people.length; i += 25) {
            const r = await api('/users/import', { method: 'POST', body: { role_id: Number(role.value), people: people.slice(i, i + 25) } });
            created.push(...r.created);
            skipped += r.skipped.length;
          }
        } catch (error) {
          // Un lot a échoué : les comptes déjà créés ont un mot de passe qu'il faut quand même montrer
          showError(error, form);
          if (created.length === 0) return;
        }
        close();
        await reload();
        if (created.length) await credentialsDialog(created, { title: 'Comptes créés', lead: `${plural(created.length, 'compte créé', 'comptes créés')}${skipped ? `, ${plural(skipped, 'personne déjà importée ignorée', 'personnes déjà importées ignorées')}` : ''}.` });
        else toast(`${plural(skipped, 'personne déjà importée', 'personnes déjà importées')} : aucun compte créé.`, 'info');
      });
    });
    return form;
  }, { wide: true });
}

/** Nouveau mot de passe d'un compte : généré, ou choisi par la personne qui gère les comptes. */
function passwordDialog(user) {
  return openDialog(`Mot de passe de ${user.display_name || user.username}`, (close) => {
    const input = h('input', { name: 'password', type: 'text', minlength: 8, maxlength: 200, autocomplete: 'off', placeholder: 'Laisser vide pour en générer un' });
    const form = h('form', { class: 'dialog-body', novalidate: true },
      h('p', {}, `Identifiant : `, h('strong', {}, user.username)),
      field('Nouveau mot de passe', input, '8 caractères au moins. L’ancien cesse aussitôt de fonctionner et ses sessions ouvertes sont fermées.'),
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Annuler'), h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Changer le mot de passe'))));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      busy(event.submitter, async () => {
        try {
          const result = await api(`/users/${user.id}/password`, { method: 'POST', body: input.value ? { password: input.value } : {} });
          close();
          await credentialsDialog([result], { title: 'Nouvelle fiche de connexion' });
        } catch (error) { showError(error, form); }
      });
    });
    return form;
  });
}

/** Nouvelles fiches pour toute une liste : l'ancien mot de passe de chacun cesse de fonctionner. */
async function regenerate(users, group) {
  const ok = await confirmDialog({
    title: 'Générer de nouveaux mots de passe',
    message: `${plural(users.length, 'compte')}${group ? ` du groupe ${group}` : ''} : les mots de passe actuels cesseront aussitôt de fonctionner, et il faudra redistribuer les fiches.`,
    confirmLabel: 'Générer et imprimer', danger: true,
  });
  if (!ok) return;
  const rows = [];
  try {
    for (const user of users) rows.push(await api(`/users/${user.id}/password`, { method: 'POST', body: {} }));
  } catch (error) { showError(error); }
  if (rows.length) await credentialsDialog(rows, { title: 'Nouvelles fiches de connexion' });
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
  const who = user.display_name || user.username || user.email;
  const roleSelect = h('select', { 'aria-label': `Rôle de ${who}` },
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
    toast(`${who} a maintenant le rôle « ${role.name} ».`, 'success', {
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
          ? `${who} pourra de nouveau se connecter.`
          : `${who} sera immédiatement déconnecté et ne pourra plus se connecter.`,
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

  const passwordButton = user.username && h('button', { class: 'btn btn-small', type: 'button', title: 'Choisir ou générer un nouveau mot de passe', onclick: () => passwordDialog(user) }, 'Mot de passe');

  return h('tr', { class: !user.is_active && 'is-inactive' },
    h('td', { 'data-label': 'Utilisateur' },
      h('div', { class: 'cell-title' }, user.display_name || user.email, isSelf && h('span', { class: 'badge badge-accent' }, 'Vous')),
      user.display_name && h('span', { class: 'sub' }, [user.username ?? user.email, user.group].filter(Boolean).join(' · '))),
    h('td', { 'data-label': 'Rôle' }, roleSelect),
    h('td', { 'data-label': 'Statut' },
      h('span', { class: ['status', user.is_active ? 'status-ok' : 'status-off'] }, user.is_active ? 'Actif' : 'Désactivé')),
    h('td', { 'data-label': 'Dernière connexion', class: 'num' }, user.last_login_at ? formatDate(user.last_login_at) : 'Jamais'),
    h('td', { class: 'cell-actions' }, passwordButton, toggle),
  );
}
