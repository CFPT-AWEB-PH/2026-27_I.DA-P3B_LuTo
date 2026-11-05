// Composants d'interface communs : notifications, boîtes de dialogue, erreurs de formulaire,
// états de chargement, états vides, en-têtes de page et étiquettes d'emplacement.

import { h } from './dom.js';
import { icon } from './icons.js';

const TOAST_ICONS = { success: 'check', error: 'alertCircle', info: 'info' };
let uid = 0;
const nextId = (prefix) => `${prefix}-${++uid}`;

/** Retire un élément après son animation de sortie (ou tout de suite si les animations sont réduites). */
function leave(el, className, done = () => el.remove()) {
  el.classList.add(className);
  const names = getComputedStyle(el).animationName;
  if (!names || names === 'none') return done();
  let finished = false;
  const finish = () => { if (!finished) { finished = true; done(); } };
  el.addEventListener('animationend', finish, { once: true });
  setTimeout(finish, 400);
}

/**
 * Une boîte de dialogue modale recouvre tout le reste de la page : tant qu'elle est ouverte,
 * la zone de notifications est placée à l'intérieur pour rester visible (et cliquable).
 */
function placeToasts(container = document.getElementById('toasts')) {
  const target = [...document.querySelectorAll('dialog[open]:not(.is-closing)')].at(-1) ?? document.body;
  if (container && container.parentElement !== target) target.append(container);
}

export function toast(message, type = 'success', { action = null } = {}) {
  const container = document.getElementById('toasts');
  let timer;
  const dismiss = () => { clearTimeout(timer); leave(el, 'is-leaving'); };
  const el = h('div', { class: ['toast', `toast-${type}`], role: type === 'error' ? 'alert' : 'status' },
    icon(TOAST_ICONS[type] ?? 'info', 'toast-icon'),
    h('p', { class: 'toast-message' }, message),
    action && h('button', { class: 'toast-action', type: 'button', onclick: () => { dismiss(); action.run(); } }, action.label),
    h('button', { class: 'toast-close', type: 'button', 'aria-label': 'Fermer la notification', onclick: dismiss }, icon('x')),
  );
  container.append(el);
  placeToasts(container);
  // Peu d'éléments minutés : une erreur reste jusqu'à sa fermeture, une notification annulable laisse 10 s
  const delay = type === 'error' ? null : action ? 10000 : 4000;
  const schedule = () => { if (delay) timer = setTimeout(dismiss, delay); };
  // Pas de disparition pendant que l'on survole ou que l'on lit au clavier
  el.addEventListener('pointerenter', () => clearTimeout(timer));
  el.addEventListener('pointerleave', schedule);
  el.addEventListener('focusin', () => clearTimeout(timer));
  schedule();
  while (container.children.length > 3) container.firstElementChild.remove();
}

