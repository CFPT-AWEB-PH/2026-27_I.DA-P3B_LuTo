// Recherche globale de la barre supérieure : suggestions pendant la saisie (serveur), Entrée pour
// la page de résultats. Raccourci : « / » ou Ctrl+K. Navigation au clavier dans les suggestions.

import { api } from './api.js';
import { h } from './dom.js';
import { formatQuantity } from './stock-dialogs.js';

export function searchBox(navigate) {
  const input = h('input', {
    type: 'search', class: 'global-search-input', placeholder: 'Rechercher un objet…  ( / )', autocomplete: 'off',
    role: 'combobox', 'aria-expanded': 'false', 'aria-controls': 'global-suggestions', 'aria-label': 'Recherche globale',
  });
  const list = h('ul', { id: 'global-suggestions', class: 'suggestions', role: 'listbox', hidden: true });
  const form = h('form', { class: 'global-search', role: 'search' }, input, list);
  let active = -1;
  let items = [];
  let timer;
  let sequence = 0;

  const close = () => {
    list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    active = -1;
  };
  const highlight = (index) => {
    active = index;
    [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === index)));
  };
  const open = (path) => {
    close();
    input.blur();
    navigate(path);
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
        list.replaceChildren(...suggestions.map((s, i) => h('li', {
          role: 'option', id: `sugg-${i}`, 'aria-selected': 'false',
          onmousedown: (e) => { e.preventDefault(); open(`/items/${s.id}`); },
        },
          h('div', { class: 'suggestion-name' }, s.name, h('span', { class: 'suggestion-qty' }, formatQuantity(s.total_quantity, s.unit))),
          h('div', { class: 'suggestion-meta' }, s.reference, s.location && ` · ${s.location}`))),
        h('li', { role: 'option', class: 'suggestion-all', onmousedown: (e) => { e.preventDefault(); open(`/items?q=${encodeURIComponent(q)}`); } },
          `Tous les résultats pour « ${q} »`));
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
      } catch {
        close();
      }
    }, 200);
  });

  input.addEventListener('keydown', (event) => {
    const count = list.children.length;
    if (event.key === 'ArrowDown' && !list.hidden) { event.preventDefault(); highlight((active + 1) % count); }
    else if (event.key === 'ArrowUp' && !list.hidden) { event.preventDefault(); highlight((active - 1 + count) % count); }
    else if (event.key === 'Escape') { close(); input.blur(); }
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
      if (!document.body.contains(input)) return;
      event.preventDefault();
      input.focus();
      input.select();
    }
  });
  return form;
}
