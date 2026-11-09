// Connexion : identifiant et mot de passe de la fiche remise à chaque personne (ou lien à usage unique).
// Sur grand écran, un panneau montre en un coup d'œil à quoi sert l'application : un nom tapé, un emplacement exact.

import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { locationLabel } from '../ui.js';
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

let uid = 0;

/** Champ de connexion : libellé, aide, message d'erreur propre au champ (relié par aria-describedby). */
function loginField(label, input, { hint = null, trailing = null } = {}) {
  const id = `login-${++uid}`;
  input.id = id;
  const hintEl = hint && h('p', { class: 'field-hint', id: `${id}-hint` }, hint);
  const errorEl = h('p', { class: 'field-error', id: `${id}-error`, hidden: true });
  const describe = () => input.setAttribute('aria-describedby', [hintEl && hintEl.id, !errorEl.hidden && errorEl.id].filter(Boolean).join(' '));
  describe();
  return {
    element: h('div', { class: 'field' },
      h('label', { for: id }, label),
      trailing ? h('div', { class: 'input-with-action' }, input, trailing) : input,
      hintEl, errorEl),
    setError(message) {
      errorEl.hidden = !message;
      errorEl.replaceChildren(...(message ? [icon('alertCircle'), message] : []));
      if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
      describe();
    },
  };
}

/** Identifiant et mot de passe, tels qu'imprimés sur la fiche remise à chaque personne. */
function passwordForm(card) {
  const username = h('input', {
    name: 'username', required: true, maxlength: 60, autocomplete: 'username', autocapitalize: 'none', autocorrect: 'off',
    spellcheck: 'false', enterkeyhint: 'next', placeholder: 'prenom.nom',
  });
  const password = h('input', { name: 'password', type: 'password', required: true, maxlength: 200, autocomplete: 'current-password', enterkeyhint: 'go' });

  // Afficher le mot de passe : on le recopie depuis la fiche, une faute de frappe se voit tout de suite
  const reveal = h('button', { class: 'icon-btn reveal-btn', type: 'button', 'aria-label': 'Afficher le mot de passe', 'aria-pressed': 'false' }, icon('eye'));
  reveal.addEventListener('click', () => {
    const shown = password.type === 'password';
    password.type = shown ? 'text' : 'password';
    reveal.setAttribute('aria-pressed', String(shown));
    reveal.setAttribute('aria-label', shown ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    reveal.replaceChildren(icon(shown ? 'eyeOff' : 'eye'));
    password.focus();
  });

  const userField = loginField('Identifiant', username, { hint: 'Il figure sur la fiche remise par l’établissement.' });
  const passField = loginField('Mot de passe', password, { trailing: reveal });
  const caps = h('p', { class: 'caps-warning', role: 'status', hidden: true }, icon('alert'), 'Majuscules verrouillées');
  passField.element.append(caps);
  const onKey = (event) => { if (event.getModifierState) caps.hidden = !event.getModifierState('CapsLock'); };
  password.addEventListener('keydown', onKey);
  password.addEventListener('keyup', onKey);
  password.addEventListener('blur', () => { caps.hidden = true; });

  const status = h('div', { class: 'alert alert-error login-alert', role: 'alert', hidden: true });
  const submit = h('button', { class: 'btn btn-primary btn-block login-submit', type: 'submit' }, 'Se connecter', icon('chevronRight'));
  const form = h('form', { class: 'login-form', novalidate: true, 'aria-label': 'Connexion' }, status, userField.element, passField.element, submit);

  const showStatus = (message) => {
    status.hidden = !message;
    status.replaceChildren(...(message ? [icon('alertCircle'), h('span', {}, message)] : []));
  };
  // L'erreur d'un champ disparaît dès qu'on le corrige
  username.addEventListener('input', () => userField.setError(null));
  password.addEventListener('input', () => passField.setError(null));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showStatus(null);
    const missingUser = !username.value.trim();
    const missingPass = !password.value;
    userField.setError(missingUser ? 'Indiquez votre identifiant.' : null);
    passField.setError(missingPass ? 'Indiquez votre mot de passe.' : null);
    if (missingUser || missingPass) {
      (missingUser ? username : password).focus();
      return;
    }
    // Indicateur dans le bouton, libellé conservé : pas de saut, pas de double envoi
    submit.disabled = true;
    submit.classList.add('is-busy');
    submit.setAttribute('aria-busy', 'true');
    try {
      await api('/auth/login', { method: 'POST', body: { username: username.value.trim(), password: password.value } });
      submit.classList.remove('is-busy');
      submit.classList.add('is-success');
      submit.replaceChildren(icon('check'), 'Connexion réussie');
      location.assign('/');
    } catch (error) {
      showStatus(error.status === 0
        ? 'Serveur injoignable. Vérifiez que vous êtes connecté au réseau de l’établissement.'
        : error.message);
      password.value = '';
      password.focus();
      submit.disabled = false;
      submit.classList.remove('is-busy');
      submit.removeAttribute('aria-busy');
      // Secousse brève : « refusé », sans avoir à lire le message (supprimée en mouvement réduit)
      card.classList.remove('is-shaking');
      void card.offsetWidth;
      card.classList.add('is-shaking');
    }
  });
  return { form, focus: () => username.focus({ preventScroll: true }) };
}

