import { api } from '../api.js';
import { clear, formatDate, h } from '../dom.js';
import { busy, confirmDialog, emptyState, field, showError, toast } from '../ui.js';

export function render(view) {
  const value = h('input', { name: 'value', required: true, maxlength: 254, placeholder: 'prenom.nom@eduge.ch ou eduge.ch', autocomplete: 'off' });
  const note = h('input', { name: 'note', maxlength: 255, placeholder: 'Facultatif' });
  const form = h('form', { class: 'card inline-form', novalidate: true },
    field('Adresse ou domaine', value, 'Un domaine autorise toutes ses adresses, mais pas ses sous-domaines.'),
    field('Note', note),
    h('button', { class: 'btn btn-primary', type: 'submit' }, 'Autoriser'),
  );
  const list = h('div', { class: 'list-container' }, h('p', { class: 'muted' }, 'Chargement…'));

  view.append(
    h('div', { class: 'page-header' },
      h('h1', {}, "Liste d'autorisation"),
      h('p', { class: 'muted' }, 'Seules les adresses et domaines listés ici peuvent se connecter. Retirer une entrée empêche les nouvelles connexions ; pour bloquer immédiatement un compte, désactivez-le.'),
    ),
    form,
    list,
  );

  const load = async () => {
    try {
      const { entries } = await api('/allowlist');
      clear(list, entries.length ? h('table', { class: 'data-table' },
        h('thead', {}, h('tr', {},
          h('th', {}, 'Entrée'), h('th', {}, 'Type'), h('th', {}, 'Note'), h('th', {}, 'Ajoutée'),
          h('th', {}, h('span', { class: 'sr-only' }, 'Actions')))),
        h('tbody', {}, entries.map((entry) => h('tr', {},
          h('td', { 'data-label': 'Entrée' }, h('span', { class: 'cell-title' }, entry.value)),
          h('td', { 'data-label': 'Type' }, entry.kind === 'domain' ? 'Domaine' : 'Adresse'),
          h('td', { 'data-label': 'Note' }, entry.note || '–'),
          h('td', { 'data-label': 'Ajoutée' }, formatDate(entry.created_at, false), entry.created_by && ` par ${entry.created_by}`),
          h('td', { class: 'cell-actions' }, h('button', {
            class: 'btn btn-small btn-danger-ghost', type: 'button',
            onclick: async () => {
              const ok = await confirmDialog({
                title: 'Retirer de la liste',
                message: `« ${entry.value} » ne pourra plus se connecter. Les sessions ouvertes restent actives jusqu'à leur expiration.`,
                confirmLabel: 'Retirer',
                danger: true,
              });
              if (!ok) return;
              try {
                await api(`/allowlist/${entry.id}`, { method: 'DELETE' });
                toast('Entrée retirée.');
                load();
              } catch (error) {
                showError(error);
              }
            },
          }, 'Retirer')),
        ))),
      ) : emptyState("La liste est vide : personne d'autre que l'administrateur initial ne peut se connecter."));
    } catch (error) {
      showError(error);
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      try {
        await api('/allowlist', { method: 'POST', body: { value: value.value, note: note.value } });
        toast('Entrée ajoutée.');
        form.reset();
        form.querySelectorAll('.field-error').forEach((el) => el.remove());
        load();
      } catch (error) {
        showError(error, form);
      }
    });
  });
  load();
}