/** Affiche l'erreur d'une action : message général + erreurs par champ dans le formulaire. */
export function showError(error, form = null) {
  let firstInvalid = null;
  if (form) {
    form.querySelectorAll('.field-error, .form-alert').forEach((el) => el.remove());
    form.querySelectorAll('[aria-invalid]').forEach((el) => {
      el.removeAttribute('aria-invalid');
      const rest = (el.getAttribute('aria-describedby') ?? '').split(' ').filter((id) => id && !id.startsWith('err-'));
      if (rest.length) el.setAttribute('aria-describedby', rest.join(' ')); else el.removeAttribute('aria-describedby');
    });
    for (const [name, message] of Object.entries(error.fields ?? {})) {
      const input = form.elements.namedItem(name);
      const target = input instanceof RadioNodeList ? input[0] : input;
      const wrapper = target?.closest('.field');
      if (!target || !wrapper) continue;
      const id = nextId('err');
      target.setAttribute('aria-invalid', 'true');
      target.setAttribute('aria-describedby', [target.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
      wrapper.append(h('p', { class: 'field-error', id }, icon('alertCircle'), message));
      firstInvalid ??= target.type === 'hidden' ? wrapper.querySelector('button') : target;
    }
  }
  firstInvalid?.focus();
  const message = error.message ?? 'Une erreur est survenue.';
  // Dans une boîte de dialogue, l'erreur s'affiche au-dessus des boutons, là où l'on regarde
  const actions = form?.closest('dialog') && form.querySelector(':scope > .dialog-actions');
  if (actions) {
    actions.before(h('p', { class: 'alert alert-error form-alert', role: 'alert' }, icon('alertCircle'), h('span', {}, message)));
    return;
  }
  toast(message, 'error');
}

/**
 * Boîte de dialogue modale. build(close) renvoie le contenu ; résout avec la valeur passée à close().
 * Sur mobile, elle s'affiche en panneau glissant depuis le bas.
 */
export function openDialog(title, build, { wide = false } = {}) {
  return new Promise((resolve) => {
    const titleId = nextId('dialog-title');
    const dialog = h('dialog', { class: ['dialog', wide && 'dialog-wide'], 'aria-labelledby': titleId });
    const opener = document.activeElement;
    let result;
    let closing = false;
    const close = (value) => {
      if (closing) return;
      closing = true;
      result = value;
      leave(dialog, 'is-closing', () => dialog.close());
      placeToasts();
    };
    dialog.append(
      h('header', { class: 'dialog-header' },
        h('h2', { id: titleId }, title),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Fermer', onclick: () => close(undefined) }, icon('x')),
      ),
      build(close),
    );
    // Mobile : la poignée promet un geste, le panneau suit le doigt depuis son en-tête et se ferme vers le bas
    const header = dialog.querySelector('.dialog-header');
    header.addEventListener('pointerdown', (event) => {
      if (!window.matchMedia('(max-width: 767px)').matches || event.button !== 0 || event.target.closest('button')) return;
      const startY = event.clientY;
      const startTime = performance.now();
      let dy = 0;
      try { header.setPointerCapture(event.pointerId); } catch { /* pointeur déjà relâché */ }
      dialog.classList.add('is-dragging');
      const move = (e) => { dy = Math.max(0, e.clientY - startY); dialog.style.transform = `translateY(${dy}px)`; };
      const end = () => {
        header.removeEventListener('pointermove', move);
        dialog.classList.remove('is-dragging');
        const fast = dy / (performance.now() - startTime) > 0.5;
        if (dy > 96 || (fast && dy > 24)) close(undefined);
        else dialog.style.transform = '';
      };
      header.addEventListener('pointermove', move);
      header.addEventListener('pointerup', end, { once: true });
      header.addEventListener('pointercancel', end, { once: true });
    });
    // Échap : même sortie animée que le bouton Fermer
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(undefined); });
    dialog.addEventListener('close', () => {
      placeToasts();
      dialog.remove();
      if (opener?.isConnected) opener.focus({ preventScroll: true });
      resolve(result);
    });
    document.body.append(dialog);
    dialog.showModal();
    placeToasts();
    // Le premier champ reçoit le focus plutôt que le bouton Fermer ; au toucher, on évite d'ouvrir le clavier
    // virtuel par-dessus le bouton de validation (le pas à pas − / + suffit souvent)
    if (finePointer()) dialog.querySelector('.dialog-body input:not([type=hidden]):not([readonly]), .dialog-body select, .dialog-body textarea')?.focus();
  });
}

export function confirmDialog({ title, message, confirmLabel = 'Confirmer', cancelLabel = 'Annuler', danger = false }) {
  return openDialog(title, (close) => h('div', { class: 'dialog-body' },
    h('div', { class: ['confirm', danger && 'confirm-danger'] },
      h('span', { class: 'confirm-icon' }, icon(danger ? 'alert' : 'info')),
      h('p', {}, message)),
    h('div', { class: 'dialog-actions' },
      h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, cancelLabel),
      h('button', { class: ['btn', danger ? 'btn-danger' : 'btn-primary'], type: 'button', onclick: () => close(true) }, confirmLabel),
    ),
  )).then(Boolean);
}

/**
 * Bouton qui ouvre un menu d'actions (motif « menu button » de l'ARIA APG) : flèches, Début et Fin
 * pour se déplacer, Échap pour fermer en rendant le focus au bouton, clic extérieur pour fermer.
 * items : [{ label, icon, onSelect, danger }] ; les valeurs fausses sont ignorées.
 */
