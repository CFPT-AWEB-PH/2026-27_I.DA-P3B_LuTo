// Composants d'interface communs : notifications, boîtes de dialogue, erreurs de formulaire.

import { h } from './dom.js';

export function toast(message, type = 'success') {
  const container = document.getElementById('toasts');
  const el = h('div', { class: ['toast', `toast-${type}`], role: type === 'error' ? 'alert' : 'status' }, message);
  container.append(el);
  setTimeout(() => {
    el.classList.add('toast-leaving');
    el.addEventListener('transitionend', () => el.remove(), { once: true });
    setTimeout(() => el.remove(), 500);
  }, type === 'error' ? 7000 : 3500);
}

/** Affiche l'erreur d'une action : message général + erreurs par champ dans le formulaire. */
export function showError(error, form = null) {
  if (form) {
    form.querySelectorAll('.field-error').forEach((el) => el.remove());
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    for (const [field, message] of Object.entries(error.fields ?? {})) {
      const input = form.elements.namedItem(field);
      const target = input instanceof RadioNodeList ? input[0] : input;
      if (target) {
        target.setAttribute('aria-invalid', 'true');
        target.closest('.field')?.append(h('p', { class: 'field-error' }, message));
      }
    }
  }
  toast(error.message ?? 'Une erreur est survenue.', 'error');
}

/**
 * Boîte de dialogue modale. build(close) renvoie le contenu ; résout avec la valeur passée à close().
 */
export function openDialog(title, build, { wide = false } = {}) {
  return new Promise((resolve) => {
    const dialog = h('dialog', { class: ['dialog', wide && 'dialog-wide'], 'aria-labelledby': 'dialog-title' });
    let result;
    const close = (value) => {
      result = value;
      dialog.close();
    };
    dialog.append(
      h('div', { class: 'dialog-header' },
        h('h2', { id: 'dialog-title' }, title),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Fermer', onclick: () => close(undefined) }, '×'),
      ),
      build(close),
    );
    dialog.addEventListener('close', () => {
      dialog.remove();
      resolve(result);
    });
    document.body.append(dialog);
    dialog.showModal();
  });
}

export function confirmDialog({ title, message, confirmLabel = 'Confirmer', danger = false }) {
  return openDialog(title, (close) => h('div', { class: 'dialog-body' },
    h('p', {}, message),
    h('div', { class: 'dialog-actions' },
      h('button', { class: 'btn', type: 'button', onclick: () => close(false) }, 'Annuler'),
      h('button', { class: ['btn', danger ? 'btn-danger' : 'btn-primary'], type: 'button', onclick: () => close(true) }, confirmLabel),
    ),
  )).then(Boolean);
}

/** Désactive un bouton pendant une opération asynchrone (évite les doubles envois). */
export async function busy(button, task) {
  button.disabled = true;
  button.classList.add('is-busy');
  try {
    return await task();
  } finally {
    button.disabled = false;
    button.classList.remove('is-busy');
  }
}

export function field(label, input, hint = null) {
  const id = input.id || `f-${Math.random().toString(36).slice(2, 9)}`;
  input.id = id;
  return h('div', { class: 'field' },
    h('label', { for: id }, label),
    input,
    hint && h('p', { class: 'field-hint' }, hint),
  );
}

export function emptyState(message) {
  return h('p', { class: 'empty-state' }, message);
}
