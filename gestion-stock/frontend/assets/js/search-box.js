// Recherche d'objets avec suggestions pendant la saisie (serveur), Entrée pour la page de résultats.
// Deux présentations : « bar » dans la barre supérieure (repliée derrière une loupe sur mobile) et
// « hero » en tête de l'accueil. Raccourci global : « / » ou Ctrl+K. Navigation au clavier dans les suggestions.

import { api } from './api.js';
import { h } from './dom.js';
import { icon } from './icons.js';
import { formatQuantity } from './stock-dialogs.js';

const collapse = () => document.body.classList.remove('search-open');

/** Met en évidence le texte saisi dans un nom (nœuds texte uniquement, jamais de HTML). */
function markMatch(text, query) {
  const fold = (value) => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  // La décomposition NFD peut allonger la chaîne : on ne surligne que si les longueurs correspondent
  const haystack = fold(text);
  const index = haystack.length === text.length ? haystack.indexOf(fold(query)) : -1;
  if (index < 0 || !query) return text;
  return [text.slice(0, index), h('mark', { class: 'hl' }, text.slice(index, index + query.length)), text.slice(index + query.length)];
}

export function searchBox(navigate, { id = 'global', variant = 'bar', placeholder = 'Rechercher un objet, une référence…' } = {}) {
  const bar = variant === 'bar';
  const listId = `${id}-suggestions`;
  const input = h('input', {
    type: 'search', class: ['search-input', bar ? 'global-search-input' : 'home-search-input'], placeholder, autocomplete: 'off',
    role: 'combobox', 'aria-expanded': 'false', 'aria-controls': listId, 'aria-autocomplete': 'list',
    'aria-label': bar ? 'Recherche globale' : 'Rechercher un objet', enterkeyhint: 'search',
  });
  const list = h('ul', { id: listId, class: 'suggestions', role: 'listbox', 'aria-label': 'Suggestions', hidden: true });
  const form = h('form', { class: bar ? 'global-search' : 'home-search', role: 'search' },
    h('div', { class: 'search-field' }, icon('search'), input, bar && h('kbd', { class: 'kbd', 'aria-hidden': 'true' }, '/')),
    bar && h('button', { class: 'icon-btn search-close', type: 'button', 'aria-label': 'Fermer la recherche', onclick: () => { close(); collapse(); } }, icon('x')),
    list);
  let active = -1;
  let items = [];
  let timer;
  let sequence = 0;

  const close = () => {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
  };
  const highlight = (index) => {
    active = index;
    [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === index)));
    const current = list.children[index];
    if (current?.id) input.setAttribute('aria-activedescendant', current.id);
    current?.scrollIntoView({ block: 'nearest' });
  };
  const open = (path) => {
    close();
    input.blur();
    collapse();
    navigate(path);
  };
  const show = (children) => {
    list.replaceChildren(...children);
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    active = -1;
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) return close();
    timer = setTimeout(async () => {
      const current = ++sequence;
      try {
        const { suggestions } = await api(`/items/suggest?q=${encodeURIComponent(q)}`);
        if (current !== sequence) return;
        items = suggestions;
        const all = h('li', { role: 'option', id: `${id}-all`, class: 'suggestion-all', onmousedown: (e) => { e.preventDefault(); open(`/items?q=${encodeURIComponent(q)}`); } },
          icon('search'), `Voir tous les résultats pour « ${q} »`);
        show(suggestions.length
          ? [...suggestions.map((s, i) => h('li', {
            role: 'option', id: `${id}-opt-${i}`, 'aria-selected': 'false',
            onmousedown: (e) => { e.preventDefault(); open(`/items/${s.id}`); },
          },
            h('div', { class: 'suggestion-name' }, h('span', {}, markMatch(s.name, q)), h('span', { class: 'suggestion-qty' }, formatQuantity(s.total_quantity, s.unit))),
            h('div', { class: 'suggestion-meta' }, [s.reference, s.location].filter(Boolean).join('  ·  ')))), all]
          : [h('li', { class: 'suggestion-empty', role: 'option', 'aria-disabled': 'true' }, `Aucun objet ne correspond à « ${q} ».`), all]);
      } catch {
        close();
      }
    }, 180);
  });

  input.addEventListener('keydown', (event) => {
    const count = list.children.length;
    if (event.key === 'ArrowDown' && !list.hidden) { event.preventDefault(); highlight((active + 1) % count); }
    else if (event.key === 'ArrowUp' && !list.hidden) { event.preventDefault(); highlight((active - 1 + count) % count); }
    else if (event.key === 'Escape') {
      event.preventDefault();
      // Premier Échap : ferme les suggestions ; second : quitte le champ
      if (!list.hidden) return close();
      input.blur();
      collapse();
    }
  });
  input.addEventListener('blur', () => setTimeout(close, 150));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const q = input.value.trim();
    if (active >= 0 && active < items.length) return open(`/items/${items[active].id}`);
    open(q ? `/items?q=${encodeURIComponent(q)}` : '/items');
  });
  return form;
}

// Raccourci : le champ de l'accueil s'il est affiché, sinon celui de la barre supérieure
document.addEventListener('keydown', (event) => {
  const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
  if (!((event.key === '/' && !typing) || (event.key?.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey)))) return;
  if (document.querySelector('dialog[open]')) return;
  // Le champ de la page s'il y en a un (accueil, catalogue), sinon celui de la barre supérieure
  const target = document.querySelector('.home-search-input, .page-search-input') ?? document.querySelector('.global-search-input');
  if (!target) return;
  event.preventDefault();
  if (target.classList.contains('global-search-input')) document.body.classList.add('search-open');
  target.focus();
  target.select();
});
