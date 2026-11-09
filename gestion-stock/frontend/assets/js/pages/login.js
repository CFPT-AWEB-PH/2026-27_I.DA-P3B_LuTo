import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { field } from '../ui.js';
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

/** Identifiant et mot de passe, tels qu'imprimés sur la fiche remise à chaque personne. */
function passwordForm() {
  const username = h('input', { name: 'username', required: true, maxlength: 60, autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false' });
  const password = h('input', { name: 'password', type: 'password', required: true, maxlength: 200, autocomplete: 'current-password' });
  const status = h('p', { class: 'alert alert-error', role: 'alert', hidden: true });
  const submit = h('button', { class: 'btn btn-primary btn-block', type: 'submit' }, 'Se connecter');
  const form = h('form', { class: 'login-form', novalidate: true }, status, field('Identifiant', username), field('Mot de passe', password), submit,
    h('p', { class: 'login-note' }, 'Vos accès figurent sur la fiche qui vous a été remise.'));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!username.value.trim() || !password.value) {
      status.hidden = false;
      status.textContent = 'Indiquez votre identifiant et votre mot de passe.';
      (username.value.trim() ? password : username).focus();
      return;
    }
    status.hidden = true;
    // Indicateur dans le bouton, libellé conservé : pas de saut, pas de double envoi
    submit.disabled = true;
    submit.classList.add('is-busy');
    try {
      await api('/auth/login', { method: 'POST', body: { username: username.value.trim(), password: password.value } });
      location.assign('/');
    } catch (error) {
      status.hidden = false;
      status.textContent = error.message;
      password.value = '';
      password.focus();
      submit.disabled = false;
      submit.classList.remove('is-busy');
    }
  });
  requestAnimationFrame(() => username.focus({ preventScroll: true }));
  return form;
}

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
  clear(body,
    passwordForm(),
    config.google && h('a', { class: 'btn btn-block', href: '/api/auth/google/start' }, 'Continuer avec Google'),
    config.login_links && h('p', { class: 'login-note' }, config.google
      ? 'Pas de compte Google ? Un administrateur peut vous envoyer un lien de connexion à usage unique.'
      : 'Fiche perdue ? Un administrateur peut vous envoyer un lien de connexion à usage unique.'),
  );
}
