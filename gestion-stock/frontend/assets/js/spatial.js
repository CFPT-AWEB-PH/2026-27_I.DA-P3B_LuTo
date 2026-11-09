// Conventions spatiales partagées avec le serveur (backend/src/Space/Geometry.php).
// Longueurs en millimètres entiers. Repère de salle : origine au coin du mur de la porte, au sol ;
// X le long du mur de la porte, Y vers le haut, Z vers l'intérieur de la salle.
// Repère d'un rangement : coin arrière-gauche-bas ; x vers la droite, y vers le haut, z vers l'avant.

export const UNITS = {
  mm: { factor: 1, decimals: 0, step: 1 },
  cm: { factor: 10, decimals: 1, step: 0.1 },
  m: { factor: 1000, decimals: 3, step: 0.001 },
};

const EPS = 0.5;

export function toDisplay(mm, unit) {
  return Number((mm / UNITS[unit].factor).toFixed(UNITS[unit].decimals));
}

/** Valeur saisie dans l'unité d'affichage (virgule acceptée) vers des millimètres entiers. */
export function fromDisplay(value, unit) {
  const number = typeof value === 'number' ? value : Number(String(value).replace(',', '.').trim());
  return Number.isFinite(number) ? Math.round(number * UNITS[unit].factor) : NaN;
}

export function formatLength(mm, unit) {
  const value = (mm / UNITS[unit].factor).toLocaleString('fr-CH', { maximumFractionDigits: UNITS[unit].decimals });
  return `${value} ${unit}`;
}

export const SHAPE_LABELS = {
  cabinet: 'Meuble à façade ouverte',
  compartment: 'Compartiment',
  cell: 'Case',
  drawer: 'Tiroir',
  box: 'Boîte',
  bin: 'Bac',
  shelf: "Niveau d'étagère",
  divider: 'Séparateur',
};

/** Volume intérieur utilisable (null si aucun). */
export function interior(shape, width, depth, height, panel) {
  let box;
  switch (shape) {
    case 'cabinet': case 'compartment': case 'cell':
      box = [width - 2 * panel, depth - panel, height - 2 * panel, panel, panel, panel]; break;
    case 'drawer': case 'box': case 'bin':
      box = [width - 2 * panel, depth - 2 * panel, height - panel, panel, panel, panel]; break;
    case 'shelf':
      box = [width, depth, height - panel, 0, panel, 0]; break;
    default:
      return null;
  }
  if (Math.min(box[0], box[1], box[2]) <= 0) return null;
  return { width: box[0], depth: box[1], height: box[2], x: box[3], y: box[4], z: box[5] };
}

export function unitInterior(unit) {
  return interior(unit.shape, unit.width_mm, unit.depth_mm, unit.height_mm, unit.panel_mm);
}

/** Coins de l'emprise au sol d'un meuble (rotation autour du coin arrière-gauche, sens Three.js). */
export function footprint(x, z, width, depth, rotationDeg) {
  const { cos, sin } = rotation(rotationDeg);
  return [[0, 0], [width, 0], [width, depth], [0, depth]].map(([lx, lz]) => [x + lx * cos + lz * sin, z - lx * sin + lz * cos]);
}

/**
 * Cosinus et sinus d'un angle en degrés, exacts aux angles droits : cos(90°) vaut 6e-17 en virgule
 * flottante, ce qui rendait décimales des positions que le serveur n'accepte qu'entières.
 */
export function rotation(degrees) {
  const a = (degrees * Math.PI) / 180;
  return { cos: Math.round(Math.cos(a) * 1e9) / 1e9, sin: Math.round(Math.sin(a) * 1e9) / 1e9 };
}

/** Garde un rangement dans le volume intérieur de son parent, en le ramenant à la butée. */
export function clampToParent(c, parent) {
  const inside = unitInterior(parent);
  if (!inside) return c;
  const out = { ...c };
  for (const [size, pos, room] of [['width_mm', 'pos_x_mm', 'width'], ['height_mm', 'pos_y_mm', 'height'], ['depth_mm', 'pos_z_mm', 'depth']]) {
    out[size] = Math.min(out[size], inside[room]);
    out[pos] = Math.max(0, Math.min(out[pos], inside[room] - out[size]));
  }
  return out;
}

