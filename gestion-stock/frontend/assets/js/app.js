// Point d'entrée : session, navigation et coque de l'application.
// Les permissions servent ici uniquement à adapter l'affichage ; le serveur les vérifie toujours.

import { api, ApiError, onUnauthorized, setCsrfToken } from './api.js';
import { clear, h } from './dom.js';
import { showError } from './ui.js';
import * as homePage from './pages/home.js';
import * as loginPage from './pages/login.js';
import * as usersPage from './pages/users.js';
import * as rolesPage from './pages/roles.js';
import * as allowlistPage from './pages/allowlist.js';

const routes = [
  { path: '/', label: 'Accueil', page: homePage },
  { path: '/admin/users', label: 'Utilisateurs', page: usersPage, permission: 'users.manage' },
  { path: '/admin/roles', label: 'Rôles et permissions', page: rolesPage, permission: 'roles.manage' },
  { path: '/admin/allowlist', label: "Liste d'autorisation", page: allowlistPage, permission: 'users.manage' },
];

const root = document.getElementById('root');
const session = {
  me: null,
  can(permission) {
    return this.me?.permissions.includes(permission) ?? false;
  },
};
let view = null;

export function navigate(path, { replace = false } = {}) {
  history[replace ? 'replaceState' : 'pushState'](null, '', path);
  render();
}

async function loadSession() {
  try {
    session.me = await api('/auth/me');
    setCsrfToken(session.me.csrf_token);
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401) throw error;
    session.me = null;
    setCsrfToken(null);
  }
}

function render() {
  const path = location.pathname;
  if (!session.me) {
    if (path !== '/login') history.replaceState(null, '', '/login' + location.search);
    view = null;
    root.className = 'login-root';
    loginPage.render(clear(root));
    return;
  }
  if (path === '/login') history.replaceState(null, '', '/');
  if (!view) renderShell();

  const route = routes.find((r) => r.path === location.pathname);
  document.querySelectorAll('.nav-link').forEach((link) => {
    link.toggleAttribute('aria-current', link.getAttribute('href') === location.pathname);
  });
  document.body.classList.remove('nav-open');

  clear(view);
  if (!route) {
    view.append(h('h1', {}, 'Page introuvable'), h('p', {}, h('a', { href: '/' }, "Retour à l'accueil")));
    return;
  }
  if (route.permission && !session.can(route.permission)) {
    view.append(h('h1', {}, 'Accès refusé'), h('p', {}, "Votre rôle ne permet pas d'accéder à cette page."));
    return;
  }
  document.title = `${route.label} · Gestion de stock`;
  route.page.render(view, session);
  view.focus({ preventScroll: true });
}

function renderShell() {
  const { user } = session.me;
  root.className = 'app';
  view = h('main', { id: 'view', class: 'view', tabindex: '-1' });

  const toggle = h('button', {
    class: 'icon-btn nav-toggle', type: 'button', 'aria-label': 'Menu',
    onclick: () => document.body.classList.toggle('nav-open'),
  }, '☰');

  const logout = h('button', {
    class: 'btn btn-ghost', type: 'button',
    onclick: async () => {
      try {
        await api('/auth/logout', { method: 'POST' });
      } catch (error) {
        if (error.status !== 401) return showError(error);
      }
      session.me = null;
      navigate('/login', { replace: true });
    },
  }, 'Déconnexion');

  const nav = h('nav', { class: 'sidebar', 'aria-label': 'Navigation principale' },
    h('ul', {}, routes
      .filter((r) => !r.permission || session.can(r.permission))
      .map((r) => h('li', {}, h('a', { class: 'nav-link', href: r.path }, r.label)))),
  );

  clear(root,
    h('header', { class: 'topbar' },
      toggle,
      h('a', { class: 'brand', href: '/' }, 'Gestion de stock'),
      h('div', { class: 'user-menu' },
        h('span', { class: 'user-name' }, user.display_name || user.email),
        h('span', { class: 'badge' }, user.role.name),
        logout,
      ),
    ),
    nav,
    h('div', { class: 'nav-backdrop', onclick: () => document.body.classList.remove('nav-open') }),
    view,
  );
}

// Liens internes : navigation sans rechargement
document.addEventListener('click', (event) => {
  const link = event.target.closest('a[href]');
  if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
  const url = new URL(link.href);
  if (url.origin !== location.origin || url.pathname.startsWith('/api/') || link.target) return;
  event.preventDefault();
  if (url.pathname !== location.pathname || url.search !== location.search) navigate(url.pathname + url.search);
});

window.addEventListener('popstate', render);

onUnauthorized(() => {
  if (!session.me) return;
  session.me = null;
  navigate('/login', { replace: true });
});

try {
  await loadSession();
  render();
} catch (error) {
  clear(root, h('div', { class: 'boot' },
    h('p', { class: 'boot-message' }, error.message ?? 'Erreur de chargement.'),
    h('button', { class: 'btn btn-primary', onclick: () => location.reload() }, 'Réessayer'),
  ));
}
