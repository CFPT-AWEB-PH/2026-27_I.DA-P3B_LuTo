// Fenêtres d'édition d'une salle (dimensions, contour, porte de référence, apparence) et de ses ouvertures.

import { api } from '../api.js';
import { h } from '../dom.js';
import { busy, confirmDialog, emptyState, field, openDialog, showError, toast } from '../ui.js';
import { icon } from '../icons.js';
import { formatLength, fromDisplay, toDisplay, UNITS, wallLength } from '../spatial.js';

const SVG = 'http://www.w3.org/2000/svg';

function svg(tag, attrs = {}, text = null) {
  const el = document.createElementNS(SVG, tag);
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, String(v));
  if (text !== null) el.textContent = text;
  return el;
}

/** Champ de longueur affiché dans l'unité de la salle ; la valeur de référence reste en mm. */
function lengthInput(name, mm, unit) {
  return h('input', {
    name, type: 'number', inputmode: 'decimal', step: UNITS[unit].step, min: 0, required: true,
    value: mm === null || mm === undefined ? '' : toDisplay(mm, unit), dataset: { length: '' },
  });
}

function isRectangle(outline) {
  if (outline.length !== 4) return false;
  const [[a, b], [c, d], [e, f], [g, k]] = outline;
  return a === 0 && b === 0 && d === 0 && c === e && f === k && g === 0 && c > 0 && f > 0;
}

/** Aperçu du plan : contour, numéros de murs, porte de référence et repère. */
function drawPreview(target, outline, door) {
  target.replaceChildren();
  const valid = outline.length >= 3 && outline.every(([x, z]) => Number.isFinite(x) && Number.isFinite(z));
  if (!valid) return;
  const xs = outline.map((p) => p[0]); const zs = outline.map((p) => p[1]);
  const minX = Math.min(...xs, 0); const maxX = Math.max(...xs, 1);
  const minZ = Math.min(...zs, 0); const maxZ = Math.max(...zs, 1);
  const pad = Math.max(maxX - minX, maxZ - minZ) * 0.12;
  const viewBox = [minX - pad, minZ - pad, maxX - minX + 2 * pad, maxZ - minZ + 2 * pad];
  target.setAttribute('viewBox', viewBox.join(' '));
  const stroke = (maxX - minX + maxZ - minZ) / 300;
  const font = Math.max(maxX - minX, maxZ - minZ) / 22;

  target.append(svg('polygon', { points: outline.map((p) => p.join(',')).join(' '), class: 'plan-floor', 'stroke-width': stroke * 2 }));
  outline.forEach(([x1, z1], i) => {
    const [x2, z2] = outline[(i + 1) % outline.length];
    target.append(svg('text', { x: (x1 + x2) / 2, y: (z1 + z2) / 2, class: 'plan-wall-label', 'font-size': font }, String(i + 1)));
  });
  if (door) {
    target.append(svg('line', { x1: door.offset, y1: 0, x2: door.offset + door.width, y2: 0, class: 'plan-door', 'stroke-width': stroke * 6 }));
    target.append(svg('text', { x: door.offset + door.width / 2, y: -pad / 3, class: 'plan-door-label', 'font-size': font }, 'Porte'));
  }
  const axis = Math.max(maxX - minX, maxZ - minZ) / 6;
  target.append(
    svg('line', { x1: 0, y1: 0, x2: axis, y2: 0, class: 'plan-axis-x', 'stroke-width': stroke * 2 }),
    svg('line', { x1: 0, y1: 0, x2: 0, y2: axis, class: 'plan-axis-z', 'stroke-width': stroke * 2 }),
    svg('text', { x: axis, y: font * 1.2, class: 'plan-axis-label', 'font-size': font * 0.8 }, 'X'),
    svg('text', { x: font * 0.4, y: axis, class: 'plan-axis-label', 'font-size': font * 0.8 }, 'Z'),
    svg('circle', { cx: 0, cy: 0, r: stroke * 5, class: 'plan-origin' }),
  );
}