export function menuButton({ label, items, trigger = null, header = null }) {
  const button = trigger ?? h('button', { class: 'icon-btn icon-btn-bordered', type: 'button', 'aria-label': label, title: label }, icon('more'));
  const menuId = nextId('menu');
  Object.entries({ 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-controls': menuId }).forEach(([k, v]) => button.setAttribute(k, v));
  const entries = items.filter(Boolean).map((item) => h('button', {
    class: ['menu-item', item.danger && 'menu-item-danger'], type: 'button', role: 'menuitem', tabindex: '-1',
    // Le focus revient au bouton avant l'action : une boîte de dialogue ouverte ensuite le lui rendra
    onclick: () => { toggle(false, true); item.onSelect(); },
  }, item.icon && icon(item.icon), item.label));
  const menu = h('div', { class: 'popover', id: menuId, role: 'menu', 'aria-label': label, hidden: true }, header, entries);
  const wrapper = h('div', { class: 'popover-anchor' }, button, menu);
  const onOutside = (event) => { if (!wrapper.contains(event.target)) toggle(false); };

  function toggle(open, restoreFocus = false) {
    if (open === !menu.hidden) return;
    menu.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
    if (open) {
      document.addEventListener('pointerdown', onOutside);
      entries[0]?.focus();
    } else {
      document.removeEventListener('pointerdown', onOutside);
      if (restoreFocus) button.focus();
    }
  }
  button.addEventListener('click', () => toggle(menu.hidden));
  wrapper.addEventListener('keydown', (event) => {
    if (menu.hidden) {
      if (event.key === 'ArrowDown' && event.target === button) { event.preventDefault(); toggle(true); }
      return;
    }
    const index = entries.indexOf(document.activeElement);
    const go = (i) => { event.preventDefault(); entries[(i + entries.length) % entries.length].focus(); };
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); toggle(false, true); }
    else if (event.key === 'ArrowDown') go(index + 1);
    else if (event.key === 'ArrowUp') go(index - 1);
    else if (event.key === 'Home') go(0);
    else if (event.key === 'End') go(entries.length - 1);
    else if (event.key === 'Tab') toggle(false);
  });
  return wrapper;
}

/**
 * Bouton « Filtres » (mobile uniquement, voir CSS) qui déplie le panneau placé juste après lui,
 * avec le nombre de filtres actifs : la liste reste visible sans faire défiler cinq champs.
 */
export function filterToggle(panelId) {
  const count = h('span', { class: 'nav-count', hidden: true });
  const button = h('button', {
    class: 'btn filter-toggle', type: 'button', 'aria-expanded': 'false', 'aria-controls': panelId,
    onclick: () => button.setAttribute('aria-expanded', String(button.getAttribute('aria-expanded') !== 'true')),
  }, icon('sliders'), 'Filtres', count);
  return {
    element: button,
    setCount(n) {
      count.hidden = n === 0;
      count.textContent = String(n);
      button.setAttribute('aria-label', n ? `Filtres, ${n} actif${n > 1 ? 's' : ''}` : 'Filtres');
    },
  };
}

/** Vrai sur un appareil à pointeur précis (souris) : on peut y donner le focus sans ouvrir de clavier virtuel. */
export const finePointer = () => window.matchMedia('(pointer: fine)').matches;

/** Désactive un bouton pendant une opération asynchrone (évite les doubles envois) et affiche un indicateur. */
export async function busy(button, task) {
  if (!button) return task();
  button.disabled = true;
  button.classList.add('is-busy');
  button.setAttribute('aria-busy', 'true');
  try {
    return await task();
  } finally {
    button.disabled = false;
    button.classList.remove('is-busy');
    button.removeAttribute('aria-busy');
  }
}

export function field(label, input, hint = null) {
  const id = input.id || nextId('f');
  input.id = id;
  const hintId = hint ? `${id}-hint` : null;
  if (hintId) input.setAttribute('aria-describedby', hintId);
  return h('div', { class: 'field' },
    h('label', { for: id }, label),
    input,
    hint && h('p', { class: 'field-hint', id: hintId }, hint),
  );
}

/** Case à cocher avec libellé et explication facultative. */
export function checkbox(input, label, hint = null) {
  return h('label', { class: 'checkbox' }, input,
    h('span', { class: 'checkbox-text' }, label, hint && h('span', { class: 'checkbox-hint' }, hint)));
}