/** Garde un meuble dans l'enveloppe de la salle et sous son plafond. */
export function clampToRoom(c, room) {
  const out = { ...c };
  const corners = unitFootprint(out);
  const xs = corners.map((p) => p[0]); const zs = corners.map((p) => p[1]);
  const oxs = room.outline.map((p) => p[0]); const ozs = room.outline.map((p) => p[1]);
  const shift = (min, max, lo, hi) => (max - min > hi - lo ? 0 : min < lo ? lo - min : max > hi ? hi - max : 0);
  out.pos_x_mm = Math.round(out.pos_x_mm + shift(Math.min(...xs), Math.max(...xs), Math.min(...oxs), Math.max(...oxs)));
  out.pos_z_mm = Math.round(out.pos_z_mm + shift(Math.min(...zs), Math.max(...zs), Math.min(...ozs), Math.max(...ozs)));
  out.pos_y_mm = Math.max(0, Math.min(out.pos_y_mm, room.height_mm - out.height_mm));
  return out;
}

// ===== Ouvertures et position sur le plan =====

export function isRectangle(outline) {
  if (outline.length !== 4) return false;
  const [[a, b], [c, d], [e, f], [g, k]] = outline;
  return a === 0 && b === 0 && d === 0 && c === e && f === k && g === 0 && c > 0 && f > 0;
}

export function outlineSize(outline) {
  const xs = outline.map((p) => p[0]); const zs = outline.map((p) => p[1]);
  return { minX: Math.min(...xs), maxX: Math.max(...xs), minZ: Math.min(...zs), maxZ: Math.max(...zs), width: Math.max(...xs) - Math.min(...xs), depth: Math.max(...zs) - Math.min(...zs) };
}

/** Mur le plus proche d'un point du repère de la salle : indice et distance depuis son début. */
export function nearestWall(outline, x, z, onlyWall = null) {
  let best = null;
  outline.forEach(([x1, z1], i) => {
    if (onlyWall !== null && i !== onlyWall) return;
    const [x2, z2] = outline[(i + 1) % outline.length];
    const dx = x2 - x1; const dz = z2 - z1;
    const lengthSq = dx * dx + dz * dz;
    if (lengthSq === 0) return;
    const t = Math.max(0, Math.min(1, ((x - x1) * dx + (z - z1) * dz) / lengthSq));
    const distance = Math.hypot(x - (x1 + t * dx), z - (z1 + t * dz));
    if (!best || distance < best.distance) best = { index: i, along: t * Math.sqrt(lengthSq), distance, length: Math.sqrt(lengthSq) };
  });
  return best;
}

/** Point d'un mur et direction du mur, dans le repère de la salle. */
export function wallFrame(outline, index, along) {
  const [x1, z1] = outline[index];
  const [x2, z2] = outline[(index + 1) % outline.length];
  const length = Math.hypot(x2 - x1, z2 - z1) || 1;
  const ux = (x2 - x1) / length; const uz = (z2 - z1) / length;
  return { x: x1 + ux * along, z: z1 + uz * along, ux, uz, length };
}

/** La porte de référence, présentée comme une ouverture : elle est sur le mur 1 (repère de la salle). */
export function referenceDoor(room) {
  return { id: 'ref', room_id: room.id, kind: 'door', name: 'Porte principale', wall_index: 0, offset_mm: room.door_offset_mm, width_mm: room.door_width_mm, height_mm: room.door_height_mm, sill_mm: 0 };
}

/** Pourquoi cette ouverture ne convient pas (mêmes règles que SpaceValidator::opening), ou null. */
export function openingProblem(room, openings, o) {
  const fmt = (mm) => formatLength(mm, room.display_unit);
  if (o.wall_index >= room.outline.length) return "Ce mur n'existe pas.";
  const length = wallLength(room.outline, o.wall_index);
  if (o.offset_mm < 0 || o.offset_mm + o.width_mm > length) return `Dépasse du mur (longueur : ${fmt(length)}).`;
  if (o.sill_mm < 0 || o.sill_mm + o.height_mm > room.height_mm) return "Dépasse la hauteur de la salle.";
  const all = [referenceDoor(room), ...openings];
  const other = all.find((x) => x.id !== o.id && x.wall_index === o.wall_index
    && rangesOverlap(o.offset_mm, o.offset_mm + o.width_mm, x.offset_mm, x.offset_mm + x.width_mm)
    && rangesOverlap(o.sill_mm, o.sill_mm + o.height_mm, x.sill_mm, x.sill_mm + x.height_mm));
  return other ? `Chevauche « ${other.name || 'une ouverture'} ».` : null;
}

