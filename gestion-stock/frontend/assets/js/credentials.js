// Fiches de connexion à imprimer et à découper. Les mots de passe n'existent en clair que le temps
// de cette fenêtre : le serveur n'en garde que l'empreinte, rien n'est stocké dans le navigateur.

import { h } from './dom.js';
import { icon } from './icons.js';
import { confirmDialog, openDialog } from './ui.js';

function slip(row) {
  return h('article', { class: 'credential-slip' },
    h('header', {}, h('strong', {}, row.display_name), row.group && h('span', {}, row.group)),
    h('dl', {},
      h('dt', {}, 'Adresse'), h('dd', {}, `${location.host}/login`),
      h('dt', {}, 'Identifiant'), h('dd', { class: 'credential-value' }, row.username),
      h('dt', {}, 'Mot de passe'), h('dd', { class: 'credential-value' }, row.password)),
    h('p', {}, 'Personnel : ne le partage pas. Mot de passe perdu ? Demande-en un nouveau à la personne qui gère les comptes.'));
}

/** Imprime uniquement les fiches : la feuille est retirée du document aussitôt après. */
function printSlips(rows) {
  const sheet = h('div', { class: 'credential-sheet' }, rows.map(slip));
  document.body.append(sheet);
  document.body.classList.add('is-printing-credentials');
  const done = () => {
    document.body.classList.remove('is-printing-credentials');
    sheet.remove();
    window.removeEventListener('afterprint', done);
  };
  window.addEventListener('afterprint', done);
  window.print();
}

/** Affiche les comptes créés, avec impression. Avertit avant de fermer si rien n'a été imprimé. */
export function credentialsDialog(rows, { title = 'Fiches de connexion', lead = null } = {}) {
  return openDialog(title, (close) => {
    let printed = false;
    return h('div', { class: 'dialog-body' },
      lead && h('p', {}, lead),
      h('p', { class: 'alert alert-info', role: 'note' }, icon('info'), h('span', {}, 'Ces mots de passe ne s’affichent qu’ici et maintenant : ils ne sont conservés nulle part en clair. Imprimez-les avant de fermer. Plus tard, il faudra en générer de nouveaux.')),
      h('div', { class: 'table-scroll' }, h('table', { class: 'data-table credential-table' },
        h('thead', {}, h('tr', {}, h('th', {}, 'Personne'), h('th', {}, 'Groupe'), h('th', {}, 'Identifiant'), h('th', {}, 'Mot de passe'))),
        h('tbody', {}, rows.map((r) => h('tr', {}, h('td', {}, r.display_name), h('td', {}, r.group || '–'), h('td', { class: 'credential-value' }, r.username), h('td', { class: 'credential-value' }, r.password)))))),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: async () => {
          if (printed || await confirmDialog({ title: 'Fermer sans imprimer ?', message: 'Ces mots de passe ne pourront plus être affichés. Il faudra en générer de nouveaux.', confirmLabel: 'Fermer quand même', cancelLabel: 'Revenir', danger: true })) close();
        } }, 'Fermer'),
        h('button', { class: 'btn btn-primary', type: 'button', onclick: () => { printed = true; printSlips(rows); } }, icon('take'), rows.length > 1 ? `Imprimer les ${rows.length} fiches` : 'Imprimer la fiche')));
  }, { wide: true });
}