/** Panneau de présentation (grand écran) : ce que fait l'application, montré plutôt que décrit. */
function showcase() {
  return h('section', { class: 'login-aside', 'aria-labelledby': 'login-pitch' },
    h('p', { class: 'login-kicker' }, 'Matériel de l’établissement'),
    h('h2', { id: 'login-pitch', class: 'login-pitch' }, 'Chaque objet a sa place. Vous la trouvez en quelques secondes.'),
    h('p', { class: 'login-sub' }, 'Tapez un nom ou une référence : l’application vous indique la salle, l’armoire et le tiroir exacts, et ce qu’il en reste.'),
    h('div', { class: 'login-demo', 'aria-hidden': 'true' },
      h('div', { class: 'login-demo-search' }, icon('search'), h('span', { class: 'login-demo-query' }, 'multimètre'), h('span', { class: 'login-demo-caret' })),
      h('div', { class: 'login-demo-result' },
        h('div', { class: 'login-demo-row' },
          h('strong', {}, 'Multimètre numérique'),
          h('span', { class: 'login-demo-qty' }, h('b', {}, '8'), ' pièces')),
        locationLabel('Atelier › Armoire 3 : informatique › Tiroir 4 › Mesure'))),
    h('ul', { class: 'login-points' },
      h('li', {}, icon('check'), 'Prendre et rendre en deux gestes'),
      h('li', {}, icon('check'), 'Chaque salle en 3D, tiroir par tiroir'),
      h('li', {}, icon('check'), 'Historique de chaque mouvement')));
}

export async function render(container) {
  const error = new URLSearchParams(location.search).get('error');
  const body = h('div', { class: 'login-methods', 'aria-busy': 'true' },
    h('div', { class: 'skeleton skeleton-input', 'aria-hidden': 'true' }),
    h('div', { class: 'skeleton skeleton-input', 'aria-hidden': 'true' }),
    h('p', { class: 'sr-only' }, 'Chargement…'));

  const card = h('section', { class: 'login-card', 'aria-labelledby': 'login-title' },
    h('div', { class: 'login-brand' },
      h('span', { class: 'brand-mark', 'aria-hidden': 'true' }, icon('drawer')),
      h('span', { class: 'brand-name' }, appName())),
    h('h1', { id: 'login-title' }, 'Connexion'),
    h('p', { class: 'login-lead' }, 'Entrez l’identifiant et le mot de passe de votre fiche.'),
    error && h('div', { class: 'alert alert-error login-alert', role: 'alert' }, icon('alertCircle'), h('span', {}, ERRORS[error] ?? ERRORS.error)),
    body);
  card.addEventListener('animationend', () => card.classList.remove('is-shaking'));

  // Formulaire d'abord dans le document (lu et atteint en premier) ; le panneau s'affiche à sa gauche sur grand écran
  container.append(h('main', { class: 'login-shell' }, card, showcase()),
    h('p', { class: 'login-footer' }, 'Accès réservé aux élèves et au personnel de l’établissement.'));

  let config;
  try {
    config = await api('/auth/config');
  } catch (e) {
    body.removeAttribute('aria-busy');
    clear(body,
      h('div', { class: 'alert alert-error', role: 'alert' }, icon('wifiOff'), h('span', {}, e.status === 0
        ? 'Serveur injoignable. Vérifiez que vous êtes connecté au réseau de l’établissement.'
        : e.message)),
      h('button', { class: 'btn btn-block', type: 'button', onclick: () => location.reload() }, icon('refresh'), 'Réessayer'));
    return;
  }

  const { form, focus } = passwordForm(card);
  body.removeAttribute('aria-busy');
  clear(body,
    form,
    config.google && h('div', { class: 'login-divider', role: 'presentation' }, h('span', {}, 'ou')),
    config.google && h('a', { class: 'btn btn-block', href: '/api/auth/google/start' }, 'Continuer avec Google'),
    h('p', { class: 'login-help' },
      config.login_links
        ? 'Fiche perdue ou mot de passe oublié ? Un administrateur peut vous en donner un nouveau, ou vous envoyer un lien de connexion.'
        : 'Fiche perdue ou mot de passe oublié ? Un administrateur peut vous en donner un nouveau.'));
  focus();
}
