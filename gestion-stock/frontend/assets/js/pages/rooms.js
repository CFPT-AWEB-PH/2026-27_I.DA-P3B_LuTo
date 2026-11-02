import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { confirmDialog, emptyState, showError, toast } from '../ui.js';
import { formatLength, polygonArea } from '../spatial.js';
import { openRoomDialog } from './room-form.js';
import { navigate } from '../app.js';

export function render(view, session) {
  const canEdit = session.can('rooms.edit');
  const grid = h('div', { class: 'room-grid' }, h('p', { class: 'muted' }, 'Chargement…'));

  view.append(
    h('div', { class: 'page-header page-header-actions' },
      h('div', {}, h('h1', {}, 'Salles'), h('p', { class: 'muted' }, 'Ouvrez une salle pour la voir en 3D et organiser ses rangements.')),
      canEdit && h('button', { class: 'btn btn-primary', type: 'button', onclick: async () => {
        const result = await openRoomDialog();
        if (result) navigate(`/rooms/${result.room.id}`);
      } }, 'Nouvelle salle'),
    ),
    grid,
  );

  const load = async () => {
    try {
      const { rooms } = await api('/rooms');
      clear(grid, rooms.length ? rooms.map((room) => card(room, canEdit, load)) : emptyState(canEdit ? 'Aucune salle. Créez la première.' : 'Aucune salle pour le moment.'));
    } catch (error) {
      showError(error);
    }
  };
  load();
}

function card(room, canEdit, reload) {
  const unit = room.display_unit;
  const xs = room.outline.map((p) => p[0]); const zs = room.outline.map((p) => p[1]);
  const area = polygonArea(room.outline) / 1e6;
  return h('article', { class: 'card room-card' },
    h('div', { class: 'role-card-header' }, h('h2', {}, h('a', { href: `/rooms/${room.id}` }, room.name)), room.label && h('span', { class: 'badge' }, room.label)),
    room.description && h('p', { class: 'muted' }, room.description),
    h('p', { class: 'small' },
      `${formatLength(Math.max(...xs) - Math.min(...xs), unit)} × ${formatLength(Math.max(...zs) - Math.min(...zs), unit)} × ${formatLength(room.height_mm, unit)}`,
      ` · ${area.toLocaleString('fr-CH', { maximumFractionDigits: 1 })} m²`),
    h('p', { class: 'small muted' }, `${room.furniture_count} meuble(s), ${room.unit_count} rangement(s) au total`),
    h('div', { class: 'card-actions' },
      h('a', { class: 'btn btn-small btn-primary', href: `/rooms/${room.id}` }, 'Ouvrir en 3D'),
      canEdit && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => { if (await openRoomDialog(room)) reload(); } }, 'Modifier'),
      canEdit && h('button', { class: 'btn btn-small', type: 'button', onclick: async () => {
        try {
          const result = await api(`/rooms/${room.id}/duplicate`, { method: 'POST' });
          toast(`Salle dupliquée : « ${result.room.name} ».`);
          reload();
        } catch (error) { showError(error); }
      } }, 'Dupliquer'),
      canEdit && h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: async () => {
        const ok = await confirmDialog({
          title: 'Supprimer la salle',
          message: `Supprimer « ${room.name} » et ses ${room.unit_count} rangement(s) ? Cette action est définitive.`,
          confirmLabel: 'Supprimer', danger: true,
        });
        if (!ok) return;
        try {
          await api(`/rooms/${room.id}`, { method: 'DELETE' });
          toast('Salle supprimée.');
          reload();
        } catch (error) { showError(error); }
      } }, 'Supprimer'),
    ),
  );
}
