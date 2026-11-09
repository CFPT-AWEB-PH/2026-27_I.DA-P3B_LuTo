// Paramètres de l'application : nom affiché et couleurs de remplissage de la vue 3D.

import { api } from '../api.js';
import { append, h } from '../dom.js';
import { busy, errorState, field, pageHeader, showError, toast } from '../ui.js';
import { icon } from '../icons.js';
import { loadSettings } from '../app.js';

const DEFAULT_COLORS = { empty: '#9ca3af', partial: '#f59e0b', full: '#dc2626' };
const FILLS = [
  ['empty', 'Vide', 'Aucun objet rangé.'],
  ['partial', 'Partiellement rempli', 'Contient des objets.'],
  ['full', 'Plein', 'La capacité indiquée sur le rangement est atteinte.'],
];

export async function render(view) {
  let settings;
  try {
    settings = (await api('/settings')).settings;
  } catch (error) {
    return append(view, pageHeader({ title: 'Paramètres' }), errorState(error, () => location.reload()));
  }
  const saved = { name: settings['app.name'] ?? 'Gestion de stock', colors: { ...DEFAULT_COLORS, ...(settings['fill.colors'] ?? {}) } };
  const name = h('input', { name: 'app.name', maxlength: 80, required: true, value: saved.name, autocomplete: 'off' });

  // Chaque couleur : pastille cliquable (sélecteur du système) et sa valeur, avec un aperçu de rangement
  const inputs = {};
  const swatches = FILLS.map(([key, label, hint]) => {
    const input = h('input', { type: 'color', name: key, value: saved.colors[key], 'aria-describedby': `fill-${key}-hint` });
    const value = h('code', { class: 'color-value' }, saved.colors[key]);
    const sample = h('span', { class: 'fill-sample', 'aria-hidden': 'true' });
    const paint = () => { value.textContent = input.value; sample.style.background = input.value; };
    input.addEventListener('input', paint);
    paint();
    inputs[key] = input;
    return h('label', { class: 'fill-choice' },
      sample,
      h('span', { class: 'fill-choice-text' }, h('strong', {}, label), h('span', { id: `fill-${key}-hint`, class: 'sub' }, hint)),
      h('span', { class: 'fill-choice-picker' }, value, input));
  });

  const reset = h('button', { class: 'btn btn-small btn-ghost', type: 'button', onclick: () => {
    for (const [key, input] of Object.entries(inputs)) { input.value = DEFAULT_COLORS[key]; input.dispatchEvent(new Event('input', { bubbles: true })); }
  } }, icon('undo'), 'Couleurs d’origine');
  const submit = h('button', { class: 'btn btn-primary', type: 'submit', disabled: true }, h('span', {}, 'Enregistrer'));
  const status = h('span', { class: 'form-status', 'aria-live': 'polite' });

  const current = () => ({ name: name.value.trim(), colors: Object.fromEntries(Object.entries(inputs).map(([k, i]) => [k, i.value])) });
  const dirty = () => JSON.stringify(current()) !== JSON.stringify(saved);
  // Bouton actif seulement s'il y a quelque chose à enregistrer ; « Couleurs d'origine » seulement si elles ont changé
  const sync = () => {
    submit.disabled = !dirty();
    status.textContent = dirty() ? 'Modifications non enregistrées' : '';
    reset.hidden = Object.entries(inputs).every(([k, i]) => i.value.toLowerCase() === DEFAULT_COLORS[k]);
  };

  const form = h('form', { class: 'settings-form', novalidate: true },
    h('section', { class: 'card settings-section', 'aria-labelledby': 'settings-general' },
      h('h2', { id: 'settings-general' }, 'Général'),
      field('Nom de l’application', name, 'Affiché dans le menu, l’onglet du navigateur et l’application installée.')),
    h('section', { class: 'card settings-section', 'aria-labelledby': 'settings-fill' },
      h('div', { class: 'section-header' }, h('h2', { id: 'settings-fill' }, 'Couleurs de remplissage'), reset),
      h('p', { class: 'field-hint section-hint' }, 'Dans la vue 3D d’une salle, « Couleurs de remplissage » colore chaque rangement selon ce qu’il contient.'),
      h('div', { class: 'fill-choices' }, swatches)),
    h('div', { class: 'settings-actions' }, status, submit));

  form.addEventListener('input', sync);
  sync();
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!name.value.trim()) return showError(Object.assign(new Error('Indiquez un nom.'), { fields: { 'app.name': 'Indiquez un nom.' } }), form);
    busy(event.submitter, async () => {
      try {
        const next = current();
        await api('/settings', { method: 'PATCH', body: { 'app.name': next.name, 'fill.colors': next.colors } });
        saved.name = next.name;
        saved.colors = next.colors;
        await loadSettings();
        toast('Paramètres enregistrés.');
      } catch (error) { showError(error, form); }
    }).then(sync);
  });

  append(view, pageHeader({ title: 'Paramètres', description: 'Nom affiché et couleurs communes à tous les utilisateurs.' }), form);
}
