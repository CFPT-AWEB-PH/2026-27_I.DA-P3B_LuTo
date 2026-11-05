// Point d'entrée : session, navigation et coque de l'application.
// Les permissions servent ici uniquement à adapter l'affichage ; le serveur les vérifie toujours.

import { api, ApiError, onUnauthorized, setCsrfToken } from './api.js';
import { append, clear, h } from './dom.js';
import { icon } from './icons.js';
import { emptyState, menuButton, showError } from './ui.js';
import * as homePage from './pages/home.js';
import * as loginPage from './pages/login.js';
import * as usersPage from './pages/users.js';
import * as rolesPage from './pages/roles.js';
import * as allowlistPage from './pages/allowlist.js';
import * as planPage from './pages/plan.js';
import * as roomsPage from './pages/rooms.js';
import * as roomEditorPage from './pages/room-editor.js';
import * as storageTypesPage from './pages/storage-types.js';
import * as itemsPage from './pages/items.js';
import * as itemPage from './pages/item.js';
import * as loansPage from './pages/loans.js';
import * as movementsPage from './pages/movements.js';
import * as catalogAdminPage from './pages/catalog-admin.js';
import * as settingsPage from './pages/settings.js';
import { searchBox } from './search-box.js';
import { canInstall, initPwa, onInstallChange, promptInstall } from './pwa.js';

// nav: false = page accessible mais absente du menu ; « :param » = segment variable
// group : section du menu ; tab : présent dans la barre d'onglets mobile
const routes = [
  { path: '/', label: 'Accueil', icon: 'home', page: homePage, group: 'stock', tab: true },
  { path: '/items', label: 'Objets', icon: 'box', page: itemsPage, permission: 'stock.view', group: 'stock', tab: true },
  { path: '/items/:id', label: 'Objet', page: itemPage, permission: 'stock.view', nav: false, menu: '/items' },
  { path: '/plan', label: 'Plan', icon: 'plan', page: planPage, permission: 'rooms.view', group: 'stock', tab: true },
  { path: '/rooms', label: 'Salles', icon: 'room', page: roomsPage, permission: 'rooms.view', group: 'stock', tab: true },
  { path: '/rooms/:id', label: 'Salle', page: roomEditorPage, permission: 'rooms.view', nav: false, menu: '/rooms' },
  { path: '/loans', label: 'Emprunts', icon: 'loans', page: loansPage, permission: 'stock.view', group: 'stock', tab: true },
  { path: '/movements', label: 'Historique', icon: 'history', page: movementsPage, permission: 'history.view', group: 'stock' },
  { path: '/admin/catalog', label: 'Catégories et champs', icon: 'tag', page: catalogAdminPage, permission: 'items.edit', group: 'admin' },
  { path: '/admin/storage-types', label: 'Types de rangement', icon: 'layers', page: storageTypesPage, permission: 'storage.edit', group: 'admin' },
  { path: '/admin/users', label: 'Utilisateurs', icon: 'users', page: usersPage, permission: 'users.manage', group: 'admin' },
  { path: '/admin/roles', label: 'Rôles et permissions', icon: 'shield', page: rolesPage, permission: 'roles.manage', group: 'admin' },
  { path: '/admin/allowlist', label: 'Accès autorisés', icon: 'listCheck', page: allowlistPage, permission: 'users.manage', group: 'admin' },
  { path: '/admin/settings', label: 'Paramètres', icon: 'sliders', page: settingsPage, permission: 'settings.manage', group: 'admin' },
];
const GROUPS = { stock: 'Stock', admin: 'Administration' };

function matchRoute(pathname) {
  for (const route of routes) {
    const names = [];
    const regex = new RegExp('^' + route.path.replace(/:(\w+)/g, (_, name) => { names.push(name); return '(\\d+)'; }) + '$');
    const m = pathname.match(regex);
    if (m) return { route, params: Object.fromEntries(names.map((n, i) => [n, m[i + 1]])) };
  }
  return null;
}

let currentPage = null;

const root = document.getElementById('root');
const session = {
  me: null,
  settings: {},
  can(permission) {
    return this.me?.permissions.includes(permission) ?? false;
  },
};
let view = null;

export function navigate(path, { replace = false } = {}) {
  history[replace ? 'replaceState' : 'pushState'](null, '', path);
  render();
}

/** Paramètres partagés (nom de l'application, couleurs de remplissage). */
export async function loadSettings() {
  session.settings = (await api('/settings')).settings;
  document.querySelectorAll('.brand-name').forEach((el) => { el.textContent = appName(); });
}

export function appName() {
  return session.settings['app.name'] || 'Gestion de stock';
}

