import { api } from '../api.js';
import { append, clear, formatDate, h } from '../dom.js';
import { busy, emptyState, errorState, field, pageHeader, skeleton, showError, toast } from '../ui.js';
import { icon } from '../icons.js';

export function render(view) {
  const value = h('input', { name: 'value', required: true, maxlength: 254, placeholder: 'prenom.nom@eduge.ch ou eduge.ch', autocomplete: 'off' });
  const note = h('input', { name: 'note', maxlength: 255, placeholder: 'Facultatif' });
  const form = h('form', { class: 'card inline-form', novalidate: true },
    field('Adresse ou domaine', value, 'Un domaine autorise toutes ses adresses, mais pas ses sous-domaines.'),
    field('Note', note),
    h('button', { class: 'btn btn-primary', type: 'submit' }, icon('plus'), h('span', {}, 'Autoriser')),
  );
  const list = h('div', { class: 'list-container' }, skeleton('row', 3));

  append(view, 
    pageHeader({ title: 'Accès autorisés', description: 'Seules les adresses et domaines listés ici peuvent se connecter. Retirer une entrée bloque les prochaines connexions ; pour couper un accès tout de suite, désactivez le compte.' }),
    form,
    list,
  );

  const load = async () => {
    try {
      const { entries } = await api('/allowlist');
      clear(list, entries.length ? h('div', { class: 'card card-flush' }, h('table', { class: 'data-table' },
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
            'aria-label': `Retirer ${entry.value}`,
            // Retrait immédiat, annulable : l'entrée peut être recréée à l'identique
            onclick: (event) => busy(event.currentTarget, async () => {
              try {
                await api(`/allowlist/${entry.id}`, { method: 'DELETE' });
              } catch (error) { return showError(error); }
              toast(`« ${entry.value} » ne pourra plus se connecter.`, 'success', {
                action: { label: 'Annuler', run: async () => {
                  try {
                    await api('/allowlist', { method: 'POST', body: { value: entry.value, note: entry.note ?? '' } });
                    toast(`Accès rétabli pour « ${entry.value} ».`);
                    load();
                  } catch (error) { showError(error); }
                } },
              });
              load();
            }),
          }, 'Retirer')),
        ))),
      )) : h('div', { class: 'card' }, emptyState("Ajoutez une adresse ou un domaine ci-dessus. En attendant, seul l'administrateur initial peut se connecter.", { title: 'Personne d’autre n’a accès', iconName: 'listCheck' })));
    } catch (error) {
      clear(list, errorState(error, load));
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      try {
        await api('/allowlist', { method: 'POST', body: { value: value.value, note: note.value } });
        toast(`« ${value.value.trim()} » peut désormais se connecter.`);
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
