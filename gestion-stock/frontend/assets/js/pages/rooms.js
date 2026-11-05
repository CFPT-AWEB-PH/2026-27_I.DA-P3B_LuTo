import { api } from '../api.js';
import { append, clear, h } from '../dom.js';
import { icon } from '../icons.js';
import { confirmDialog, emptyState, errorState, menuButton, pageHeader, plural, showError, skeleton, toast } from '../ui.js';
import { formatLength, polygonArea } from '../spatial.js';
import { openRoomDialog } from './room-form.js';
import { navigate } from '../app.js';

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

export function render(view, session) {
  const canEdit = session.can('rooms.edit');
  const grid = h('div', {}, skeleton('card', 3));

  const create = async () => {
    const result = await openRoomDialog();
    if (result) navigate(`/rooms/${result.room.id}`);
  };

  append(view, 
    pageHeader({
      title: 'Salles',
      description: 'Ouvrez une salle pour la parcourir en 3D et retrouver chaque meuble, tiroir et boîte.',
      actions: canEdit && h('button', { class: 'btn btn-primary', type: 'button', onclick: create }, icon('plus'), 'Nouvelle salle'),
    }),
    grid,
  );

  const load = async () => {
    try {
      const { rooms } = await api('/rooms');
      clear(grid, rooms.length
        ? h('div', { class: 'room-grid' }, rooms.map((room) => card(room, canEdit, load)))
        : h('div', { class: 'card' }, emptyState(canEdit ? 'Créez la première salle : ses dimensions et sa porte suffisent pour commencer.' : "Aucune salle n'a encore été créée.", {
          title: 'Aucune salle', iconName: 'room',
          action: canEdit && h('button', { class: 'btn btn-primary', type: 'button', onclick: create }, icon('plus'), 'Créer une salle'),
        })));
    } catch (error) {
      clear(grid, errorState(error, load));
    }
  };
  load();
}

function card(room, canEdit, reload) {
  const unit = room.display_unit;
  const xs = room.outline.map((p) => p[0]); const zs = room.outline.map((p) => p[1]);
  const area = polygonArea(room.outline) / 1e6;

  const more = canEdit && menuButton({
    label: `Autres actions pour ${room.name}`,
    items: [
      { label: 'Modifier les dimensions', icon: 'edit', onSelect: async () => { if (await openRoomDialog(room)) reload(); } },
      { label: 'Dupliquer', icon: 'copy', onSelect: async () => {
        try {
          const result = await api(`/rooms/${room.id}/duplicate`, { method: 'POST' });
          toast(`Salle dupliquée : « ${result.room.name} ».`);
          reload();
        } catch (error) { showError(error); }
      } },
      { label: 'Supprimer', icon: 'trash', danger: true, onSelect: async () => {
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
    ],
  });

  return h('article', { class: 'card room-card' },
    h('div', { class: 'room-plan' }, planThumb(room)),
    h('div', { class: 'room-body' },
      h('h2', {}, h('a', { href: `/rooms/${room.id}` }, room.name), room.label && h('span', { class: 'badge' }, room.label)),
      room.description && h('p', { class: 'small muted' }, room.description),
      h('p', { class: 'room-facts' },
        h('span', {}, `${formatLength(Math.max(...xs) - Math.min(...xs), unit)} × ${formatLength(Math.max(...zs) - Math.min(...zs), unit)}`),
        h('span', {}, `${area.toLocaleString('fr-CH', { maximumFractionDigits: 1 })} m²`),
        h('span', {}, plural(room.furniture_count, 'meuble')),
        h('span', {}, plural(room.unit_count, 'rangement'))),
      h('div', { class: 'card-actions' },
        h('a', { class: 'btn btn-primary', href: `/rooms/${room.id}` }, icon('cube'), 'Ouvrir en 3D'),
        more)),
  );
}
