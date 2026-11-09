// Petits aperçus 3D dans les fenêtres (salle, ouvertures, objet, type de rangement).
// Chargés à la demande ; sans WebGL, le formulaire reste simplement utilisable sans aperçu.

import { h } from '../dom.js';
import { LARGE_VISUALS } from '../visuals.js';

const FAKE_ROOM = { outline: [[-5000, -5000], [5000, -5000], [5000, 5000], [-5000, 5000]], height_mm: 5000, display_unit: 'mm', door_offset_mm: 0, door_width_mm: 0, door_height_mm: 0 };

/** Crée la zone d'aperçu. Elle se libère d'elle-même quand sa fenêtre se ferme. */
function previewBox(className, onReady, options = {}) {
  const box = h('div', { class: ['preview-3d', className], 'aria-hidden': 'true' });
  let scene = null;
  let pending = null;
  (async () => {
    // La zone doit être dans le document pour connaître sa taille
    await new Promise((resolve) => requestAnimationFrame(resolve));
    if (!box.isConnected) return;
    try {
      const { RoomScene } = await import('./room-scene.js');
      scene = new RoomScene(box, options);
    } catch (error) {
      console.warn('Aperçu 3D indisponible', error);
      box.hidden = true;
      return;
    }
    const dialog = box.closest('dialog');
    dialog?.addEventListener('close', () => { scene?.dispose(); scene = null; }, { once: true });
    onReady?.(scene);
    pending?.(scene);
    pending = null;
  })();
  return { element: box, run(fn) { if (scene) fn(scene); else pending = fn; } };
}

/** Aperçu d'un meuble ou rangement seul. */
export function unitPreview() {
  const preview = previewBox('preview-small', null, { minimal: true });
  return {
    element: preview.element,
    show(unit) {
      preview.run((scene) => {
        scene.setData(FAKE_ROOM, [], [{ id: 1, parent_id: null, rotation_deg: 0, pos_x_mm: 0, pos_y_mm: 0, pos_z_mm: 0, label: '', name: 'Bac 1', ...unit }], []);
        scene.frameAll();
      });
    },
  };
}

/** Aperçu d'un objet dans une boîte : les petites pièces dans un bac, les outils dans un grand bac. */
export function itemPreview() {
  const preview = previewBox('preview-small', null, { minimal: true });
  return {
    element: preview.element,
    show({ name, visual, visual_color: color, category, unit, resolved }) {
      preview.run((scene) => {
        const large = LARGE_VISUALS.has(resolved);
        const bin = {
          id: 1, parent_id: null, rotation_deg: 0, pos_x_mm: 0, pos_y_mm: 0, pos_z_mm: 0, shape: 'bin', name: 'Bac 1', label: '',
          width_mm: large ? 340 : 120, depth_mm: large ? 240 : 120, height_mm: large ? 60 : 45, panel_mm: 3, color: '#c4263c',
        };
        scene.setData(FAKE_ROOM, [], [bin], [{ unit_id: 1, item_id: 1, quantity: large ? 3 : 18, name, unit, visual, visual_color: color, category }]);
        scene.frameAll();
      });
    },
  };
}

/** Aperçu de la salle entière, avec ses ouvertures et ses meubles. */
export function roomPreview() {
  const preview = previewBox('preview-room', null);
  let lastSpan = null;
  return {
    element: preview.element,
    show(room, openings = [], units = []) {
      preview.run((scene) => {
        scene.setData({ id: room.id ?? 0, ...room }, openings, units, []);
        const span = JSON.stringify(room.outline);
        if (span !== lastSpan) scene.viewPreset('iso');
        lastSpan = span;
      });
    },
  };
}
