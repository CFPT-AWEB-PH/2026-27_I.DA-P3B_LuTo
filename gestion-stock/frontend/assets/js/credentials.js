// Fiches de connexion à imprimer et à découper. Les mots de passe n'existent en clair que le temps
// de cette fenêtre : le serveur n'en garde que l'empreinte, rien n'est stocké dans le navigateur.

import { h } from './dom.js';
import { icon } from './icons.js';
import { confirmDialog, openDialog, plural } from './ui.js';

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

/**
 * Affiche les comptes créés, avec impression de tout ou partie des fiches.
 * Avertit avant de fermer s'il reste des fiches jamais imprimées.
 */
export function credentialsDialog(rows, { title = 'Fiches de connexion', lead = null } = {}) {
  return openDialog(title, (close) => {
    const selected = new Set(rows);
    const printed = new Set();
    const several = rows.length > 1;

    const printLabel = h('span', {});
    const printButton = h('button', { class: 'btn btn-primary', type: 'button', onclick: () => {
      const chosen = rows.filter((r) => selected.has(r));
      chosen.forEach((r) => printed.add(r));
      printSlips(chosen);
    } }, icon('take'), printLabel);
    const all = h('input', { type: 'checkbox', 'aria-label': 'Sélectionner toutes les fiches' });
    const boxes = new Map();
    const sync = () => {
      printLabel.textContent = !several ? 'Imprimer la fiche' : selected.size === 0 ? 'Imprimer' : `Imprimer ${plural(selected.size, 'fiche')}`;
      printButton.disabled = selected.size === 0;
      all.checked = selected.size === rows.length;
      all.indeterminate = selected.size > 0 && selected.size < rows.length;
      for (const [row, box] of boxes) box.checked = selected.has(row);
    };
    all.addEventListener('change', () => {
      for (const row of rows) { if (all.checked) selected.add(row); else selected.delete(row); }
      sync();
    });
    const rowBox = (row) => {
      const box = h('input', { type: 'checkbox', 'aria-label': `Imprimer la fiche de ${row.display_name}`, onchange: () => {
        if (box.checked) selected.add(row); else selected.delete(row);
        sync();
      } });
      boxes.set(row, box);
      return box;
    };

    const body = h('div', { class: 'dialog-body' },
      lead && h('p', {}, lead),
      h('p', { class: 'alert alert-info', role: 'note' }, icon('info'), h('span', {}, 'Ces mots de passe ne s’affichent qu’ici et maintenant : ils ne sont conservés nulle part en clair. Imprimez-les avant de fermer. Plus tard, il faudra en générer de nouveaux.')),
      h('div', { class: 'table-scroll' }, h('table', { class: 'data-table credential-table' },
        h('thead', {}, h('tr', {}, several && h('th', { class: 'cell-check' }, all), h('th', {}, 'Personne'), h('th', {}, 'Groupe'), h('th', {}, 'Identifiant'), h('th', {}, 'Mot de passe'))),
        h('tbody', {}, rows.map((r) => h('tr', {}, several && h('td', { class: 'cell-check' }, rowBox(r)), h('td', {}, r.display_name), h('td', {}, r.group || '–'), h('td', { class: 'credential-value' }, r.username), h('td', { class: 'credential-value' }, r.password)))))),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: async () => {
          const left = rows.length - printed.size;
          if (left === 0 || await confirmDialog({
            title: printed.size ? 'Fermer sans tout imprimer ?' : 'Fermer sans imprimer ?',
            message: `${plural(left, 'fiche n’a pas été imprimée', 'fiches n’ont pas été imprimées')}. Ces mots de passe ne pourront plus être affichés : il faudra en générer de nouveaux.`,
            confirmLabel: 'Fermer quand même', cancelLabel: 'Revenir', danger: true,
          })) close();
        } }, 'Fermer'),
        printButton));
    sync();
    return body;
  }, { wide: true });
}