/** Première place libre sur un mur pour une ouverture de cette largeur (null : mur plein). */
export function freeOffset(room, openings, wallIndex, opening) {
  const length = wallLength(room.outline, wallIndex);
  for (let offset = 200; offset + opening.width_mm <= length - 100; offset += 100) {
    if (!openingProblem(room, openings, { ...opening, wall_index: wallIndex, offset_mm: offset })) return offset;
  }
  return null;
}

/** Nouvelle position d'une ouverture tirée vers ce point : mur le plus proche, centrée sur le point, par pas de 1 cm. */
export function slideOpening(room, o, x, y, z) {
  // La porte principale reste sur le mur 1 : c'est elle qui définit le repère de la salle
  const wall = nearestWall(room.outline, x, z, o.id === 'ref' ? 0 : null);
  const step = (v) => Math.round(v / 10) * 10;
  const offset = Math.max(0, Math.min(step(wall.along - o.width_mm / 2), Math.floor(wall.length) - o.width_mm));
  const sill = o.kind === 'window' ? Math.max(0, Math.min(step(y - o.height_mm / 2), room.height_mm - o.height_mm)) : o.sill_mm;
  return { ...o, wall_index: wall.index, offset_mm: offset, sill_mm: sill };
}

/**
 * Plus grande zone libre au fond d'un rangement entre ses subdivisions : c'est là que les objets rangés
 * directement dedans sont montrés, comme un marteau posé à côté des bacs d'un tiroir.
 */
export function freeFloorArea(inside, kids) {
  const floor = kids.filter((k) => k.pos_y_mm < inside.height);
  if (floor.length === 0) return { x: 0, z: 0, width: inside.width, depth: inside.depth };
  const xs = [...new Set([0, inside.width, ...floor.flatMap((k) => [k.pos_x_mm, k.pos_x_mm + k.width_mm])])].sort((a, b) => a - b);
  const zs = [...new Set([0, inside.depth, ...floor.flatMap((k) => [k.pos_z_mm, k.pos_z_mm + k.depth_mm])])].sort((a, b) => a - b);
  let best = { x: 0, z: 0, width: 0, depth: 0 };
  for (let a = 0; a < xs.length - 1; a++) {
    for (let b = a + 1; b < xs.length; b++) {
      for (let c = 0; c < zs.length - 1; c++) {
        for (let d = c + 1; d < zs.length; d++) {
          const area = (xs[b] - xs[a]) * (zs[d] - zs[c]);
          if (area <= best.width * best.depth) continue;
          const blocked = floor.some((k) => k.pos_x_mm < xs[b] && k.pos_x_mm + k.width_mm > xs[a] && k.pos_z_mm < zs[d] && k.pos_z_mm + k.depth_mm > zs[c]);
          if (!blocked) best = { x: xs[a], z: zs[c], width: xs[b] - xs[a], depth: zs[d] - zs[c] };
        }
      }
    }
  }
  return best;
}

/** Repère de la salle → plan de l'étage. */
export function roomToPlan(room, x, z) {
  const { cos, sin } = rotation(room.plan_rotation_deg ?? 0);
  return [(room.plan_x_mm ?? 0) + x * cos + z * sin, (room.plan_z_mm ?? 0) - x * sin + z * cos];
}

/** Plan de l'étage → repère de la salle. */
export function planToRoom(room, x, z) {
  const { cos, sin } = rotation(room.plan_rotation_deg ?? 0);
  const dx = x - (room.plan_x_mm ?? 0); const dz = z - (room.plan_z_mm ?? 0);
  return [dx * cos - dz * sin, dx * sin + dz * cos];
}

/** Enveloppe d'une salle sur le plan. */
export function planBounds(room) {
  const points = room.outline.map(([x, z]) => roomToPlan(room, x, z));
  const xs = points.map((p) => p[0]); const zs = points.map((p) => p[1]);
  return { minX: Math.min(...xs), maxX: Math.max(...xs), minZ: Math.min(...zs), maxZ: Math.max(...zs) };
}

export function unitFootprint(u) {
  return footprint(u.pos_x_mm, u.pos_z_mm, u.width_mm, u.depth_mm, u.rotation_deg);
}

/** Position du coin qui garde le centre de l'emprise immobile lors d'un changement de rotation. */
export function rotateAroundCenter(u, newRotation) {
  const corners = unitFootprint(u);
  const cx = (corners[0][0] + corners[2][0]) / 2;
  const cz = (corners[0][1] + corners[2][1]) / 2;
  const [hx, hz] = footprint(0, 0, u.width_mm, u.depth_mm, newRotation)[2];
  return { pos_x_mm: Math.round(cx - hx / 2), pos_z_mm: Math.round(cz - hz / 2), rotation_deg: ((newRotation % 360) + 360) % 360 };
}

