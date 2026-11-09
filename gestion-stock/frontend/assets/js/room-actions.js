// Salles sur l'accueil : plan miniature et menu d'actions. La gestion complète se fait sur le plan du bâtiment.

import { api } from './api.js';
import { confirmDialog, plural, showError, toast } from './ui.js';
import { openRoomDialog } from './pages/room-form.js';
import { navigate } from './app.js';

const SVG = 'http://www.w3.org/2000/svg';

/** Plan miniature d'une salle : contour et porte de référence, à l'échelle. */
export function planThumb(room) {
  const svg = document.createElementNS(SVG, 'svg');
  const xs = room.outline.map((p) => p[0]); const zs = room.outline.map((p) => p[1]);
  const minX = Math.min(...xs); const minZ = Math.min(...zs);
  const w = Math.max(...xs) - minX || 1; const d = Math.max(...zs) - minZ || 1;
  const pad = Math.max(w, d) * 0.06;
  svg.setAttribute('viewBox', `${minX - pad} ${minZ - pad} ${w + 2 * pad} ${d + 2 * pad}`);
  svg.setAttribute('class', 'plan-thumb');
  svg.setAttribute('aria-hidden', 'true');
  const floor = document.createElementNS(SVG, 'polygon');
  floor.setAttribute('points', room.outline.map((p) => p.join(',')).join(' '));
  floor.setAttribute('class', 'plan-thumb-floor');
  const door = document.createElementNS(SVG, 'line');
  Object.entries({ x1: room.door_offset_mm, y1: 0, x2: room.door_offset_mm + room.door_width_mm, y2: 0, class: 'plan-thumb-door' })
    .forEach(([k, v]) => door.setAttribute(k, v));
  svg.append(floor, door);
  return svg;
}

/**
 * Actions d'une salle pour le menu contextuel (clic droit, appui long).
 * Les actions faites dans l'éditeur 3D y sont ouvertes par l'adresse (?action=…).
 */
export function roomMenuItems(room, session, reload) {
  const canEdit = session.can('rooms.edit');
  return [
    { label: 'Ouvrir en 3D', icon: 'cube', onSelect: () => navigate(`/rooms/${room.id}`) },
    session.can('furniture.edit') && { label: 'Ajouter une armoire…', icon: 'plus', onSelect: () => navigate(`/rooms/${room.id}?action=add-furniture`) },
    { separator: true },
    canEdit && { label: 'Modifier les paramètres…', icon: 'sliders', onSelect: async () => { if (await openRoomDialog(room)) reload(); } },
    canEdit && { label: 'Gérer les ouvertures…', icon: 'door', onSelect: () => navigate(`/rooms/${room.id}?action=openings`) },
    canEdit && { label: 'Dupliquer', icon: 'copy', onSelect: async () => {
      try {
        const result = await api(`/rooms/${room.id}/duplicate`, { method: 'POST' });
        toast(`Salle dupliquée : « ${result.room.name} ».`);
        reload();
      } catch (error) { showError(error); }
    } },
    { separator: true },
    canEdit && { label: 'Supprimer la salle…', icon: 'trash', danger: true, onSelect: async () => {
      const ok = await confirmDialog({
        title: 'Supprimer la salle',
        message: `« ${room.name} » et ses ${plural(room.unit_count, 'rangement')} seront supprimés définitivement. Cette action est irréversible.`,
        confirmLabel: 'Supprimer la salle', danger: true,
      });
      if (!ok) return;
      try {
        await api(`/rooms/${room.id}`, { method: 'DELETE' });
        toast('Salle supprimée.');
        reload();
      } catch (error) { showError(error); }
    } },
  ];
}
