import { api } from '../api.js';
import { h } from '../dom.js';
import { icon } from '../icons.js';
import { appName } from '../app.js';

const ERRORS = {
  not_allowed: "Cette adresse n'a pas accès à l'application. Demandez à un administrateur de l'ajouter.",
  disabled: 'Ce compte est désactivé. Un administrateur peut le réactiver.',
  invalid_link: 'Ce lien de connexion a expiré ou a déjà servi. Demandez-en un nouveau.',
  rate_limited: 'Trop de tentatives. Patientez quelques minutes avant de réessayer.',
  disabled_method: 'La connexion par lien est désactivée.',
  error: 'La connexion a échoué. Réessayez.',
};

export async function render(container) {
  const error = new URLSearchParams(location.search).get('error');
  const body = h('div', { class: 'login-methods', 'aria-busy': 'true' }, h('div', { class: 'skeleton', 'aria-hidden': 'true' }), h('p', { class: 'sr-only' }, 'Chargement…'));
  body.firstChild.classList.add('skeleton-text');

  container.append(h('main', { class: 'login-card' },
    h('div', { class: 'login-brand' },
      h('span', { class: 'brand-mark', 'aria-hidden': 'true' }, icon('drawer')),
      h('span', { class: 'brand-name' }, appName())),
    h('h1', {}, 'Connexion'),
    h('p', { class: 'login-lead' }, 'Retrouvez le matériel de l’école, salle par salle, tiroir par tiroir.'),
    error && h('p', { class: 'alert alert-error', role: 'alert' }, icon('alertCircle'), h('span', {}, ERRORS[error] ?? ERRORS.error)),
    body,
  ));

  let config;
  try {
    config = await api('/auth/config');
  } catch (e) {
    body.removeAttribute('aria-busy');
    body.replaceChildren(h('p', { class: 'alert alert-error', role: 'alert' }, icon('wifiOff'), h('span', {}, e.message)),
      h('button', { class: 'btn', type: 'button', onclick: () => location.reload() }, icon('refresh'), 'Réessayer'));
    return;
  }

  body.removeAttribute('aria-busy');
  body.replaceChildren(
    config.google
      ? h('a', { class: 'btn btn-primary btn-block', href: '/api/auth/google/start' }, 'Continuer avec Google')
      : h('p', { class: 'alert alert-info' }, icon('info'), h('span', {}, 'La connexion avec Google sera disponible une fois le site servi en HTTPS.')),
    config.login_links && h('p', { class: 'login-note' },
      'Pas de compte Google ? Un administrateur peut vous envoyer un lien de connexion à usage unique.'),
  );
}