export function wallLength(outline, index) {
  const [x1, z1] = outline[index];
  const [x2, z2] = outline[(index + 1) % outline.length];
  return Math.hypot(x2 - x1, z2 - z1);
}

export function polygonArea(outline) {
  let sum = 0;
  outline.forEach(([x1, z1], i) => {
    const [x2, z2] = outline[(i + 1) % outline.length];
    sum += x1 * z2 - x2 * z1;
  });
  return Math.abs(sum) / 2;
}

function distanceToSegment(px, pz, x1, z1, x2, z2) {
  const dx = x2 - x1;
  const dz = z2 - z1;
  const lengthSq = dx * dx + dz * dz;
  const t = lengthSq === 0 ? 0 : Math.max(0, Math.min(1, ((px - x1) * dx + (pz - z1) * dz) / lengthSq));
  return Math.hypot(px - (x1 + t * dx), pz - (z1 + t * dz));
}

export function containsPoint(polygon, x, z) {
  let inside = false;
  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const [xi, zi] = polygon[i];
    const [xj, zj] = polygon[j];
    if (distanceToSegment(x, z, xi, zi, xj, zj) <= EPS) return true;
    if ((zi > z) !== (zj > z) && x < ((xj - xi) * (z - zi)) / (zj - zi) + xi) inside = !inside;
  }
  return inside;
}