/** Titre de l'onglet du navigateur : « Page · Application ». */
export function setTitle(title) {
  document.title = `${title} · ${appName()}`;
}

async function loadSession() {
  try {
    session.me = await api('/auth/me');
    setCsrfToken(session.me.csrf_token);
    await loadSettings();
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
    currentPage?.dispose?.();
    currentPage = null;
    view = null;
    root.className = 'login-root';
    document.title = `Connexion · ${appName()}`;
    loginPage.render(clear(root));
    return;
  }
  if (path === '/login') history.replaceState(null, '', '/');
  if (!view) renderShell();

  const match = matchRoute(location.pathname);
  const route = match?.route;
  const menuPath = route?.menu ?? route?.path;
  document.querySelectorAll('.nav-link, .tab[href]').forEach((link) => {
    link.toggleAttribute('aria-current', link.getAttribute('href') === menuPath);
    if (link.hasAttribute('aria-current')) link.setAttribute('aria-current', 'page');
  });
  setNavOpen(false);
  // L'accueil a sa propre grande recherche : pas de second champ identique dans la barre
  document.body.classList.toggle('is-home', route?.path === '/');
  document.body.classList.remove('search-open');

  currentPage?.dispose?.();
  currentPage = route?.page ?? null;
  view.className = 'view';
  clear(view);
  // Transition d'entrée unique et courte : signale le changement de page sans ralentir
  view.classList.remove('view-enter');
  void view.offsetWidth;
  view.classList.add('view-enter');
  window.scrollTo({ top: 0 });

  if (!route) {
    document.title = `Page introuvable · ${appName()}`;
    append(view, emptyState("Cette adresse ne correspond à aucune page. Elle a peut-être changé.", {
      title: 'Page introuvable', iconName: 'search',
      action: h('a', { class: 'btn btn-primary', href: '/' }, "Revenir à l'accueil"),
    }));
    return;
  }
  if (route.permission && !session.can(route.permission)) {
    document.title = `Accès refusé · ${appName()}`;
    append(view, emptyState("Votre rôle ne donne pas accès à cette page. Demandez à un administrateur si vous en avez besoin.", {
      title: 'Accès refusé', iconName: 'shield',
      action: h('a', { class: 'btn', href: '/' }, "Revenir à l'accueil"),
    }));
    return;
  }
  setTitle(route.label);
  route.page.render(view, session, match.params);
  view.focus({ preventScroll: true });
}

function setNavOpen(open) {
  document.body.classList.toggle('nav-open', open);
  document.querySelector('.nav-toggle')?.setAttribute('aria-expanded', String(open));
  if (open) document.querySelector('.sidebar')?.focus({ preventScroll: true });
}

function initials(user) {
  const source = user.display_name || user.email;
  const words = source.split(/[\s.@_-]+/).filter(Boolean);
  return ((words[0]?.[0] ?? '') + (words[1]?.[0] ?? '')).toUpperCase() || '?';
}

async function logout() {
  try {
    await api('/auth/logout', { method: 'POST' });
  } catch (error) {
    if (error.status !== 401) return showError(error);
  }
  session.me = null;
  view = null;
  navigate('/login', { replace: true });
}

function userMenu(user) {
  const label = `Compte de ${user.display_name || user.email}`;
  return menuButton({
    label,
    trigger: h('button', { class: 'user-button', type: 'button', 'aria-label': label },
      h('span', { class: 'avatar', 'aria-hidden': 'true' }, initials(user)),
      h('span', { class: 'user-button-name' }, user.display_name || user.email), icon('chevronDown')),
    header: h('div', { class: 'popover-header' },
      h('strong', {}, user.display_name || user.email),
      user.display_name && h('span', {}, user.email),
      h('div', {}, h('span', { class: 'badge badge-accent' }, user.role.name))),
    items: [
      canInstall() && { label: "Installer l'application", icon: 'take', onSelect: promptInstall },
      { label: 'Se déconnecter', icon: 'logOut', danger: true, onSelect: logout },
    ],
  });
}

