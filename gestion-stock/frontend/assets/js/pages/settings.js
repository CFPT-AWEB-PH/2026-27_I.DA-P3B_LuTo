// Paramètres de l'application.

import { api } from '../api.js';
import { append, h } from '../dom.js';
import { busy, errorState, field, pageHeader, showError, toast } from '../ui.js';
import { loadSettings } from '../app.js';

export async function render(view) {
  let settings;
  try {
    settings = (await api('/settings')).settings;
  } catch (error) {
    return append(view, pageHeader({ title: 'Paramètres' }), errorState(error, () => location.reload()));
  }
  const colors = settings['fill.colors'] ?? { empty: '#9ca3af', partial: '#f59e0b', full: '#dc2626' };
  const name = h('input', { name: 'app.name', maxlength: 80, value: settings['app.name'] ?? 'Gestion de stock' });
  const empty = h('input', { type: 'color', name: 'empty', value: colors.empty });
  const partial = h('input', { type: 'color', name: 'partial', value: colors.partial });
  const full = h('input', { type: 'color', name: 'full', value: colors.full });

  const form = h('form', { class: 'card', novalidate: true },
    field('Nom de l\'application', name),
    h('fieldset', { class: 'form-section' }, h('legend', {}, 'Couleurs de remplissage dans la vue 3D'),
      h('p', { class: 'field-hint' }, 'Vide : aucun objet. Partiel : contient des objets. Plein : la capacité indiquée sur le rangement est atteinte.'),
      h('div', { class: 'form-grid form-grid-3' }, field('Vide', empty), field('Partiellement rempli', partial), field('Plein', full))),
    h('div', { class: 'dialog-actions' }, h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Enregistrer les paramètres'))));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      try {
        await api('/settings', { method: 'PATCH', body: { 'app.name': name.value, 'fill.colors': { empty: empty.value, partial: partial.value, full: full.value } } });
        await loadSettings();
        toast('Paramètres enregistrés.');
      } catch (error) { showError(error, form); }
    });
  });

  append(view, pageHeader({ title: 'Paramètres', description: 'Nom affiché et couleurs communes à tous les utilisateurs.' }), form);
}