function orientation(a, b, c) {
  return (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
}

function segmentsCross(a, b, c, d) {
  const o1 = orientation(a, b, c); const o2 = orientation(a, b, d);
  const o3 = orientation(c, d, a); const o4 = orientation(c, d, b);
  const t = 1e-6;
  return ((o1 > t && o2 < -t) || (o1 < -t && o2 > t)) && ((o3 > t && o4 < -t) || (o3 < -t && o4 > t));
}

export function containsPolygon(outer, inner) {
  if (!inner.every(([x, z]) => containsPoint(outer, x, z))) return false;
  for (let i = 0; i < outer.length; i++) {
    for (let j = 0; j < inner.length; j++) {
      if (segmentsCross(outer[i], outer[(i + 1) % outer.length], inner[j], inner[(j + 1) % inner.length])) return false;
    }
  }
  return true;
}

function project(polygon, nx, nz) {
  let min = Infinity; let max = -Infinity;
  for (const [x, z] of polygon) {
    const p = x * nx + z * nz;
    min = Math.min(min, p); max = Math.max(max, p);
  }
  return [min, max];
}

export function convexOverlap(a, b) {
  for (const polygon of [a, b]) {
    for (let i = 0; i < polygon.length; i++) {
      const [x1, z1] = polygon[i];
      const [x2, z2] = polygon[(i + 1) % polygon.length];
      const length = Math.hypot(z1 - z2, x2 - x1);
      if (length === 0) continue;
      const [minA, maxA] = project(a, (z1 - z2) / length, (x2 - x1) / length);
      const [minB, maxB] = project(b, (z1 - z2) / length, (x2 - x1) / length);
      if (maxA <= minB + EPS || maxB <= minA + EPS) return false;
    }
  }
  return true;
}

export function rangesOverlap(a1, a2, b1, b2) {
  return a1 < b2 - EPS && b1 < a2 - EPS;
}

/** Pré-contrôle côté client d'un meuble (le serveur reste seul juge). */
export function furnitureFits(room, units, candidate) {
  const corners = unitFootprint(candidate);
  if (!containsPolygon(room.outline, corners)) return false;
  if (candidate.pos_y_mm < 0 || candidate.pos_y_mm + candidate.height_mm > room.height_mm) return false;
  return !units.some((o) => o.parent_id === null && o.id !== candidate.id
    && convexOverlap(corners, unitFootprint(o))
    && rangesOverlap(candidate.pos_y_mm, candidate.pos_y_mm + candidate.height_mm, o.pos_y_mm, o.pos_y_mm + o.height_mm));
}

/** Pré-contrôle d'un rangement dans l'intérieur de son parent. */
export function childFits(parent, siblings, c) {
  const inside = unitInterior(parent);
  if (!inside) return false;
  if (c.pos_x_mm < 0 || c.pos_y_mm < 0 || c.pos_z_mm < 0) return false;
  if (c.pos_x_mm + c.width_mm > inside.width || c.pos_y_mm + c.height_mm > inside.height || c.pos_z_mm + c.depth_mm > inside.depth) return false;
  return !siblings.some((o) => o.id !== c.id
    && rangesOverlap(c.pos_x_mm, c.pos_x_mm + c.width_mm, o.pos_x_mm, o.pos_x_mm + o.width_mm)
    && rangesOverlap(c.pos_y_mm, c.pos_y_mm + c.height_mm, o.pos_y_mm, o.pos_y_mm + o.height_mm)
    && rangesOverlap(c.pos_z_mm, c.pos_z_mm + c.depth_mm, o.pos_z_mm, o.pos_z_mm + o.depth_mm));
}

/** Premier emplacement libre pour un nouveau rangement (heuristique ; null si aucun trouvé). */
export function findFreeSpot(room, units, parent, size) {
  const siblings = units.filter((u) => u.parent_id === (parent?.id ?? null));
  if (parent) {
    const xs = [0, ...siblings.map((s) => s.pos_x_mm + s.width_mm)];
    const ys = [0, ...siblings.map((s) => s.pos_y_mm + s.height_mm)];
    for (const y of ys.sort((a, b) => a - b)) {
      for (const x of xs.sort((a, b) => a - b)) {
        const c = { ...size, pos_x_mm: x, pos_y_mm: y, pos_z_mm: 0 };
        if (childFits(parent, siblings, c)) return c;
      }
    }
    return null;
  }
  // Meuble : le long des murs existants puis en grille, en évitant le passage de la porte
  const xs = [0, ...siblings.map((s) => Math.max(...unitFootprint(s).map((p) => p[0]))), room.door_offset_mm + room.door_width_mm + 100];
  const zs = [0, ...siblings.map((s) => Math.max(...unitFootprint(s).map((p) => p[1])))];
  const maxX = Math.max(...room.outline.map((p) => p[0]));
  const maxZ = Math.max(...room.outline.map((p) => p[1]));
  for (let x = 0; x < maxX; x += 250) xs.push(x);
  for (let z = 0; z < maxZ; z += 250) zs.push(z);
  const door = [[room.door_offset_mm, 0], [room.door_offset_mm + room.door_width_mm, 0],
    [room.door_offset_mm + room.door_width_mm, room.door_width_mm], [room.door_offset_mm, room.door_width_mm]];
  for (const z of [...new Set(zs)].sort((a, b) => a - b)) {
    for (const x of [...new Set(xs)].sort((a, b) => a - b)) {
      const c = { ...size, id: null, parent_id: null, pos_x_mm: x, pos_y_mm: 0, pos_z_mm: z, rotation_deg: 0 };
      if (furnitureFits(room, units, c) && !convexOverlap(unitFootprint(c), door)) return c;
    }
  }
  return null;
}

/**
 * Pourquoi cet emplacement est impossible (texte pour l'utilisateur), ou null s'il convient.
 * Mêmes règles que le serveur (SpaceValidator), pour réagir en direct ; le serveur reste seul juge.
 */
export function unitProblem(room, units, parent, c) {
  const fmt = (mm) => formatLength(mm, room.display_unit);
  if (![c.width_mm, c.depth_mm, c.height_mm].every((v) => Number.isFinite(v) && v >= 1)) return 'Indiquez les trois dimensions.';
  if (![c.pos_x_mm, c.pos_y_mm, c.pos_z_mm].every(Number.isFinite)) return 'Indiquez la position.';
  if (Math.max(c.width_mm, c.depth_mm, c.height_mm) > 50000) return `Dimension trop grande : 50 m au maximum (${fmt(Math.max(c.width_mm, c.depth_mm, c.height_mm))} indiqués).`;
  if (c.shape !== 'divider' && !interior(c.shape, c.width_mm, c.depth_mm, c.height_mm, c.panel_mm)) return 'Parois trop épaisses : il ne reste aucun volume intérieur.';

  if (!parent) {
    const corners = unitFootprint(c);
    if (!containsPolygon(room.outline, corners)) return 'Le meuble sort de la salle.';
    if (c.pos_y_mm < 0) return 'Le meuble passe sous le sol.';
    if (c.pos_y_mm + c.height_mm > room.height_mm) return `Plus haut que la salle (${fmt(room.height_mm)}).`;
    const other = units.find((o) => o.parent_id === null && o.id !== c.id
      && convexOverlap(corners, unitFootprint(o))
      && rangesOverlap(c.pos_y_mm, c.pos_y_mm + c.height_mm, o.pos_y_mm, o.pos_y_mm + o.height_mm));
    return other ? `Chevauche « ${other.name} ».` : null;
  }

  const inside = unitInterior(parent);
  if (!inside) return `« ${parent.name} » n'a pas de volume intérieur.`;
  if (c.pos_x_mm < 0 || c.pos_y_mm < 0 || c.pos_z_mm < 0) return `Sort de « ${parent.name} ».`;
  for (const [size, pos, room2, word] of [['width_mm', 'pos_x_mm', 'width', 'en largeur'], ['height_mm', 'pos_y_mm', 'height', 'en hauteur'], ['depth_mm', 'pos_z_mm', 'depth', 'en profondeur']]) {
    const excess = c[pos] + c[size] - inside[room2];
    if (excess > 0) return `Dépasse de « ${parent.name} » ${word} de ${fmt(excess)} (${fmt(inside[room2])} disponibles).`;
  }
  const other = units.find((o) => o.parent_id === parent.id && o.id !== c.id
    && rangesOverlap(c.pos_x_mm, c.pos_x_mm + c.width_mm, o.pos_x_mm, o.pos_x_mm + o.width_mm)
    && rangesOverlap(c.pos_y_mm, c.pos_y_mm + c.height_mm, o.pos_y_mm, o.pos_y_mm + o.height_mm)
    && rangesOverlap(c.pos_z_mm, c.pos_z_mm + c.depth_mm, o.pos_z_mm, o.pos_z_mm + o.depth_mm));
  return other ? `Chevauche « ${other.name} ».` : null;
}

/** Aperçu de la grille que créera le serveur (StorageUnitController::generate). */
export function gridLayout(parent, type, rows, columns, gap, labels = []) {
  const inside = unitInterior(parent);
  if (!inside) return [];
  const flat = ['drawer', 'box', 'bin'].includes(parent.shape);
  const width = Math.floor((inside.width - (columns - 1) * gap) / columns);
  const depth = flat ? Math.floor((inside.depth - (rows - 1) * gap) / rows) : inside.depth;
  const height = flat ? Math.min(type.default_height_mm, inside.height) : Math.floor((inside.height - (rows - 1) * gap) / rows);
  if (width < 10 || height < 10 || depth < 10) return [];
  const panel = Math.min(type.default_panel_mm, Math.floor(Math.min(width, depth, height) / 4));
  const drafts = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < columns; c++) {
      const number = columns === 1 ? r + 1 : rows === 1 ? c + 1 : `${r + 1}.${c + 1}`;
      drafts.push({
        id: -100 - drafts.length, parent_id: parent.id, shape: type.shape, name: `${type.name} ${number}`, label: (labels[drafts.length] ?? '').trim(),
        width_mm: width, depth_mm: depth, height_mm: height, panel_mm: panel, color: type.default_color, rotation_deg: 0,
        pos_x_mm: c * (width + gap), pos_y_mm: flat ? 0 : (rows - 1 - r) * (height + gap), pos_z_mm: flat ? r * (depth + gap) : 0,
      });
    }
  }
  return drafts;
}

