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
  const a = (rotationDeg * Math.PI) / 180;
  const cos = Math.cos(a);
  const sin = Math.sin(a);
  return [[0, 0], [width, 0], [width, depth], [0, depth]].map(([lx, lz]) => [x + lx * cos + lz * sin, z - lx * sin + lz * cos]);
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