/** État vide : une phrase qui dit quoi faire, et l'action pour le faire quand elle existe. */
export function emptyState(message, { title = null, iconName = 'box', action = null } = {}) {
  return h('div', { class: 'empty-state' },
    h('span', { class: 'empty-icon' }, icon(iconName)),
    title && h('p', { class: 'empty-title' }, title),
    h('p', { class: 'empty-text' }, message),
    action);
}

/** Erreur de chargement d'une zone, avec nouvel essai. */
export function errorState(error, retry) {
  return h('div', { class: 'empty-state empty-state-error', role: 'alert' },
    h('span', { class: 'empty-icon' }, icon(error?.status === 0 ? 'wifiOff' : 'alertCircle')),
    h('p', { class: 'empty-title' }, 'Chargement impossible'),
    h('p', { class: 'empty-text' }, error?.message ?? 'Une erreur est survenue.'),
    retry && h('button', { class: 'btn', type: 'button', onclick: retry }, icon('refresh'), 'Réessayer'));
}

/** Squelettes de chargement : lignes (« row »), cartes (« card ») ou texte (« text »). */
export function skeleton(kind = 'row', count = 3) {
  return h('div', { class: ['skeleton-group', `skeleton-${kind}s`], 'aria-hidden': 'true' },
    Array.from({ length: count }, () => h('div', { class: ['skeleton', `skeleton-${kind}`] })));
}

/** En-tête de page : titre, description courte, action principale et lien de retour éventuels. */
export function pageHeader({ title, description = null, actions = null, back = null }) {
  return h('header', { class: 'page-header' },
    back && h('a', { class: 'back-link', href: back.href }, icon('arrowLeft'), back.label),
    h('div', { class: 'page-header-row' },
      h('div', { class: 'page-header-text' },
        h('h1', {}, title),
        description && h('p', { class: 'page-description' }, description)),
      actions && h('div', { class: 'page-actions' }, actions)));
}

/**
 * Étiquette d'emplacement « Salle › Armoire › Tiroir » : la salle en tête, l'emplacement exact
 * en dernier et en gras. Avec href, l'étiquette ouvre la salle en 3D sur ce rangement.
 */
export function locationLabel(path, { href = null, quantity = null } = {}) {
  const parts = String(path ?? '').split(' › ').filter(Boolean);
  const room = parts.shift();
  const content = [
    h('span', { class: 'loc-room' }, room ?? '–'),
    parts.map((part, i) => [
      h('span', { class: 'loc-sep', 'aria-hidden': 'true' }, '›'),
      h('span', { class: ['loc-part', i === parts.length - 1 && 'loc-exact'] }, part),
    ]),
  ];
  const label = href
    ? h('a', { class: 'loc', href, title: 'Voir cet emplacement en 3D' }, content)
    : h('span', { class: 'loc' }, content);
  return quantity === null ? label : h('span', { class: 'loc-with-qty' }, label, h('span', { class: 'loc-qty' }, quantity));
}

/** Champ numérique avec boutons − et + (cibles tactiles), sans changer l'input lui-même. */
export function stepper(input, step = 1) {
  const bump = (delta) => {
    const current = Number(String(input.value).replace(',', '.')) || 0;
    const min = input.min === '' ? -Infinity : Number(input.min);
    const max = input.max === '' ? Infinity : Number(input.max);
    const next = Math.min(max, Math.max(Number.isFinite(min) ? min : 0, Math.round((current + delta) * 1000) / 1000));
    input.value = String(next);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };
  return h('div', { class: 'stepper' },
    h('button', { class: 'stepper-btn', type: 'button', tabindex: '-1', 'aria-label': 'Diminuer', onclick: () => bump(-step) }, icon('minus')),
    input,
    h('button', { class: 'stepper-btn', type: 'button', tabindex: '-1', 'aria-label': 'Augmenter', onclick: () => bump(step) }, icon('plus')));
}

/** Accord simple : plural(3, 'objet') → « 3 objets ». */
export function plural(count, singular, pluralForm = `${singular}s`) {
  return `${Number(count).toLocaleString('fr-CH')} ${Math.abs(count) >= 2 ? pluralForm : singular}`;
}