/** Aperçu d'une pile de rangements nommés, de haut en bas. */
export function stackLayout(parent, type, entries, gap) {
  const inside = unitInterior(parent);
  if (!inside) return [];
  let top = inside.height;
  return entries.map((entry, i) => {
    top -= entry.height_mm;
    const draft = {
      id: -100 - i, parent_id: parent.id, shape: type.shape, name: entry.name, label: '',
      width_mm: inside.width, depth_mm: inside.depth, height_mm: entry.height_mm, rotation_deg: 0,
      panel_mm: Math.min(type.default_panel_mm, Math.floor(Math.min(inside.width, inside.depth, entry.height_mm) / 4)),
      color: type.default_color, pos_x_mm: 0, pos_y_mm: top, pos_z_mm: 0,
    };
    top -= gap;
    return draft;
  });
}

/**
 * Aimantation : ramène une position sur un bord voisin (mur, parent, autre rangement) si elle en est proche.
 * Sur chaque axe, teste les deux faces du rangement contre les repères fournis.
 */
export function snapToEdges(position, size, edges, threshold) {
  let best = null;
  for (const edge of edges) {
    for (const candidate of [edge, edge - size]) {
      const distance = Math.abs(candidate - position);
      if (distance <= threshold && (best === null || distance < Math.abs(best - position))) best = candidate;
    }
  }
  return best ?? position;
}
