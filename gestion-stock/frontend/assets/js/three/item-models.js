// Objets affichés dans les rangements : modèles légers en millimètres, dessinés en instances
// (un seul appel de rendu par objet, quel que soit le nombre de pièces affichées).
// Le nombre de pièces visibles suit la quantité jusqu'à un plafond : c'est une représentation,
// la quantité exacte reste celle du panneau de propriétés.

import * as THREE from '/vendor/three/three.module.min.js';
import { resolveVisual, sizeFromName } from '../visuals.js';

const S = 0.001;
const STEEL = '#c9ccd2';
const DARK = '#2b2d33';
const MAX_LOOSE = 70;
const MAX_NEAT = 36;
// Petites pièces en vrac ; les autres sont rangées côte à côte
const LOOSE = new Set(['screw', 'nut', 'washer', 'resistor', 'capacitor', 'chip', 'box']);
const DEFAULT_TINT = {
  capacitor: '#27408b', chip: '#26282e', wire: '#30333a', screwdriver: '#e0632a', pliers: '#2f9aa8',
  wrench: STEEL, hammer: '#c8a06a', beam: '#2f7fd6', wheel: '#2f7fd6', box: '#8b909a', screw: STEEL, nut: STEEL, washer: STEEL, resistor: '#d9bd8c',
};

const alongX = (geometry) => geometry.rotateZ(Math.PI / 2);
const cylX = (radius, length, x = 0, y = 0, z = 0, segments = 8) => alongX(new THREE.CylinderGeometry(radius, radius, length, segments)).translate(x, y, z);
const box = (lx, ly, lz, x = 0, y = 0, z = 0) => new THREE.BoxGeometry(lx, ly, lz).translate(x, y, z);

/** Parties d'un modèle : [géométrie, couleur]. La couleur null prend la teinte de l'objet. */
const MODELS = {
  screw: () => [[cylX(1.5, 12, 7, 0, 0, 6), STEEL], [cylX(2.8, 1.8, 0, 0, 0, 8), STEEL]],
  nut: () => [[new THREE.TorusGeometry(2.3, 1, 4, 6).rotateX(Math.PI / 2).scale(1, 1.1, 1), null]],
  washer: () => [[new THREE.TorusGeometry(3.4, 1.1, 3, 14).rotateX(Math.PI / 2).scale(1, 0.3, 1), null]],
  resistor: () => [
    [cylX(1.3, 6.5, 0, 0, 0, 6), null],
    [cylX(1.35, 0.7, -2, 0, 0, 6), '#7a4a1e'], [cylX(1.35, 0.7, -0.4, 0, 0, 6), '#222222'], [cylX(1.35, 0.7, 1.2, 0, 0, 6), '#c0392b'],
    [cylX(0.3, 26, -16.2, 0, 0, 4), STEEL], [cylX(0.3, 26, 16.2, 0, 0, 4), STEEL],
  ],
  capacitor: () => [
    [new THREE.CylinderGeometry(4, 4, 11, 10).translate(0, 5.5, 0), null],
    [new THREE.CylinderGeometry(3.9, 3.9, 0.4, 10).translate(0, 11.1, 0), STEEL],
  ],
  chip: () => [
    [box(20, 3.5, 7, 0, 3, 0), null],
    ...[-1, 1].map((side) => [box(17, 1, 1.5, 0, 0.5, side * 3.6), STEEL]),
  ],
  wire: () => [
    [new THREE.TorusGeometry(24, 3, 6, 18).rotateX(Math.PI / 2), null],
    [new THREE.TorusGeometry(24, 3, 6, 18).rotateX(Math.PI / 2).translate(0, 5, 0), null],
  ],
  screwdriver: () => [[cylX(11, 90, -50, 0, 0, 8), null], [cylX(2.5, 100, 45, 0, 0, 6), STEEL], [box(8, 1.5, 5, 98, 0, 0), STEEL]],
  pliers: () => [
    [box(44, 7, 16, 54, 0, 0), STEEL],
    [cylX(5, 100, -18, 0, 11, 6).rotateY(0.1), null],
    [cylX(5, 100, -18, 0, -11, 6).rotateY(-0.1), null],
  ],
  wrench: () => [
    [box(120, 3.5, 11), null],
    [new THREE.TorusGeometry(10, 3.5, 4, 10).rotateX(Math.PI / 2).translate(-66, 0, 0), null],
    [box(16, 3.5, 7, 66, 0, 8), null], [box(16, 3.5, 7, 66, 0, -8), null],
  ],
  hammer: () => [[cylX(11, 280, 0, 0, 0, 8), null], [box(30, 28, 100, 150, 0, 0), '#5a5f68']],
  beam: () => [
    [box(120, 12, 24), null],
    ...[-48, -24, 0, 24, 48].map((x) => [new THREE.CylinderGeometry(2.2, 2.2, 0.5, 6).translate(x, 6.05, 0), '#123a66']),
  ],
  wheel: () => [
    [new THREE.CylinderGeometry(30, 30, 10, 18).translate(0, 5, 0), null],
    [new THREE.CylinderGeometry(9, 9, 11, 10).translate(0, 5.5, 0), DARK],
  ],
  box: () => [[box(30, 18, 30), null], [box(26, 0.5, 26, 0, 9.1, 0), '#ffffff']],
};

