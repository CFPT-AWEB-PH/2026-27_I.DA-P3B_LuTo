// Menu contextuel : clic droit, appui long au toucher, touche Menu ou Maj+F10 au clavier.
// Il ne remplace le menu du navigateur que sur les éléments qui l'activent, jamais dans un champ de saisie
// ni sur un texte sélectionné. Maj + clic droit laisse toujours le menu natif (copier un lien, inspecter…).
// items : [{ label, icon, onSelect, danger }] ; { separator: true } pour un trait ; les valeurs fausses sont ignorées.
// Les actions proposées dépendent des permissions, mais le serveur reste seul juge.

import { h } from './dom.js';
import { icon } from './icons.js';

const LONG_PRESS_MS = 550;
const MOVE_TOLERANCE = 10;
let closeCurrent = null;
let lastOpened = 0;

export function closeContextMenu() {
  closeCurrent?.();
}

/** Ouvre le menu en (x, y), recadré pour rester dans la partie visible de l'écran. */
export function openContextMenu(rawItems, { x, y, label = 'Actions' }) {
  closeContextMenu();
  const items = rawItems.flat().filter(Boolean);
  // Pas de trait en tête, en fin ou en double quand des actions sont masquées par les permissions
  const cleaned = items.filter((item, i) => !item.separator || (i > 0 && i < items.length - 1 && !items[i - 1].separator));
  if (!cleaned.some((item) => !item.separator)) return false;

  const opener = document.activeElement;
  const entries = [];
  const menu = h('div', { class: 'popover context-menu', role: 'menu', 'aria-label': label },
    cleaned.map((item) => {
      if (item.separator) return h('div', { class: 'menu-separator', role: 'separator' });
      const entry = h('button', {
        class: ['menu-item', item.danger && 'menu-item-danger'], type: 'button', role: 'menuitem', tabindex: '-1',
        onclick: () => { close(true); item.onSelect(); },
      }, item.icon && icon(item.icon), item.label);
      entries.push(entry);
      return entry;
    }));

  function close(restoreFocus = false) {
    if (closeCurrent !== close) return;
    closeCurrent = null;
    menu.remove();
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('resize', onDismiss);
    window.removeEventListener('blur', onDismiss);
    document.removeEventListener('scroll', onDismiss, true);
    if (restoreFocus && opener?.isConnected) opener.focus({ preventScroll: true });
  }
  const onOutside = (event) => { if (!menu.contains(event.target)) close(); };
  const onDismiss = (event) => { if (!(event.target instanceof Node) || !menu.contains(event.target)) close(); };

  menu.addEventListener('keydown', (event) => {
    const index = entries.indexOf(document.activeElement);
    const go = (i) => { event.preventDefault(); entries[(i + entries.length) % entries.length].focus(); };
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
    else if (event.key === 'ArrowDown') go(index + 1);
    else if (event.key === 'ArrowUp') go(index - 1);
    else if (event.key === 'Home') go(0);
    else if (event.key === 'End') go(entries.length - 1);
    else if (event.key === 'Tab') { event.preventDefault(); close(true); }
  });
  menu.addEventListener('contextmenu', (event) => event.preventDefault());

  // Une boîte de dialogue ouverte est dans la couche supérieure : le menu doit y être aussi
  ([...document.querySelectorAll('dialog[open]')].at(-1) ?? document.body).append(menu);

  // Mesure, puis bascule du côté où il y a de la place et recadrage
  const margin = 8;
  const { width, height } = menu.getBoundingClientRect();
  const maxX = window.innerWidth - width - margin;
  const maxY = window.innerHeight - height - margin;
  menu.style.left = `${Math.max(margin, x <= maxX ? x : Math.min(x - width, maxX))}px`;
  menu.style.top = `${Math.max(margin, y <= maxY ? y : Math.min(y - height, maxY))}px`;

  closeCurrent = close;
  lastOpened = Date.now();
  document.addEventListener('pointerdown', onOutside, true);
  window.addEventListener('resize', onDismiss);
  window.addEventListener('blur', onDismiss);
  document.addEventListener('scroll', onDismiss, true);
  entries[0]?.focus({ preventScroll: true });
  return true;
}

/**
 * Active le menu sur un élément. getItems() est appelé à l'ouverture.
 * Au toucher, l'appui long ouvre le menu et neutralise le clic qui suit.
 */
export function contextMenu(element, getItems, { label = 'Actions' } = {}) {
  element.classList.add('has-context-menu');

  element.addEventListener('contextmenu', (event) => {
    if (event.shiftKey || event.target.closest('input, textarea, select, [contenteditable]')) return;
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && element.contains(selection.anchorNode)) return;
    // Android envoie aussi cet événement à la fin de l'appui long : déjà ouvert, on l'ignore
    if (Date.now() - lastOpened < 800) { event.preventDefault(); return; }
    // Au clavier, l'événement n'a pas de coordonnées : le menu s'ouvre sous l'élément
    let { clientX: x, clientY: y } = event;
    if (x === 0 && y === 0) {
      const rect = (event.target instanceof Element ? event.target : element).getBoundingClientRect();
      x = rect.left + 8;
      y = rect.bottom;
    }
    if (openContextMenu(getItems(event), { x, y, label })) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  let timer = null;
  let start = null;
  const cancel = () => { clearTimeout(timer); timer = null; };
  element.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse' || !event.isPrimary || event.target.closest('input, textarea, select')) return;
    start = { x: event.clientX, y: event.clientY };
    cancel();
    timer = setTimeout(() => {
      timer = null;
      if (!openContextMenu(getItems(event), { x: start.x, y: start.y, label })) return;
      navigator.vibrate?.(10);
      // Le doigt se relève sur l'élément : ce clic ne doit pas ouvrir le lien ou le bouton dessous
      const swallow = (e) => { e.preventDefault(); e.stopPropagation(); };
      element.addEventListener('click', swallow, { capture: true, once: true });
      // Sans clic (doigt glissé hors de l'élément), le filtre ne doit pas bloquer un clic ultérieur
      document.addEventListener('pointerup', () => setTimeout(() => element.removeEventListener('click', swallow, { capture: true }), 300), { once: true });
    }, LONG_PRESS_MS);
  });
  element.addEventListener('pointermove', (event) => {
    if (timer && Math.hypot(event.clientX - start.x, event.clientY - start.y) > MOVE_TOLERANCE) cancel();
  });
  for (const type of ['pointerup', 'pointercancel', 'pointerleave']) element.addEventListener(type, cancel);
}
