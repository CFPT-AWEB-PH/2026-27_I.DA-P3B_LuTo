import { api } from '../api.js';
import { h } from '../dom.js';

const ERRORS = {
  not_allowed: "Ce compte n'est pas autorisé à accéder à l'application. Contactez un administrateur.",
  disabled: 'Votre compte est désactivé. Contactez un administrateur.',
  invalid_link: 'Ce lien de connexion est invalide, a expiré ou a déjà été utilisé.',
  rate_limited: 'Trop de tentatives. Réessayez dans quelques minutes.',
  disabled_method: 'La connexion par lien est désactivée.',
  error: 'La connexion a échoué. Réessayez.',
};

export async function render(container) {
  const error = new URLSearchParams(location.search).get('error');
  const body = h('div', { class: 'login-methods' }, h('p', { class: 'muted' }, 'Chargement…'));

  container.append(h('main', { class: 'login-card' },
    h('h1', {}, 'Gestion de stock'),
    h('p', { class: 'muted' }, 'Salles, rangements et matériel'),
    error && h('p', { class: 'alert alert-error', role: 'alert' }, ERRORS[error] ?? ERRORS.error),
    body,
  ));

  let config;
  try {
    config = await api('/auth/config');
  } catch (e) {
    body.replaceChildren(h('p', { class: 'alert alert-error' }, e.message));
    return;
  }

  body.replaceChildren(
    config.google
      ? h('a', { class: 'btn btn-primary btn-block', href: '/api/auth/google/start' }, 'Se connecter avec Google')
      : h('p', { class: 'alert' }, 'La connexion avec Google sera activée une fois le site servi en HTTPS.'),
    config.login_links && h('p', { class: 'muted small' },
      'En attendant, un administrateur peut vous fournir un lien de connexion à usage unique.'),
  );
}