const geometryCache = new Map();

/** Assemble un modèle : une géométrie avec couleurs de sommets, centrée en X-Z, posée sur y = 0. */
function modelGeometry(visual, color) {
  const key = `${visual}|${color}`;
  if (geometryCache.has(key)) return geometryCache.get(key);
  const positions = [];
  const normals = [];
  const colors = [];
  const paint = new THREE.Color();
  for (const [part, partColor] of MODELS[visual]()) {
    const flat = part.index ? part.toNonIndexed() : part;
    positions.push(...flat.attributes.position.array);
    normals.push(...flat.attributes.normal.array);
    paint.set(partColor ?? color);
    for (let i = 0; i < flat.attributes.position.count; i++) colors.push(paint.r, paint.g, paint.b);
    part.dispose();
  }
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
  geometry.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
  geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3));
  geometry.computeBoundingBox();
  const { min, max } = geometry.boundingBox;
  geometry.translate(-(min.x + max.x) / 2, -min.y, -(min.z + max.z) / 2);
  geometry.computeBoundingBox();
  geometry.userData.shared = true;
  geometry.userData.size = geometry.boundingBox.getSize(new THREE.Vector3());
  geometryCache.set(key, geometry);
  return geometry;
}

let sharedMaterial = null;
function material() {
  sharedMaterial ??= new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.5, metalness: 0.1 });
  sharedMaterial.userData.shared = true;
  return sharedMaterial;
}

