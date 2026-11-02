// Recherche globale de la barre supérieure : suggestions pendant la saisie (serveur), Entrée pour
// la page de résultats. Raccourci : « / » ou Ctrl+K. Navigation au clavier dans les suggestions.
// Sur mobile, elle se déplie par-dessus la barre supérieure (bouton loupe).

import { api } from './api.js';
import { h } from './dom.js';
import { icon } from './icons.js';
import { formatQuantity } from './stock-dialogs.js';

export function searchBox(navigate) {
  const input = h('input', {
    type: 'search', class: 'global-search-input', placeholder: 'Rechercher un objet, une référence…', autocomplete: 'off',
    role: 'combobox', 'aria-expanded': 'false', 'aria-controls': 'global-suggestions', 'aria-autocomplete': 'list', 'aria-label': 'Recherche globale',
    enterkeyhint: 'search',
  });
  const list = h('ul', { id: 'global-suggestions', class: 'suggestions', role: 'listbox', 'aria-label': 'Suggestions', hidden: true });
  const collapse = () => document.body.classList.remove('search-open');
  const form = h('form', { class: 'global-search', role: 'search' },
    h('div', { class: 'search-field' }, icon('search'), input, h('kbd', { class: 'kbd', 'aria-hidden': 'true' }, '/')),
    h('button', { class: 'icon-btn search-close', type: 'button', 'aria-label': 'Fermer la recherche', onclick: () => { close(); collapse(); } }, icon('x')),
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
        const all = h('li', { role: 'option', id: 'sugg-all', class: 'suggestion-all', onmousedown: (e) => { e.preventDefault(); open(`/items?q=${encodeURIComponent(q)}`); } },
          icon('search'), `Voir tous les résultats pour « ${q} »`);
        show(suggestions.length
          ? [...suggestions.map((s, i) => h('li', {
            role: 'option', id: `sugg-${i}`, 'aria-selected': 'false',
            onmousedown: (e) => { e.preventDefault(); open(`/items/${s.id}`); },
          },
            h('div', { class: 'suggestion-name' }, s.name, h('span', { class: 'suggestion-qty' }, formatQuantity(s.total_quantity, s.unit))),
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
    else if (event.key === 'Escape') { event.preventDefault(); close(); input.blur(); collapse(); }
  });
  input.addEventListener('blur', () => setTimeout(close, 150));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const q = input.value.trim();
    if (active >= 0 && active < items.length) return open(`/items/${items[active].id}`);
    if (q) open(`/items?q=${encodeURIComponent(q)}`);
  });

  document.addEventListener('keydown', (event) => {
    const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
    if ((event.key === '/' && !typing) || (event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey))) {
      if (!document.body.contains(input) || document.querySelector('dialog[open]')) return;
      event.preventDefault();
      document.body.classList.add('search-open');
      input.focus();
      input.select();
    }
  });
  return form;
}