function renderShell() {
  const { user } = session.me;
  root.className = 'app';
  view = h('main', { id: 'view', class: 'view', tabindex: '-1' });
  const visible = routes.filter((r) => r.nav !== false && (!r.permission || session.can(r.permission)));

  const brand = () => h('a', { class: 'brand', href: '/' },
    h('span', { class: 'brand-mark', 'aria-hidden': 'true' }, icon('drawer')),
    h('span', { class: 'brand-name' }, appName()));

  const search = session.can('stock.view') ? searchBox(navigate, { id: 'global' }) : null;

  const nav = h('nav', { class: 'sidebar', id: 'sidebar', tabindex: '-1', 'aria-label': 'Navigation principale' },
    h('div', { class: 'sidebar-brand' }, brand()),
    Object.entries(GROUPS).map(([key, title]) => {
      const items = visible.filter((r) => r.group === key);
      return items.length > 0 && h('div', { class: 'nav-group' },
        key !== 'stock' && h('p', { class: 'nav-heading', id: `nav-${key}` }, title),
        h('ul', { 'aria-labelledby': key !== 'stock' ? `nav-${key}` : null }, items.map((r) => h('li', {},
          h('a', { class: 'nav-link', href: r.path }, icon(r.icon), r.label)))));
    }),
  );

  // Mobile : jusqu'à cinq destinations du quotidien, sans onglet « Plus » (les onglets naviguent, ils n'agissent pas) ;
  // l'administration, rare sur téléphone, reste dans le menu ouvert depuis la barre supérieure
  const tabbar = h('nav', { class: 'tabbar', 'aria-label': 'Navigation rapide' },
    visible.filter((r) => r.tab).map((r) => h('a', { class: 'tab', href: r.path },
      h('span', { class: 'tab-pill' }, icon(r.icon)), r.label)));
  document.body.classList.toggle('has-admin-nav', visible.some((r) => !r.tab));

  // Le menu du compte est reconstruit quand l'installation devient possible
  const accountSlot = h('div', { class: 'account-slot' }, userMenu(user));
  onInstallChange(() => clear(accountSlot, userMenu(user)));

  // Aucune donnée n'est conservée hors connexion et aucune opération n'est mise en attente : on le dit clairement
  const offline = h('div', { class: 'offline-banner', role: 'status', hidden: navigator.onLine },
    icon('wifiOff'), 'Serveur injoignable : les données affichées peuvent être dépassées et rien ne peut être enregistré.');
  window.addEventListener('offline', () => { offline.hidden = false; });
  window.addEventListener('online', () => { offline.hidden = true; });
  window.addEventListener('server-reachability', (event) => { offline.hidden = event.detail; });

  clear(root,
    h('a', { class: 'skip-link', href: '#view' }, 'Aller au contenu'),
    h('header', { class: 'topbar' },
      h('button', {
        class: 'icon-btn nav-toggle', type: 'button', 'aria-label': 'Ouvrir le menu', 'aria-controls': 'sidebar', 'aria-expanded': 'false',
        onclick: () => setNavOpen(!document.body.classList.contains('nav-open')),
      }, icon('menu')),
      brand(),
      search,
      h('div', { class: 'topbar-end' },
        search && h('button', {
          class: 'icon-btn search-toggle', type: 'button', 'aria-label': 'Rechercher',
          onclick: () => { document.body.classList.add('search-open'); search.querySelector('input').focus(); },
        }, icon('search')),
        accountSlot),
    ),
    offline,
    nav,
    h('div', { class: 'nav-backdrop', onclick: () => setNavOpen(false) }),
    view,
    tabbar,
  );
}

// Liens internes : navigation sans rechargement
document.addEventListener('click', (event) => {
  const link = event.target.closest('a[href]');
  if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
  const url = new URL(link.href);
  if (url.origin !== location.origin || url.pathname.startsWith('/api/') || link.target) return;
  event.preventDefault();
  if (link.classList.contains('skip-link')) return view?.focus();
  if (url.pathname !== location.pathname || url.search !== location.search) navigate(url.pathname + url.search);
  else setNavOpen(false);
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
    setNavOpen(false);
    document.querySelector('.nav-toggle')?.focus();
  }
});

window.addEventListener('popstate', render);

onUnauthorized(() => {
  if (!session.me) return;
  session.me = null;
  view = null;
  navigate('/login', { replace: true });
});

initPwa();

try {
  await loadSession();
  render();
} catch (error) {
  const unreachable = error.status === 0;
  clear(root, h('div', { class: 'boot' },
    emptyState(unreachable
      ? "L'application a besoin du serveur pour afficher les données. Vérifiez que vous êtes connecté au réseau de l'établissement."
      : error.message ?? "L'application n'a pas pu démarrer.",
    { title: unreachable ? 'Serveur injoignable' : 'Démarrage impossible', iconName: 'wifiOff' }),
    h('button', { class: 'btn btn-primary', onclick: () => location.reload() }, icon('refresh'), 'Réessayer'),
  ));
  // Reprise automatique dès le retour du réseau
  if (unreachable) window.addEventListener('online', () => location.reload(), { once: true });
}