/** Générateur déterministe : une boîte garde le même aspect d'un affichage à l'autre. */
function randomFrom(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Échelle du modèle (x, y, z) d'après les cotes du nom : « M3 x 12 », « Écrou M4 »… */
function scaleFor(visual, name) {
  const { diameter, length } = sizeFromName(name);
  if (visual === 'screw' && (diameter || length)) {
    const d = (diameter ?? 3) / 3;
    return new THREE.Vector3(((length ?? 12) + 1.8) / 13.8, d, d);
  }
  if ((visual === 'nut' || visual === 'washer') && diameter) return new THREE.Vector3(diameter / 3, diameter / 3, diameter / 3);
  return new THREE.Vector3(1, 1, 1);
}

const measured = new Set(['m', 'cm', 'mm', 'km', 'kg', 'g', 'l', 'ml']);

function visibleCount(entry) {
  const quantity = Number(entry.quantity) || 0;
  // Longueurs et masses : quelques bobines ou tas, pas un objet par mètre
  if (measured.has(String(entry.unit ?? '').toLowerCase())) return Math.min(3, Math.max(1, Math.ceil(quantity / 10)));
  return Math.max(1, Math.ceil(quantity));
}

function placeInCell(entry, visual, size, cell, height, random) {
  const { x, z, width, depth } = cell;
  const alongWidth = width >= depth;
  const long = alongWidth ? width : depth;
  const short = alongWidth ? depth : width;
  const baseYaw = alongWidth ? 0 : Math.PI / 2;
  const fit = Math.min(1, (long * 0.96) / size.x, (short * 0.96) / Math.max(size.z, 1));
  const lx = size.x * fit;
  const ly = size.y * fit;
  const lz = size.z * fit;
  const placements = [];
  const wanted = visibleCount(entry);

  if (LOOSE.has(visual)) {
    const elongated = lx > lz * 2;
    const footprint = Math.max(lx * lz, 1);
    const perLayer = Math.max(1, Math.floor(((long * short) / footprint) * 0.7));
    const maxLayers = Math.max(1, Math.floor((height * 0.6) / Math.max(ly * 0.7, 0.5)));
    const count = Math.min(wanted, MAX_LOOSE, perLayer * maxLayers);
    for (let i = 0; i < count; i++) {
      const yaw = elongated ? baseYaw + (random() - 0.5) * 0.7 : random() * Math.PI * 2;
      const c = Math.abs(Math.cos(yaw)); const s = Math.abs(Math.sin(yaw));
      const ex = Math.min((c * lx + s * lz) / 2, width / 2);
      const ez = Math.min((s * lx + c * lz) / 2, depth / 2);
      placements.push({
        x: x + ex + random() * (width - 2 * ex),
        z: z + ez + random() * (depth - 2 * ez),
        y: Math.floor(i / perLayer) * ly * 0.7,
        yaw, tilt: (random() - 0.5) * 0.35,
      });
    }
    return { placements, fit };
  }

  // Outils, profilés, roues : côte à côte dans le sens de la longueur, sur plusieurs couches si nécessaire
  const gap = 2;
  const perLine = Math.max(1, Math.floor((long + gap) / (lx + gap)));
  const lines = Math.max(1, Math.floor((short + gap) / (lz + gap)));
  const layers = Math.max(1, Math.min(3, Math.floor(height / Math.max(ly, 1))));
  const count = Math.min(wanted, MAX_NEAT, perLine * lines * layers);
  const usedLines = Math.min(lines, Math.ceil(count / perLine));
  const startShort = (short - usedLines * lz - (usedLines - 1) * gap) / 2;
  for (let i = 0; i < count; i++) {
    const inLayer = i % (perLine * lines);
    const line = Math.floor(inLayer / perLine);
    const pos = inLayer % perLine;
    const u = (long - perLine * lx - (perLine - 1) * gap) / 2 + pos * (lx + gap) + lx / 2;
    const v = startShort + line * (lz + gap) + lz / 2;
    placements.push({
      x: x + (alongWidth ? u : v), z: z + (alongWidth ? v : u),
      y: Math.floor(i / (perLine * lines)) * ly,
      yaw: baseYaw + (random() - 0.5) * 0.03, tilt: 0,
    });
  }
  return { placements, fit };
}

/**
 * Objets d'un rangement, dans le repère de son volume intérieur (origine : coin arrière gauche bas).
 * @param {{ width: number, depth: number, height: number }} inside dimensions intérieures en mm
 * @param {Array} entries lignes de contenu : { item_id, name, quantity, unit, visual, visual_color, category }
 * @returns {THREE.Group}
 */
export function buildObjects(unitId, inside, entries) {
  const group = new THREE.Group();
  const matrix = new THREE.Matrix4();
  const position = new THREE.Vector3();
  const quaternion = new THREE.Quaternion();
  const euler = new THREE.Euler(0, 0, 0, 'YXZ');
  const margin = Math.min(2, inside.width / 20, inside.depth / 20);
  const alongWidth = inside.width >= inside.depth;
  const usable = (alongWidth ? inside.width : inside.depth) - 2 * margin;

  entries.forEach((entry, index) => {
    // Plusieurs objets dans un même rangement : une part chacun, dans le sens de la longueur
    const share = usable / entries.length;
    const cell = alongWidth
      ? { x: margin + index * share, z: margin, width: share, depth: inside.depth - 2 * margin }
      : { x: margin, z: margin + index * share, width: inside.width - 2 * margin, depth: share };
    const visual = resolveVisual(entry);
    const geometry = modelGeometry(visual, entry.visual_color ?? DEFAULT_TINT[visual]);
    const scale = scaleFor(visual, entry.name);
    const size = geometry.userData.size.clone().multiply(scale);
    const { placements, fit } = placeInCell(entry, visual, size, cell, inside.height, randomFrom(unitId * 7919 + entry.item_id * 104729));
    if (placements.length === 0) return;

    const mesh = new THREE.InstancedMesh(geometry, material(), placements.length);
    const finalScale = scale.clone().multiplyScalar(fit * S);
    placements.forEach((p, i) => {
      position.set(p.x * S, p.y * S, p.z * S);
      euler.set(p.tilt, p.yaw, 0);
      quaternion.setFromEuler(euler);
      matrix.compose(position, quaternion, finalScale);
      mesh.setMatrixAt(i, matrix);
    });
    mesh.instanceMatrix.needsUpdate = true;
    mesh.computeBoundingSphere();
    mesh.userData.unitId = unitId;
    mesh.userData.itemId = entry.item_id;
    group.add(mesh);
  });
  return group;
}
