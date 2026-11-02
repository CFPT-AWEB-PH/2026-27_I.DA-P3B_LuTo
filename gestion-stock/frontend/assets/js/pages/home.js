import { h } from '../dom.js';

const LABELS = {
  'rooms.view': 'consulter les salles',
  'rooms.edit': 'créer et modifier des salles',
  'furniture.edit': 'placer des armoires',
  'storage.edit': 'modifier les rangements',
  'stock.view': 'consulter le stock',
  'items.edit': 'gérer le catalogue',
  'stock.take': 'prendre et rendre des objets',
  'stock.add': 'ajouter du stock',
  'stock.adjust': 'corriger le stock',
  'stock.move': 'déplacer du stock',
  'history.view': "consulter l'historique",
  'users.manage': 'gérer les utilisateurs',
  'roles.manage': 'gérer les rôles',
  'settings.manage': 'modifier les paramètres',
  'system.admin': 'administrer le système',
};

export function render(view, session) {
  const { user, permissions } = session.me;
  view.append(
    h('h1', {}, `Bonjour ${user.display_name || user.email}`),
    h('section', { class: 'card' },
      h('h2', {}, 'Votre accès'),
      h('p', {}, 'Rôle : ', h('strong', {}, user.role.name)),
      h('p', {}, 'Vous pouvez : ' + permissions.map((p) => LABELS[p] ?? p).join(', ') + '.'),
    ),
    h('section', { class: 'card card-muted' },
      h('h2', {}, 'Prochaines étapes'),
      h('p', {}, "Salles en 3D, rangements, catalogue, recherche, emprunts et historique sont opérationnels. Le tableau de bord, le menu contextuel (clic droit) et l'installation en application (PWA) arrivent à l'étape suivante."),
    ),
  );
}