/** Création ou modification d'une salle. Résout avec la réponse de l'API, ou undefined si annulé. */
export function openRoomDialog(room = null) {
  return openDialog(room ? `Paramètres de « ${room.name} »` : 'Nouvelle salle', (close) => {
    let unit = room?.display_unit ?? 'cm';
    const outline = room?.outline ?? [[0, 0], [6000, 0], [6000, 8000], [0, 8000]];
    let mode = isRectangle(outline) ? 'rect' : 'custom';

    const name = h('input', { name: 'name', required: true, maxlength: 120, value: room?.name ?? '' });
    const label = h('input', { name: 'label', maxlength: 50, value: room?.label ?? '' });
    const description = h('textarea', { name: 'description', maxlength: 1000, rows: 2 }, room?.description ?? '');
    const unitSelect = h('select', { name: 'display_unit' },
      Object.keys(UNITS).map((u) => h('option', { value: u, selected: u === unit }, u)));

    const length = lengthInput('length', outline[1][0], unit);
    const depth = lengthInput('depth', isRectangle(outline) ? outline[2][1] : 8000, unit);
    const height = lengthInput('height_mm', room?.height_mm ?? 2800, unit);
    const wall = lengthInput('wall_thickness_mm', room?.wall_thickness_mm ?? 150, unit);
    const doorOffset = lengthInput('door_offset_mm', room?.door_offset_mm ?? 500, unit);
    const doorWidth = lengthInput('door_width_mm', room?.door_width_mm ?? 900, unit);
    const doorHeight = lengthInput('door_height_mm', room?.door_height_mm ?? 2050, unit);
    const floorColor = h('input', { name: 'floor_color', type: 'color', value: room?.floor_color ?? '#c8c2b8' });
    const wallColor = h('input', { name: 'wall_color', type: 'color', value: room?.wall_color ?? '#eceae4' });
    const material = h('select', { name: 'floor_material' },
      [['plain', 'Uni'], ['wood', 'Parquet'], ['tiles', 'Carrelage'], ['concrete', 'Béton']]
        .map(([v, t]) => h('option', { value: v, selected: v === (room?.floor_material ?? 'plain') }, t)));

    const outlineError = h('input', { type: 'hidden', name: 'outline' });
    const pointsBody = h('tbody');
    const preview = svg('svg', { class: 'plan-preview', role: 'img', 'aria-label': 'Aperçu du plan de la salle' });

    const pointRow = (x, z, index) => {
      const fixedX = index === 0;
      const fixedZ = index <= 1;
      return h('tr', {},
        h('td', {}, index === 0 ? 'Origine' : `P${index + 1}`),
        h('td', {}, h('input', { type: 'number', step: UNITS[unit].step, value: toDisplay(x, unit), readonly: fixedX, dataset: { length: '', axis: 'x' }, 'aria-label': `X du point ${index + 1}` })),
        h('td', {}, h('input', { type: 'number', step: UNITS[unit].step, value: toDisplay(z, unit), readonly: fixedZ, dataset: { length: '', axis: 'z' }, 'aria-label': `Z du point ${index + 1}` })),
        h('td', {}, index > 2 && h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Supprimer ce point', onclick: (e) => { e.currentTarget.closest('tr').remove(); renumber(); update(); } }, icon('x'))),
      );
    };
    const renumber = () => [...pointsBody.rows].forEach((row, i) => { row.cells[0].textContent = i === 0 ? 'Origine' : `P${i + 1}`; });
    const fillPoints = (points) => pointsBody.replaceChildren(...points.map(([x, z], i) => pointRow(x, z, i)));
    fillPoints(outline);

    const readOutline = () => {
      if (mode === 'rect') {
        const l = fromDisplay(length.value, unit); const d = fromDisplay(depth.value, unit);
        return [[0, 0], [l, 0], [l, d], [0, d]];
      }
      return [...pointsBody.rows].map((row) => {
        const [x, z] = row.querySelectorAll('input');
        return [fromDisplay(x.value, unit), fromDisplay(z.value, unit)];
      });
    };

    const rectFields = h('div', { class: 'form-grid' },
      field('Longueur du mur de la porte (X)', length),
      field('Profondeur de la salle (Z)', depth));
    const customFields = h('div', {},
      h('p', { class: 'field-hint' }, "Points du contour intérieur, dans l'ordre. Le segment origine → P2 est le mur de la porte, sur l'axe X."),
      h('table', { class: 'points-table' }, h('thead', {}, h('tr', {}, h('th', {}, 'Point'), h('th', {}, `X (${unit})`), h('th', {}, `Z (${unit})`), h('th', {}))), pointsBody),
      h('button', { class: 'btn btn-small', type: 'button', onclick: () => {
        const pts = readOutline();
        const last = pts[pts.length - 1];
        pointsBody.append(pointRow(0, last[1], pts.length));
        update();
      } }, 'Ajouter un point'));

    const modeSwitch = h('div', { class: 'segmented', role: 'radiogroup', 'aria-label': 'Forme de la salle' },
      ['rect', 'custom'].map((m) => h('button', {
        type: 'button', role: 'radio', class: 'segmented-option', 'aria-checked': String(m === mode),
        onclick: () => {
          if (m === mode) return;
          const pts = readOutline();
          mode = m;
          if (m === 'custom') fillPoints(pts);
          else if (isRectangle(pts)) { length.value = toDisplay(pts[1][0], unit); depth.value = toDisplay(pts[2][1], unit); }
          modeSwitch.querySelectorAll('[role=radio]').forEach((b, i) => b.setAttribute('aria-checked', String(['rect', 'custom'][i] === mode)));
          update();
        },
      }, m === 'rect' ? 'Rectangulaire' : 'Contour libre')));

    const doorInfo = h('p', { class: 'field-hint' });
    const update = () => {
      rectFields.hidden = mode !== 'rect';
      customFields.hidden = mode !== 'custom';
      const pts = readOutline();
      const door = { offset: fromDisplay(doorOffset.value, unit), width: fromDisplay(doorWidth.value, unit) };
      drawPreview(preview, pts, door);
      if (Number.isFinite(pts[1]?.[0])) {
        doorInfo.textContent = `Mur de la porte : ${formatLength(wallLength(pts, 0), unit)}. La porte est mesurée depuis l'origine (coin gauche, vu de l'intérieur).`;
      }
    };

    unitSelect.addEventListener('change', () => {
      const previous = unit;
      unit = unitSelect.value;
      form.querySelectorAll('[data-length]').forEach((input) => {
        const mm = fromDisplay(input.value, previous);
        input.step = UNITS[unit].step;
        if (Number.isFinite(mm)) input.value = toDisplay(mm, unit);
      });
      form.querySelectorAll('.unit-suffix').forEach((el) => { el.textContent = unit; });
      customFields.querySelectorAll('th')[1].textContent = `X (${unit})`;
      customFields.querySelectorAll('th')[2].textContent = `Z (${unit})`;
      update();
    });

    const form = h('form', { class: 'dialog-body', novalidate: true },
      h('div', { class: 'form-grid' }, field('Nom', name), field('Étiquette', label, 'Code court affiché, ex. « B204 ».')),
      field('Description', description),
      h('div', { class: 'form-grid form-grid-3' }, field('Unité d\'affichage', unitSelect), field(h('span', {}, 'Hauteur sous plafond (', h('span', { class: 'unit-suffix' }, unit), ')'), height), field(h('span', {}, 'Épaisseur des murs (', h('span', { class: 'unit-suffix' }, unit), ')'), wall)),
      h('fieldset', { class: 'form-section' },
        h('legend', {}, 'Forme et dimensions intérieures'),
        modeSwitch,
        h('div', { class: 'field' }, outlineError),
        h('div', { class: 'room-shape' }, h('div', {}, rectFields, customFields), preview)),
      h('fieldset', { class: 'form-section' },
        h('legend', {}, 'Porte de référence (origine du repère)'),
        doorInfo,
        h('div', { class: 'form-grid form-grid-3' },
          field('Distance depuis l\'origine', doorOffset), field('Largeur', doorWidth), field('Hauteur', doorHeight))),
      h('fieldset', { class: 'form-section' },
        h('legend', {}, 'Apparence'),
        h('div', { class: 'form-grid form-grid-3' }, field('Sol', floorColor), field('Revêtement', material), field('Murs', wallColor))),
      h('div', { class: 'dialog-actions' },
        h('button', { class: 'btn', type: 'button', onclick: () => close(undefined) }, 'Annuler'),
        h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, room ? 'Enregistrer la salle' : 'Créer la salle'))),
    );
    form.addEventListener('input', update);
    update();

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const body = {
        name: name.value, label: label.value, description: description.value, display_unit: unit,
        outline: readOutline(),
        height_mm: fromDisplay(height.value, unit), wall_thickness_mm: fromDisplay(wall.value, unit),
        door_offset_mm: fromDisplay(doorOffset.value, unit), door_width_mm: fromDisplay(doorWidth.value, unit),
        door_height_mm: fromDisplay(doorHeight.value, unit),
        floor_color: floorColor.value, floor_material: material.value, wall_color: wallColor.value,
      };
      busy(event.submitter, async () => {
        try {
          const result = await api(room ? `/rooms/${room.id}` : '/rooms', { method: room ? 'PATCH' : 'POST', body });
          toast(room ? 'Salle enregistrée.' : 'Salle créée.');
          close(result);
        } catch (error) {
          showError(error, form);
        }
      });
    });
    return form;
  }, { wide: true });
}

