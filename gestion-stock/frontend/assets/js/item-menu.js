// Actions du menu contextuel d'un objet : fiche, prise, retour, déplacement, historique.

import { api } from './api.js';
import { formatDate, h } from './dom.js';
import { locationLabel, openDialog, showError, toast } from './ui.js';
import { addDialog, formatQuantity, moveDialog, returnDialog, takeDialog } from './stock-dialogs.js';
import { navigate } from './app.js';

/** Liste de choix simple ; résout avec l'élément choisi, undefined si annulé. */
function choose(title, choices, render) {
  return openDialog(title, (close) => h('div', { class: 'dialog-body' },
    h('ul', { class: 'plain-list choice-list' }, choices.map((choice) => h('li', {},
      h('button', { class: 'choice', type: 'button', onclick: () => close(choice) }, render(choice))))),
    h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'))));
}

/** Emplacement à utiliser : celui indiqué, le seul existant, ou celui choisi par l'utilisateur. */
async function pickLocation(item, title) {
  if (item.locations.length === 1) return item.locations[0];
  return choose(title, item.locations, (l) => [
    locationLabel(l.path), h('strong', { class: 'choice-side' }, formatQuantity(l.quantity, item.unit)),
  ]);
}

/**
 * Entrées du menu d'un objet. item : { id, name, unit, is_consumable, locations } (locations facultatif :
 * rechargé au besoin). location : emplacement précis si le menu a été ouvert depuis un rangement.
 */
export function itemMenuItems(item, session, { reload = () => {}, location = null, active = true } = {}) {
  const can = (p) => session.can(p);
  // Depuis un rangement, la ligne ne connaît pas tous les emplacements de l'objet
  const withLocations = async () => (item.locations ? item : { ...item, locations: (await api(`/items/${item.id}`)).item.locations });
  const run = (task) => async () => {
    try {
      if (await task()) await reload();
    } catch (error) { showError(error); }
  };
  const canTake = active && can('stock.take') && (location || !item.locations || item.locations.length > 0);
  const canMove = active && can('stock.move') && (location || !item.locations || item.locations.length > 0);

  return [
    { label: 'Consulter la fiche', icon: 'box', onSelect: () => navigate(`/items/${item.id}`) },
    item.locations?.[0] && { label: 'Voir en 3D', icon: 'cube', onSelect: () => navigate(`/rooms/${item.locations[0].room_id}?unit=${item.locations[0].unit_id}`) },
    { separator: true },
    canTake && { label: 'Prendre une quantité…', icon: 'take', onSelect: run(async () => {
      const full = await withLocations();
      if (!location && full.locations.length === 0) return toast('Aucun stock à prendre pour cet objet.', 'info');
      const place = location ?? await pickLocation(full, `Prendre « ${item.name} » depuis…`);
      return place && takeDialog(full, place);
    }) },
    can('stock.take') && { label: 'Enregistrer un retour…', icon: 'undo', onSelect: run(async () => {
      // Un gestionnaire peut enregistrer le retour d'un autre utilisateur
      const scope = can('stock.adjust') && can('history.view') ? 'all' : 'mine';
      const { loans } = await api(`/loans?scope=${scope}&status=open&item_id=${item.id}`);
      if (loans.length === 0) return toast(scope === 'all' ? 'Aucun emprunt en cours pour cet objet.' : "Vous n'avez aucun emprunt en cours pour cet objet.", 'info');
      const loan = loans.length === 1 ? loans[0] : await choose(`Quel emprunt de « ${item.name} » ?`, loans, (l) => [
        h('span', {}, h('strong', {}, l.user.display_name || l.user.email), h('span', { class: 'sub' }, `pris le ${formatDate(l.taken_at, false)}`)),
        h('strong', { class: 'choice-side' }, formatQuantity(l.remaining, item.unit)),
      ]);
      return loan && returnDialog(loan);
    }) },
    canMove && { label: "Déplacer l'objet…", icon: 'move', onSelect: run(async () => {
      const full = await withLocations();
      if (!location && full.locations.length === 0) return toast('Aucun stock à déplacer pour cet objet.', 'info');
      const place = location ?? await pickLocation(full, `Déplacer « ${item.name} » depuis…`);
      return place && moveDialog(full, place);
    }) },
    active && can('stock.add') && { label: 'Ajouter du stock…', icon: 'plus', onSelect: run(() => addDialog(item, location)) },
    { separator: true },
    can('history.view') && { label: "Consulter l'historique", icon: 'history', onSelect: () => navigate(`/movements?item_id=${item.id}`) },
  ];
}