const KIND_LABELS = { door: 'Porte', window: 'Fenêtre', opening: 'Passage' };

/** Gestion des ouvertures supplémentaires. onChange reçoit la nouvelle charge utile de la salle. */
export function openOpeningsDialog(payload, onChange) {
  const { room } = payload;
  let openings = payload.openings;
  const unit = room.display_unit;

  return openDialog(`Ouvertures de « ${room.name} »`, (close) => {
    const list = h('div', { class: 'openings-list' });
    const formSlot = h('div');

    const renderList = () => {
      list.replaceChildren(
        h('p', { class: 'field-hint' }, `La porte de référence est sur le mur 1, à ${formatLength(room.door_offset_mm, unit)} de l'origine. Elle se règle dans les paramètres de la salle.`),
        openings.length === 0 ? emptyState('Ajoutez fenêtres, portes et passages pour un rendu fidèle.', { iconName: 'door' }) : h('ul', { class: 'plain-list' }, openings.map((o) => h('li', { class: 'list-row' },
          h('span', {}, h('strong', {}, `${KIND_LABELS[o.kind]}${o.name ? ` « ${o.name} »` : ''}`),
            ` · mur ${o.wall_index + 1}, à ${formatLength(o.offset_mm, unit)}, ${formatLength(o.width_mm, unit)} × ${formatLength(o.height_mm, unit)}, allège ${formatLength(o.sill_mm, unit)}`),
          h('span', { class: 'list-row-actions' },
            h('button', { class: 'btn btn-small', type: 'button', onclick: () => renderForm(o) }, 'Modifier'),
            h('button', { class: 'btn btn-small btn-danger-ghost', type: 'button', onclick: async () => {
              if (!await confirmDialog({ title: 'Supprimer l\'ouverture', message: 'Supprimer cette ouverture ?', confirmLabel: 'Supprimer', danger: true })) return;
              try {
                const result = await api(`/openings/${o.id}`, { method: 'DELETE' });
                openings = result.openings;
                onChange(result);
                renderList();
              } catch (error) { showError(error); }
            } }, 'Supprimer')))),
        ),
        h('button', { class: 'btn btn-primary', type: 'button', onclick: () => renderForm(null) }, icon('plus'), 'Ajouter une ouverture'),
      );
    };

    const renderForm = (o) => {
      const kind = h('select', { name: 'kind' }, Object.entries(KIND_LABELS).map(([v, t]) => h('option', { value: v, selected: v === (o?.kind ?? 'window') }, t)));
      const oname = h('input', { name: 'name', maxlength: 80, value: o?.name ?? '' });
      const wallSelect = h('select', { name: 'wall_index' }, room.outline.map((_, i) => h('option', { value: i, selected: i === (o?.wall_index ?? 1) },
        `Mur ${i + 1}${i === 0 ? ' (porte)' : ''} · ${formatLength(wallLength(room.outline, i), unit)}`)));
      const offset = lengthInput('offset_mm', o?.offset_mm ?? 500, unit);
      const width = lengthInput('width_mm', o?.width_mm ?? 1200, unit);
      const oheight = lengthInput('height_mm', o?.height_mm ?? 1200, unit);
      const sill = lengthInput('sill_mm', o?.sill_mm ?? 900, unit);
      const form = h('form', { class: 'card', novalidate: true },
        h('h3', {}, o ? 'Modifier l\'ouverture' : 'Nouvelle ouverture'),
        h('div', { class: 'form-grid' }, field('Type', kind), field('Nom', oname)),
        field('Mur', wallSelect, "Distance mesurée depuis le début du mur (sens du contour)."),
        h('div', { class: 'form-grid' }, field(`Distance (${unit})`, offset), field(`Largeur (${unit})`, width),
          field(`Hauteur (${unit})`, oheight), field(`Allège / hauteur du bas (${unit})`, sill)),
        h('div', { class: 'dialog-actions' },
          h('button', { class: 'btn', type: 'button', onclick: () => formSlot.replaceChildren() }, 'Annuler'),
          h('button', { class: 'btn btn-primary', type: 'submit' }, h('span', {}, 'Enregistrer l’ouverture'))),
      );
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const body = {
          kind: kind.value, name: oname.value, wall_index: Number(wallSelect.value),
          offset_mm: fromDisplay(offset.value, unit), width_mm: fromDisplay(width.value, unit),
          height_mm: fromDisplay(oheight.value, unit), sill_mm: fromDisplay(sill.value, unit),
        };
        busy(event.submitter, async () => {
          try {
            const result = await api(o ? `/openings/${o.id}` : `/rooms/${room.id}/openings`, { method: o ? 'PATCH' : 'POST', body });
            openings = result.openings;
            onChange(result);
            toast('Ouverture enregistrée.');
            formSlot.replaceChildren();
            renderList();
          } catch (error) { showError(error, form); }
        });
      });
      formSlot.replaceChildren(form);
    };

    renderList();
    return h('div', { class: 'dialog-body' }, list, formSlot,
      h('div', { class: 'dialog-actions' }, h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Fermer')));
  }, { wide: true });
}
