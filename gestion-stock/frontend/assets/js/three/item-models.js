// Objets affichés dans les rangements : modèles en millimètres, dessinés en instances
// (un seul appel de rendu par objet, quel que soit le nombre de pièces affichées).
// Le nombre de pièces visibles suit la quantité jusqu'à un plafond : c'est une représentation,
// la quantité exacte reste celle du panneau de propriétés.
// Formes et cotes relevées sur photos et fiches techniques (Raspberry Pi 4, HC-SR04, LCD 1602, NEMA 17…).

import * as THREE from '/vendor/three/three.module.min.js';
import { batterySize, resolveVisual, sizeFromName } from '../visuals.js';

const S = 0.001;
const STEEL = '#c9ccd2';
const CHROME = '#dde0e5';
const ALU = '#b8bcc3';
const DARK = '#2b2d33';
const BLACK = '#1d1e22';
const WHITE = '#f1efe7';
const HOLE = '#0d0e10';
const SILK = '#f4f4f0';
const BRASS = '#c9a24a';
const GOLD = '#d9b65c';
const COPPER = '#c98a4a';
const MAX_LOOSE = 70;
const MAX_NEAT = 36;
// Petites pièces en vrac ; les autres sont rangées côte à côte
const LOOSE = new Set(['screw', 'nut', 'washer', 'resistor', 'capacitor', 'chip', 'box',
  'standoff', 'hex_spacer', 'insert', 'rivet', 'grommet', 'bracket', 'coupler', 'film_cap', 'trimmer', 'led', 'ic_socket', 'relay', 'toggle', 'rocker', 'push_button', 'knob',
  'rj45', 'rj45_boot', 'idc', 'terminal_block', 'crimp', 'banana', 'alligator', 'binding_post', 'dc_jack', 'sd_card', 'sensor', 'grove_module', 'caster', 'potentiometer', 'fuse_holder', 'pin_header', 'dsub']);
const PCB_GREEN = '#1f6b3f';
const PCB_BLUE = '#1b3f8f';
const PCB_BLACK = '#17181c';
const DEFAULT_TINT = {
  capacitor: '#27408b', chip: '#26282e', wire: '#c4263c', screwdriver: '#e0632a', pliers: '#2f9aa8',
  wrench: CHROME, hammer: '#c8a06a', beam: '#2f7fd6', wheel: '#2f7fd6', box: '#8b909a', screw: STEEL, nut: STEEL, washer: STEEL, resistor: '#d9bd8c',
  standoff: '#e9e6dc', hex_spacer: BRASS, insert: BRASS, rivet: '#efe9d8', rubber_foot: '#1d1e22', grommet: '#1d1e22', bracket: '#1d1e22', shaft: STEEL, coupler: STEEL, caster: '#e8e4da',
  resistor_strip: '#f2cf2e', film_cap: '#a8324a', trimmer: '#2a5fc4', potentiometer: '#1d1e22', led: '#e8c33a', ic_socket: '#1d1e22', pin_header: '#1d1e22', heatsink: '#1d1e22', relay: '#2a5fc4',
  toggle: CHROME, rocker: '#1d1e22', push_button: '#1d1e22', rotary_switch: '#1d1e22', knob: '#1d1e22', fuse_holder: '#1d1e22',
  jumper: '#5a3fc4', grove_cable: '#d8c23a', ribbon: '#a9abb0', ethernet: '#8d9095', rj45: '#e3eaee', rj45_boot: '#2a6fd0', idc: '#2b2d33', terminal_block: '#2a6fd0', crimp: '#2a4fb5',
  banana: '#c4263c', alligator: '#c4263c', probe: '#c4263c', binding_post: '#c4263c', dc_jack: '#1d1e22', dsub: STEEL,
  raspberry_pi: PCB_GREEN, pi_case: '#f4f4f2', sense_hat: PCB_GREEN, sd_card: '#17181c', sensor: PCB_BLUE, grove_module: PCB_BLUE, ultrasonic: PCB_BLUE, lcd: PCB_GREEN, breadboard: '#eeebdf', perfboard: '#c98a42', red_board: '#c9262f',
  motor: '#a9acb3', stepper: '#1d1e22', servo: '#1d1e22', robot_wheel: '#f2c230', tyre: '#17181c', pulley: '#2f7fd6', plate: '#2f7fd6',
  battery: '#17181c', battery_9v: '#17181c', battery_holder: '#1d1e22',
  precision_driver: '#8d9198', driver_set: '#1d1e22', cutter: '#c4263c', hex_key: '#26282e', tweezers: STEEL, ruler: '#d6d9dd', knife: '#f2c230', brush: '#c8a06a', punch: '#8f949c', pouch: '#1d1e22', desolder: '#3a78d0',
  case: '#2f6fd6', bag: '#dfe6ea', foam: '#5b5f66',
};

// ---------------------------------------------------------------------------------------------
// Briques de construction (mm). Y vers le haut ; les objets longs sont couchés le long de X.

const V2 = (x, y) => new THREE.Vector2(x, y);
const alongX = (geometry) => geometry.rotateZ(Math.PI / 2);
const cylX = (radius, length, x = 0, y = 0, z = 0, segments = 12) => alongX(new THREE.CylinderGeometry(radius, radius, length, segments)).translate(x, y, z);
const cylZ = (radius, length, x = 0, y = 0, z = 0, segments = 12) => new THREE.CylinderGeometry(radius, radius, length, segments).rotateX(Math.PI / 2).translate(x, y, z);
const box = (lx, ly, lz, x = 0, y = 0, z = 0) => new THREE.BoxGeometry(lx, ly, lz).translate(x, y, z);
/** Cylindre debout, base à y. */
const cyl = (radius, height, x = 0, y = 0, z = 0, segments = 12) => new THREE.CylinderGeometry(radius, radius, height, segments).translate(x, y + height / 2, z);
const ring = (radius, tube, y = 0, segments = 14, x = 0, z = 0) => new THREE.TorusGeometry(radius, tube, 4, segments).rotateX(Math.PI / 2).translate(x, y, z);
const bend = (points, radius = 0.6, segments = 16, sides = 6) => new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points.map(([x, y, z]) => new THREE.Vector3(x, y, z))), segments, radius, sides, false);

/** Pièce de révolution autour de Y : profil [[rayon, hauteur, lisse?], …]. Arêtes vives sauf points marqués lisses. */
function lathe(profile, segments = 16) {
  const points = [];
  profile.forEach(([r, h, smooth], i) => {
    const point = V2(Math.max(r, 1e-4), h);
    points.push(point);
    if (!smooth && i > 0 && i < profile.length - 1) points.push(point.clone());
  });
  return new THREE.LatheGeometry(points, segments);
}
const latheX = (profile, segments = 16, x = 0, y = 0, z = 0) => lathe(profile, segments).rotateZ(-Math.PI / 2).translate(x, y, z);
const latheZ = (profile, segments = 16, x = 0, y = 0, z = 0) => lathe(profile, segments).rotateX(Math.PI / 2).translate(x, y, z);
/** Filetage : arêtes alternées, assez pour accrocher la lumière. */
const thread = (major, minor, from, to, pitch) => {
  const points = [];
  for (let h = from, i = 0; h <= to + 1e-6; h += pitch / 2, i++) points.push([i % 2 ? minor : major, h, 1]);
  return points;
};

// Contours plans (x, z) extrudés en épaisseur : plaques, clés, circuits, avec de vrais trous
const outline = (points, holes = []) => {
  const shape = new THREE.Shape(points.map(([x, y]) => V2(x, y)));
  shape.holes.push(...holes);
  return shape;
};
const hole = (x, z, r) => new THREE.Path().absarc(x, z, r, 0, Math.PI * 2, true);
const polyHole = (points) => new THREE.Path(points.map(([x, y]) => V2(x, y)));
function roundRect(w, d, r, holes = []) {
  r = Math.max(0.01, Math.min(r, w / 2 - 0.01, d / 2 - 0.01));
  const x0 = -w / 2; const z0 = -d / 2;
  const s = new THREE.Shape();
  s.moveTo(x0 + r, z0);
  s.lineTo(x0 + w - r, z0); s.absarc(x0 + w - r, z0 + r, r, -Math.PI / 2, 0);
  s.lineTo(x0 + w, z0 + d - r); s.absarc(x0 + w - r, z0 + d - r, r, 0, Math.PI / 2);
  s.lineTo(x0 + r, z0 + d); s.absarc(x0 + r, z0 + d - r, r, Math.PI / 2, Math.PI);
  s.lineTo(x0, z0 + r); s.absarc(x0 + r, z0 + r, r, Math.PI, Math.PI * 1.5);
  s.holes.push(...holes);
  return s;
}
const disc = (r, holes = []) => {
  const s = new THREE.Shape().absarc(0, 0, r, 0, Math.PI * 2, false);
  s.holes.push(...holes);
  return s;
};
const hexagon = (flats, holeRadius = 0) => {
  const R = flats / Math.sqrt(3);
  return outline(Array.from({ length: 6 }, (_, i) => [R * Math.cos(i * Math.PI / 3), R * Math.sin(i * Math.PI / 3)]), holeRadius ? [hole(0, 0, holeRadius)] : []);
};
/** Carré aux coins abattus (moteurs pas à pas). */
const chamferSquare = (side, cut, holes = []) => {
  const h = side / 2;
  return outline([[-h + cut, -h], [h - cut, -h], [h, -h + cut], [h, h - cut], [h - cut, h], [-h + cut, h], [-h, h - cut], [-h, -h + cut]], holes);
};
/** Section d'axe en D. */
const dShape = (r, flat) => {
  const a0 = Math.acos(flat / r);
  return outline(Array.from({ length: 17 }, (_, i) => {
    const a = a0 + (i / 16) * (Math.PI * 2 - 2 * a0);
    return [r * Math.cos(a), r * Math.sin(a)];
  }));
};
/** Couronne dentée (poulies crantées). */
const gear = (teeth, outer, root, holeRadius) => {
  const points = [];
  for (let i = 0; i < teeth; i++) {
    const a = (i / teeth) * Math.PI * 2; const step = (Math.PI * 2) / teeth;
    for (const [f, r] of [[0.05, root], [0.3, outer], [0.7, outer], [0.95, root]]) points.push([r * Math.cos(a + f * step), r * Math.sin(a + f * step)]);
  }
  return outline(points, [hole(0, 0, holeRadius)]);
};

/** Plaque extrudée vers le haut depuis y, contour en (x, z) ; bevel : arêtes adoucies. */
const slab = (shape, thickness, y = 0, bevel = 0, curveSegments = 10) => new THREE.ExtrudeGeometry(shape, {
  depth: Math.max(thickness - 2 * bevel, 0.01), curveSegments,
  bevelEnabled: bevel > 0, bevelThickness: bevel, bevelSize: bevel, bevelOffset: -bevel, bevelSegments: 2,
}).translate(0, 0, bevel).rotateX(Math.PI / 2).translate(0, y + thickness, 0);
/** Même plaque, extrudée le long de X (de 0 à épaisseur) ou de Z. */
const prismX = (shape, length, bevel = 0) => slab(shape, length, 0, bevel).rotateZ(-Math.PI / 2);
const prismZ = (shape, length, bevel = 0) => slab(shape, length, 0, bevel).rotateX(Math.PI / 2);
/** Profil (x, y) extrudé en épaisseur sur Z, centré. */
const extrudeZ = (shape, depth) => new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: false, curveSegments: 10 }).translate(0, 0, -depth / 2);
/** Profil (z inversé, y) extrudé le long de X, centré. */
const extrudeX = (shape, length) => extrudeZ(shape, length).rotateY(Math.PI / 2);
/** Boîte aux coins verticaux arrondis et arêtes adoucies, centrée comme box(). */
const rbox = (lx, ly, lz, r = 1, x = 0, y = 0, z = 0, bevel = Math.min(0.5, ly / 4, r * 0.8)) => slab(roundRect(lx, lz, r), ly, 0, bevel).translate(x, y - ly / 2, z);
/** Déforme une géométrie sommet par sommet (biseaux, effilements). */
function warp(geometry, fn) {
  const p = geometry.attributes.position; const v = new THREE.Vector3();
  for (let i = 0; i < p.count; i++) { v.fromBufferAttribute(p, i); fn(v); p.setXYZ(i, v.x, v.y, v.z); }
  geometry.computeVertexNormals();
  return geometry;
}

/**
 * Rectangles plats en une seule géométrie : trous, sérigraphie, contacts, graduations.
 * rects [u, v, largeur, hauteur] ; face y : (x, z) ; face z : (x, y) ; face x : (z, y). offset : position sur l'axe de la face.
 */
function flats(rects, face = 'y', offset = 0) {
  const [map, level, turn] = {
    y: [(u, v) => [u, v], offset, (g) => g],
    '-y': [(u, v) => [u, -v], -offset, (g) => g.rotateX(Math.PI)],
    z: [(u, v) => [u, -v], offset, (g) => g.rotateX(Math.PI / 2)],
    '-z': [(u, v) => [u, v], -offset, (g) => g.rotateX(-Math.PI / 2)],
    x: [(u, v) => [-v, u], offset, (g) => g.rotateZ(-Math.PI / 2)],
    '-x': [(u, v) => [v, u], -offset, (g) => g.rotateZ(Math.PI / 2)],
  }[face];
  const positions = []; const normals = [];
  for (const [u, v, w, h] of rects) {
    const [ax, az] = map(u - w / 2, v - h / 2); const [bx, bz] = map(u + w / 2, v + h / 2);
    const x0 = Math.min(ax, bx); const x1 = Math.max(ax, bx); const z0 = Math.min(az, bz); const z1 = Math.max(az, bz);
    positions.push(x0, level, z0, x0, level, z1, x1, level, z1, x0, level, z0, x1, level, z1, x1, level, z0);
    for (let k = 0; k < 6; k++) normals.push(0, 1, 0);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
  g.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
  return turn(g);
}
const grid = (nx, nz, pitchX, pitchZ, size, x0 = 0, z0 = 0) => Array.from({ length: nx * nz }, (_, i) => [x0 + ((i % nx) - (nx - 1) / 2) * pitchX, z0 + (Math.floor(i / nx) - (nz - 1) / 2) * pitchZ, size, size]);
/** Répète une pièce autour de l'axe Y. */
const around = (count, make) => Array.from({ length: count }, (_, i) => make((i / count) * Math.PI * 2, i));

/** Ressort hélicoïdal le long de Y. */
const coil = (radius, turns, tube, pitch) => {
  const points = [];
  for (let i = 0; i <= turns * 12; i++) {
    const a = (i / 12) * Math.PI * 2;
    points.push(new THREE.Vector3(Math.cos(a) * radius, (i / 12) * pitch, Math.sin(a) * radius));
  }
  return new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points), turns * 16, tube, 5, false);
};

/** Circuit imprimé : plaque aux coins arrondis, trous de fixation métallisés. */
const board = (w, d, holes, color = null, r = 1.5, holeRadius = 1.4, thickness = 1.6) => [
  [slab(roundRect(w, d, r, holes.map(([x, z]) => hole(x, z, holeRadius))), thickness), color],
  ...holes.map(([x, z]) => [new THREE.RingGeometry(holeRadius, holeRadius + 1.4, 16).rotateX(-Math.PI / 2).translate(x, thickness + 0.02, z), GOLD]),
];

/** Corps de résistance « os de chien » couché le long de X, bagues 1 kΩ 5 %. */
const resistorBody = (bodyColor = null) => [
  [latheX([[0.3, -3.4], [0.9, -3.25, 1], [1.2, -2.95, 1], [1.25, -2.4, 1], [1.1, -1.8, 1], [1.05, -1.4, 1], [1.05, 1.4, 1], [1.1, 1.8, 1], [1.25, 2.4, 1], [1.2, 2.95, 1], [0.9, 3.25, 1], [0.3, 3.4]], 12), bodyColor],
  [latheX([[1.29, -2.75], [1.29, -2.2]], 12), '#7a4a1e'],
  [latheX([[1.1, -1.35], [1.1, -0.85]], 12), '#222222'],
  [latheX([[1.1, -0.55], [1.1, -0.05]], 12), '#c0392b'],
  [latheX([[1.29, 2.2], [1.29, 2.75]], 12), BRASS],
];

/** Fiche RJ45 et son manchon au bout d'un câble, vers +X. */
const rj45Plug = (x, y, z, bootColor = null) => [
  [lathe([[2.6, 0], [3.3, 1.5, 1], [4.2, 6, 1], [5.4, 12], [5.4, 15]], 10).rotateZ(-Math.PI / 2).scale(1, 0.85, 1.1).translate(x, y, z), bootColor],
  [rbox(21, 8, 11.7, 0.8, x + 25, y - 0.6, z), '#e3eaee'],
  [box(13, 1.2, 6, x + 23, y + 4.2, z), '#d3dade'],
  [flats(Array.from({ length: 8 }, (_, i) => [x + 33.5, z - 4.45 + i * 1.27, 3, 0.55]), 'y', y + 3.42), GOLD],
];

/** Fil souple terminé par deux boîtiers de connecteur. */
const dupont = (color) => [
  [bend([[-48, 1.25, 0], [-30, 5, 7], [-5, 7, 11], [22, 5, 5], [45, 1.25, 0]], 0.65, 30, 6), color],
  [box(14, 2.54, 2.54, -55, 1.25, 0), BLACK], [box(14, 2.54, 2.54, 52, 1.25, 0), BLACK],
  [flats([[-55, 0, 6, 1.4]], 'y', 2.53), '#3a3c42'], [flats([[52, 0, 6, 1.4]], 'y', 2.53), '#3a3c42'],
  [box(6, 0.64, 0.64, -65, 1.25, 0), GOLD],
  [flats([[0, 1.25, 1, 1]], 'x', 59.01), HOLE],
];

// ---------------------------------------------------------------------------------------------
/** Parties d'un modèle : [géométrie, couleur]. La couleur null prend la teinte de l'objet. */
const MODELS = {
  // Vis à tête cylindrique bombée, empreinte cruciforme, M3 x 12 (tête 1,8 mm : voir scaleFor)
  screw: () => [
    [latheX([[0, 0], [2.1, 0], [2.6, 0.35, 1], [2.8, 0.9, 1], [2.8, 1.8], [1.5, 1.8], ...thread(1.5, 1.22, 2.1, 12.9, 0.5), [1.1, 13.6], [0, 13.8]], 14), null],
    [box(0.5, 0.5, 3, 0.1, 0, 0), '#4a4e55'], [box(0.5, 3, 0.5, 0.1, 0, 0), '#4a4e55'],
  ],
  // Écrou hexagonal M3 : 5,5 sur plats, 2,4 d'épaisseur, chanfreiné
  nut: () => [[slab(hexagon(5.5, 1.5), 2.4, 0, 0.3), null], [lathe([[1.52, 0.3], ...thread(1.52, 1.3, 0.6, 1.8, 0.4), [1.52, 2.1]], 12), '#9a9ea6']],
  // Rondelle plate M3 : 7 x 3,2 x 0,5
  washer: () => [[lathe([[1.7, 0], [3.5, 0], [3.5, 0.5], [1.7, 0.5], [1.7, 0]], 24), null]],
  // Résistance 1/4 W : corps 6,5 mm, pattes 26 mm, bagues brun-noir-rouge-or
  resistor: () => [...resistorBody(), [cylX(0.3, 25, -15.9, 0, 0, 6), STEEL], [cylX(0.3, 25, 15.9, 0, 0, 6), STEEL]],
  // Condensateur chimique radial 8 x 11,5 : gaine, gorge de sertissage, évent en croix, bande de polarité
  capacitor: () => [
    [cyl(3.6, 0.5, 0, 0, 0, 16), '#3a3b40'],
    [lathe([[0, 0.5], [3.6, 0.5], [3.95, 0.8, 1], [4, 1.3], [4, 1.9], [3.7, 2.2, 1], [4, 2.5], [4, 11.2], [3.85, 11.5], [3.6, 11.5]], 20), null],
    [new THREE.CylinderGeometry(4.03, 4.03, 8.6, 6, 1, true, -0.45, 0.9).translate(0, 6.9, 0), '#c8ccd6'],
    [cyl(3.65, 0.08, 0, 11.38, 0, 20), STEEL],
    [box(4.6, 0.06, 0.35, 0, 11.48, 0), '#7c8086'], [box(0.35, 0.06, 4.6, 0, 11.48, 0), '#7c8086'],
    [cyl(0.3, 16, 1.75, -15.5, 0, 6), STEEL], [cyl(0.3, 16, -1.75, -15.5, 0, 6), STEEL],
  ],
  // Circuit intégré DIP-16 : boîtier 19,3 x 6,5, encoche et repère de la broche 1, 16 pattes
  chip: () => {
    const parts = [
      [rbox(19.3, 3.3, 6.5, 0.3, 0, 2.6, 0, 0.25), null],
      [new THREE.CylinderGeometry(0.9, 0.9, 0.08, 12, 1, false, 0, Math.PI).translate(-9.62, 4.23, 0), '#101114'],
      [cyl(0.5, 0.04, -7.6, 4.24, -1.9, 12), '#3e4148'],
      [flats([[1, -0.8, 9, 0.8], [0.4, 0.8, 6, 0.8]], 'y', 4.26), '#8a8d94'],
    ];
    for (let i = 0; i < 8; i++) {
      const x = -8.89 + i * 2.54;
      for (const s of [-1, 1]) {
        parts.push([box(0.5, 0.25, 1, x, 2, s * 3.75), STEEL], [box(1.5, 1.4, 0.25, x, 1.4, s * 4.15), STEEL], [box(0.5, 2.9, 0.25, x, -0.75, s * 4.15), STEEL]);
      }
    }
    return parts;
  },
  // Bobine de fil : flasques, enroulement, bout libre
  wire: () => [
    [lathe([[8, 0], [27, 0], [27, 2], [11, 2], [11, 26], [27, 26], [27, 28], [8, 28], [8, 0]], 32), '#e8e5dc'],
    [lathe([[11, 2], [22, 2.4, 1], [22.6, 5, 1], [22.6, 23, 1], [22, 25.6, 1], [11, 26]], 32), null],
    ...Array.from({ length: 10 }, (_, i) => [ring(22.65, 0.5, 4 + i * 2.1, 32), null]),
    [bend([[18, 26, 12], [22, 29, 16], [30, 30, 24], [44, 28, 30]], 0.7, 12, 6), null],
  ],
  // Tournevis : manche bi-matière à rainures, virole, lame plate
  screwdriver: () => {
    const y = 12;
    return [
      [latheX([[0, -100], [6, -99.6, 1], [9.5, -98, 1], [11.5, -94, 1], [12, -86, 1], [11.6, -60, 1], [10.6, -40, 1], [11.3, -28, 1], [11.6, -20, 1], [10.4, -12, 1], [7.5, -5, 1], [5.5, -1], [5.5, 0]], 18, 0, y), null],
      ...around(6, (a) => [box(44, 2.2, 4, -64, 0, 0).translate(0, 10.7, 0).rotateX(a).translate(0, y, 0), BLACK]),
      [latheX([[0, -100.2], [5.5, -100.2], [6, -99.6]], 18, 0, y), BLACK],
      [latheX([[5.5, 0], [4.6, 3], [3.4, 7], [3, 8]], 12, 0, y), STEEL],
      [cylX(3, 92, 54, y, 0, 12), STEEL],
      [warp(box(12, 6.4, 3, 106, y, 0), (v) => { if (v.x > 104) v.z *= 0.3; }), STEEL],
    ];
  },
  // Pince universelle : mâchoires, rivet d'articulation, poignées gainées
  pliers: () => [
    [slab(outline([[36, -9], [50, -9.5], [70, -7], [92, -3.5], [96, -1], [96, 1], [92, 3.5], [70, 7], [50, 9.5], [36, 9]]), 9, 0, 1), STEEL],
    [flats([[72, 0, 46, 0.5]], 'y', 9.01), '#5b5f66'],
    [flats([[84, -2, 6, 1.4], [84, 2, 6, 1.4]], 'y', 9.01), '#7c8086'],
    [cyl(5.2, 10, 44, -0.5, 0, 18), '#e2e5e9'],
    ...[-1, 1].flatMap((s) => [
      [bend([[38, 4.5, s * 5], [26, 4.5, s * 8], [18, 4.5, s * 10]], 4.2, 6, 8), STEEL],
      [bend([[20, 4.5, s * 9.5], [-20, 4.5, s * 14], [-70, 4.5, s * 19], [-112, 4.5, s * 20]], 5.4, 18, 10), null],
      [new THREE.SphereGeometry(6, 12, 8).translate(-112, 4.5, s * 20), null],
    ]),
  ],
  // Clé mixte : œil polygonal d'un côté, fourche ouverte à 15° de l'autre
  wrench: () => {
    const R = 13.5; const cx = 68; const w = 6.6; const a0 = Math.asin(w / R); const tilt = 0.26;
    const jaw = [];
    for (let i = 0; i <= 20; i++) { const a = a0 + (i / 20) * (Math.PI * 2 - 2 * a0); jaw.push([R * Math.cos(a), R * Math.sin(a)]); }
    jaw.push([-1, -w]);
    for (let i = 1; i < 10; i++) { const a = -Math.PI / 2 - (i / 10) * Math.PI; jaw.push([-1 + w * Math.cos(a), w * Math.sin(a)]); }
    jaw.push([-1, w]);
    const turned = jaw.map(([x, z]) => [cx + x * Math.cos(tilt) - z * Math.sin(tilt), x * Math.sin(tilt) + z * Math.cos(tilt)]);
    const star = Array.from({ length: 24 }, (_, i) => { const a = (i / 24) * Math.PI * 2; const r = i % 2 ? 6.4 : 7.2; return [-68 + r * Math.cos(a), r * Math.sin(a)]; });
    return [
      [slab(outline([[-60, -5.5], [58, -6.5], [58, 6.5], [-60, 5.5]]), 4.4, 0.3, 0.8), null],
      [slab(outline(Array.from({ length: 32 }, (_, i) => { const a = (i / 32) * Math.PI * 2; return [-68 + 11.5 * Math.cos(a), 11.5 * Math.sin(a)]; }), [polyHole(star)]), 5, 0, 0.7), null],
      [slab(outline(turned), 5, 0, 0.7), null],
      [flats([[0, 0, 36, 4]], 'y', 4.72), '#c3c7cd'],
    ];
  },
  // Marteau de mécanicien : manche ovale en bois, tête à panne ronde
  hammer: () => [
    [latheX([[0, -150], [11, -149, 1], [13.5, -140, 1], [13, -110, 1], [11, -60, 1], [10, 0, 1], [10.5, 90, 1], [11, 118], [11, 135], [0, 135]], 16).scale(1, 1, 0.75), null],
    [rbox(26, 26, 50, 3, 125, 0, 0, 1), '#4d525a'],
    [latheZ([[0, -48], [12.4, -48], [14, -46.5, 1], [14.2, -44], [14.2, -33], [13, -25]], 20, 125, 0, 0), '#4d525a'],
    [latheZ([[11.5, 25], [9, 31, 1], [9.2, 35, 1], [11.8, 40, 1], [12.5, 45, 1], [11.5, 50, 1], [8.5, 54, 1], [4.5, 56, 1], [0, 56.6]], 20, 125, 0, 0), '#4d525a'],
    [flats([[125, 0, 14, 7]], 'y', 13.02), '#b88f5a'], [flats([[125, 0, 13, 1.2]], 'y', 13.04), '#9ea3ab'],
  ],
  // Profilé aluminium 8 x 24 : trous taraudés M4 au pas de 16, rainures latérales
  beam: () => [
    [rbox(120, 8, 24, 0.8, 0, 4, 0, 0.4), null],
    ...Array.from({ length: 8 }, (_, i) => [cyl(2.05, 0.06, -56 + i * 16, 8, 0, 14), '#0f2744']),
    [flats([[0, 4, 120, 1.6]], 'z', 12.01), '#1d4f8a'], [flats([[0, 4, 120, 1.6]], '-z', -12.01), '#1d4f8a'],
    [flats([[0, 4, 1.6, 1.6]], 'x', 60.01), '#0f2744'],
  ],
  // Roue : pneu caoutchouc, jante à six jours, moyeu à vis de pression
  wheel: () => [
    [lathe([[24, 0], [28, 0], [29.6, 1.2, 1], [30, 5, 1], [29.6, 8.8, 1], [28, 10], [24, 10], [24, 0]], 36), BLACK],
    [lathe([[2.6, 1], [24.2, 1], [24.2, 9], [2.6, 9], [2.6, 1]], 36), null],
    ...around(6, (a) => [cyl(4.5, 0.06, Math.cos(a) * 15, 9, Math.sin(a) * 15, 14), '#123a66']),
    [lathe([[2.6, 9], [8, 9], [8, 14], [2.6, 14], [2.6, 9]], 20), null],
    [cylX(1.2, 0.3, 8, 11.5, 0, 8), HOLE],
  ],
  // Petit boîtier plastique : couvercle vissé, étiquette
  box: () => [
    [rbox(30, 18, 30, 3, 0, 9, 0, 0.8), null],
    [rbox(30.4, 0.4, 30.4, 3.2, 0, 14, 0, 0.1), '#5b5f66'],
    ...[[-11, -11], [11, -11], [-11, 11], [11, 11]].map(([x, z]) => [cyl(1.3, 0.2, x, 18, z, 10), '#3a3d44']),
    [flats([[0, 7, 18, 7]], 'z', 15.02), '#ffffff'],
  ],

  // === Visserie et fixations ===
  // Entretoise nylon ronde 15 mm
  standoff: () => [[latheX([[1.6, 0], [2.3, 0], [2.5, 0.3], [2.5, 14.7], [2.3, 15], [1.6, 15], [1.6, 0]], 16), null]],
  // Entretoise laiton hexagonale M3 x 20 mâle-femelle
  hex_spacer: () => [
    [prismX(hexagon(5, 1.4), 20, 0.2), null],
    [latheX([[1.2, 19.9], ...thread(1.5, 1.22, 20.2, 25.6, 0.5), [1.1, 26], [0, 26.1]], 12), null],
  ],
  // Insert laiton à chaud M3 : moletage en deux bandes
  insert: () => [
    [latheX([[1.4, 0], [2.0, 0], [2.3, 0.3], [2.3, 2.1], [2.0, 2.4], [2.0, 3.0], [2.3, 3.3], [2.3, 5.4], [2.0, 5.7], [1.4, 5.7], [1.4, 0]], 18), null],
    ...around(12, (a) => [box(1.6, 0.3, 0.5, 1.2, 0, 0).translate(0, 2.3, 0).rotateX(a), null]),
    ...around(12, (a) => [box(2, 0.3, 0.5, 4.35, 0, 0).translate(0, 2.3, 0).rotateX(a + 0.26), null]),
  ],
  // Rivet plastique à expansion : tête champignon, jambes fendues
  rivet: () => [
    [lathe([[0, 0], [1.2, 0], [1.9, 1.5, 1], [1.9, 3], [1.6, 3.4], [1.6, 8.5], [3.5, 8.5], [3.6, 9, 1], [3.1, 10, 1], [1.6, 10.5, 1], [0, 10.6]], 16), null],
    [box(0.5, 5, 4.2, 0, 2.3, 0), '#77736a'],
    [cyl(0.9, 0.05, 0, 10.58, 0, 12), '#cfc8b5'],
  ],
  // Planche de pieds adhésifs : feuille de support, patins bombés
  rubber_foot: () => [
    [rbox(42, 0.3, 42, 2, 0, 0.15, 0, 0.05), '#ece9e0'],
    ...grid(3, 3, 13, 13, 0).map(([x, z]) => [lathe([[0, 0.3], [5, 0.3], [5.1, 0.9, 1], [4.7, 2, 1], [3.6, 2.9, 1], [2, 3.4, 1], [0, 3.5]], 18).translate(x, 0, z), null]),
  ],
  // Passe-fil caoutchouc : deux collerettes et une gorge
  grommet: () => [[lathe([[3, 0], [6.4, 0], [6.8, 0.5, 1], [6.5, 1.5], [4.6, 1.5], [4.6, 3.5], [6.5, 3.5], [6.8, 4, 1], [6.4, 5], [3, 5], [3, 0]], 20), null]],
  // Équerre : deux ailes percées, renfort central
  bracket: () => [
    [slab(roundRect(24, 18, 1.5, [hole(2, -4.5, 1.7), hole(7, 4.5, 1.7)]), 2.5, 0, 0.3), null],
    [slab(roundRect(18, 18, 1.5, [hole(3, -4.5, 1.7), hole(3, 4.5, 1.7)]), 2.5, 0, 0.3).rotateZ(Math.PI / 2).translate(-9.5, 9, 0), null],
    [extrudeZ(outline([[-9.5, 2.4], [-1, 2.4], [-9.5, 11]]), 2), null],
  ],
  // Axe acier 4 mm, bouts chanfreinés, gorge de circlip
  shaft: () => [
    [latheX([[0, 0], [1.6, 0], [2, 0.4], [2, 99.6], [1.6, 100], [0, 100]], 16), null],
    [latheX([[2.02, 5], [2.02, 5.8]], 16), '#7c8086'], [latheX([[2.02, 94.2], [2.02, 95]], 16), '#7c8086'],
  ],
  // Moyeu à bride : bride percée, fût et vis de pression
  coupler: () => [
    [lathe([[2.1, 0], [12, 0], [12.4, 0.4], [12.4, 2.6], [12, 3], [6, 3], [6, 10.6], [5.6, 11], [2.1, 11], [2.1, 0]], 28), null],
    ...around(6, (a) => [cyl(1.6, 0.06, Math.cos(a) * 9, 3, Math.sin(a) * 9, 12), HOLE]),
    [cylX(1.3, 0.3, 5.95, 7, 0, 10), HOLE], [cylZ(1.3, 0.3, 0, 7, 5.95, 10), HOLE],
  ],
  // Roulette pivotante : platine 4 trous, chape, roue
  caster: () => [
    [rbox(32, 2, 32, 3, 0, 33, 0, 0.4), STEEL],
    ...grid(2, 2, 22, 22, 0).map(([x, z]) => [cyl(1.8, 0.06, x, 34, z, 12), HOLE]),
    [lathe([[0, 28], [11, 28], [11.5, 29], [11.5, 31], [10, 32], [0, 32]], 20), '#a9adb4'],
    [box(22, 2, 17, 2, 27, 0), STEEL],
    [box(20, 16, 1.8, 3, 19, 7.2), STEEL], [box(20, 16, 1.8, 3, 19, -7.2), STEEL],
    [latheZ([[0, -5], [9.5, -5], [11.8, -4.4, 1], [12.5, -2.5, 1], [12.6, 0, 1], [12.5, 2.5, 1], [11.8, 4.4, 1], [9.5, 5], [0, 5]], 24, 5, 12.5, 0), null],
    [latheZ([[0, -5.1], [6, -5.1], [6, 5.1], [0, 5.1]], 16, 5, 12.5, 0), '#8b909a'],
    [latheZ([[0, -9.4], [3, -9.4], [3, 9.4], [0, 9.4]], 12, 5, 12.5, 0), CHROME],
  ],

  // === Composants ===
  // Résistances en bande : corps alignés entre deux rubans de papier
  resistor_strip: () => {
    const parts = [-27, 27].flatMap((z) => [[box(130, 0.35, 6, 0, 0.17, z), null], [box(130, 0.3, 6, 0, 1.05, z), '#f4f1e6']]);
    for (let i = 0; i < 14; i++) {
      const x = -52 + i * 8;
      parts.push([box(0.6, 0.6, 60, x, 0.6, 0), STEEL]);
      for (const [g, c] of resistorBody('#d9bd8c')) parts.push([g.rotateY(Math.PI / 2).translate(x, 0.6, 0), c]);
    }
    return parts;
  },
  // Condensateur film polyester (boîtier rectangulaire)
  film_cap: () => [
    [rbox(10, 8.5, 5, 0.8, 0, 4.25, 0, 0.5), null],
    [flats([[0, 6, 6, 0.8], [0, 4.6, 4, 0.8], [-0.5, 3.2, 5, 0.6]], 'z', 2.52), SILK],
    [cyl(0.3, 4, 3.75, -4, 0, 6), STEEL], [cyl(0.3, 4, -3.75, -4, 0, 6), STEEL],
  ],
  // Potentiomètre ajustable multitours (type 3296) : vis laiton, marquage
  trimmer: () => [
    [rbox(9.5, 10, 4.8, 0.5, 0, 5, 0, 0.3), null],
    [cyl(1.15, 1, -3.2, 10, 0, 14), BRASS], [box(2, 0.2, 0.35, -3.2, 11, 0), '#6b5420'],
    [flats([[1.5, 6, 4, 1], [1.5, 4.2, 3, 0.8]], 'z', 2.42), SILK],
    ...[-2.54, 0, 2.54].map((x) => [box(0.5, 4, 0.5, x, -2, 0), STEEL]),
  ],
  // Potentiomètre 16 mm : fond bakélite, capot, canon fileté, écrou, axe moleté, cosses
  potentiometer: () => [
    [cyl(8, 1.2, 0, 0, 0, 24), '#6b4a2e'],
    [lathe([[0, 1.2], [8.05, 1.2], [8.05, 6.4], [7.6, 6.8], [0, 6.8]], 24), STEEL],
    [lathe([[0, 6.8], ...thread(3.5, 3.2, 6.8, 13.6, 0.7), [0, 13.8]], 16), STEEL],
    [slab(hexagon(10, 3.6), 2, 9.2, 0.3), CHROME],
    [lathe([[0, 13.6], [3, 13.6], [3, 29.4], [2.7, 30], [0, 30]], 16), null],
    ...around(16, (a) => [box(0.5, 9, 0.6, 3, 25, 0).rotateY(a), null]),
    [box(6.2, 0.3, 0.8, 0, 30, 0), HOLE],
    ...[-5, 0, 5].map((x) => [box(2, 0.4, 7, x, 0.6, 11), BRASS]),
    [flats([[-5, 13, 0.8, 0.8], [0, 13, 0.8, 0.8], [5, 13, 0.8, 0.8]], 'y', 0.81), HOLE],
  ],
  // LED 5 mm : collerette, dôme, pattes (anode plus longue)
  led: () => [
    [lathe([[0, 0], [2.9, 0], [2.9, 1], [2.5, 1], [2.5, 6.1, 1], [2.4, 6.9, 1], [2.1, 7.6, 1], [1.6, 8.2, 1], [0.9, 8.5, 1], [0, 8.6]], 18), null],
    [box(0.5, 27, 0.5, 1.27, -13.5, 0), STEEL], [box(0.5, 25, 0.5, -1.27, -12.5, 0), STEEL],
  ],
  // Support DIP-16 : cadre ajouré, contacts tulipe
  ic_socket: () => [
    [slab(outline([[-10.2, -5], [10.2, -5], [10.2, 5], [-10.2, 5]], [polyHole([[-8, -2], [8, -2], [8, 2], [-8, 2]])]), 3.2, 0, 0.2), null],
    [flats(grid(8, 2, 2.54, 7.62, 1.3), 'y', 3.21), HOLE],
    [flats(grid(8, 2, 2.54, 7.62, 0.6), 'y', 3.22), GOLD],
    [new THREE.CylinderGeometry(1, 1, 0.1, 12, 1, false, 0, Math.PI).translate(-10.15, 3.2, 0), HOLE],
    ...grid(8, 2, 2.54, 7.62, 0).map(([x, z]) => [box(0.5, 3, 0.25, x, -1.5, z), GOLD]),
  ],
  // Barrette mâle 1 x 20 au pas de 2,54
  pin_header: () => Array.from({ length: 20 }, (_, i) => -24.13 + i * 2.54).flatMap((x) => [
    [box(2.4, 2.5, 2.4, x, 1.25, 0), null],
    [warp(box(0.64, 11.5, 0.64, x, 2.75, 0), (v) => { if (v.y > 8) { v.x = x + (v.x - x) * 0.4; v.z *= 0.4; } }), GOLD],
  ]),
  // Dissipateur à ailettes, anodisé noir
  heatsink: () => [
    [rbox(40, 3, 30, 0.5, 0, 1.5, 0, 0.3), null],
    ...Array.from({ length: 8 }, (_, i) => [box(1.6, 20, 30, -17.5 + i * 5, 13, 0), null]),
    ...Array.from({ length: 8 }, (_, i) => [cylZ(0.8, 30, -17.5 + i * 5, 23, 0, 8), null]),
    [flats([[-15, 0, 3, 3], [15, 0, 3, 3]], 'y', 3.01), HOLE],
  ],
  // Relais 5 V (type SRD) : boîtier, marquage, 5 pattes
  relay: () => [
    [rbox(19, 15.5, 15.5, 0.6, 0, 7.75 + 0.4, 0, 0.4), null],
    [flats([[-3, -3, 9, 2], [-2, 1, 11, 1.1], [-2, 3.5, 11, 1.1], [-3, 5.8, 9, 1.1]], 'y', 15.92), SILK],
    [flats([[6, -4, 3, 3]], 'y', 15.93), '#c4263c'],
    ...[[-7.5, -6], [-7.5, 6], [6.5, -6], [6.5, 0], [6.5, 6]].map(([x, z]) => [box(0.6, 3.5, 1.2, x, -1.3, z), STEEL]),
  ],
  // Inverseur miniature à levier : boîtier, canon fileté, écrou, levier chromé
  toggle: () => [
    [box(13, 9, 8, 0, 4.5, 0), '#26282e'],
    [box(13.2, 1, 8.2, 0, 9.5, 0), STEEL],
    [lathe([[0, 10], ...thread(3, 2.75, 10, 18, 0.7), [2.6, 18.6], [0, 18.6]], 16), STEEL],
    [slab(hexagon(8, 3.1), 2.2, 14.6, 0.3), CHROME],
    [lathe([[0, 0], [1.5, 0], [1.25, 9, 1], [1.6, 10, 1], [1.3, 10.8, 1], [0, 11]], 12).rotateZ(0.32).translate(0, 18.4, 0), null],
    ...[-4.7, 0, 4.7].map((x) => [box(2, 4, 0.5, x, -2, 0), BRASS]),
  ],
  // Interrupteur à bascule (type KCD1) : collerette, bascule inclinée, repères I/O
  rocker: () => [
    [box(19, 12, 13, 0, 6, 0), null],
    [box(1, 6, 7, 9.9, 7, 0), null], [box(1, 6, 7, -9.9, 7, 0), null],
    [rbox(21.5, 2, 15.5, 1.2, 0, 13, 0, 0.4), null],
    [rbox(17, 3, 11, 1, 0, 0, 0, 0.6).rotateZ(0.12).translate(0, 14.8, 0), '#2a2b30'],
    [box(0.6, 0.12, 3.6, -5, 15.85, 0).rotateZ(0.12), SILK],
    [ring(1.5, 0.3, 0, 12).rotateZ(0.12).translate(5, 17.05, 0), SILK],
    [box(0.6, 5, 2.5, -4, -2.5, 0), BRASS], [box(0.6, 5, 2.5, 4, -2.5, 0), BRASS],
  ],
  // Bouton-poussoir tactile 12 x 12 : socle, capot métallique, capuchon
  push_button: () => [
    [box(12, 3.6, 12, 0, 1.8, 0), '#26282e'],
    [slab(roundRect(12.2, 12.2, 0.5, [hole(0, 0, 4.2)]), 0.4, 3.6), STEEL],
    [cyl(4, 0.6, 0, 3.6, 0, 16), '#3a3b42'],
    [lathe([[0, 4.2], [5.8, 4.2], [6, 4.8, 1], [6, 6.6, 1], [5.6, 7.3, 1], [4.6, 7.6, 1], [0, 7.7]], 20), null],
    ...[[-1, -1], [1, -1], [-1, 1], [1, 1]].map(([sx, sz]) => [box(0.7, 4, 0.3, sx * 5, -1, sz * 6.2), STEEL]),
  ],
  // Commutateur rotatif : galette de contacts, capot, canon, axe à méplat
  rotary_switch: () => [
    [cyl(13, 3, 0, 0, 0, 24), '#e2d6b5'],
    ...around(12, (a) => [box(5, 0.4, 1.6, 14, 1.5, 0).rotateY(a), BRASS]),
    [lathe([[0, 3], [12.5, 3], [12.5, 13], [12, 14], [0, 14]], 24), STEEL],
    [lathe([[0, 14], ...thread(4.5, 4.2, 14, 21, 0.75), [0, 21]], 16), STEEL],
    [slab(hexagon(12, 4.6), 2.4, 16, 0.3), CHROME],
    [slab(dShape(3, 2), 22, 21), null],
  ],
  // Bouton de réglage moleté avec capuchon et index
  knob: () => [
    [lathe([[0, 0], [11, 0], [11.2, 0.8, 1], [11, 2.2], [9.6, 2.4], [9.4, 4, 1], [8.8, 13.4, 1], [8.4, 14.2, 1], [7.4, 14.6], [0, 14.6]], 28), null],
    ...around(24, (a) => [box(0.9, 9.6, 1, 9.05, 9, 0).rotateZ(0.063).rotateY(a), null]),
    [cyl(7.3, 0.4, 0, 14.4, 0, 24), '#aeb2b8'],
    [box(0.9, 0.1, 5.5, 0, 14.85, -3.6), SILK],
    [flats(around(11, (a) => [Math.cos(a * 0.75 + 2.4) * 10.2, Math.sin(a * 0.75 + 2.4) * 10.2, 0.6, 0.6]), 'y', 2.41), SILK],
  ],
  // Porte-fusible de panneau 5 x 20 : capuchon à fente, collerette, écrou, cosses
  fuse_holder: () => [
    [latheX([[0, 0], [5.6, 0], [6.2, 0.6, 1], [6.3, 12], [8.5, 12.4], [8.5, 14.4], [6.4, 14.6]], 20), null],
    ...around(14, (a) => [box(10, 0.6, 0.6, 6, 6.2, 0).rotateX(a), null]),
    [box(0.3, 1, 7, -0.1, 0, 0), HOLE],
    [latheX([[6.4, 14.6], ...thread(6.4, 6.1, 14.6, 26, 0.8), [5.5, 26], [5.5, 36], [4, 36.5], [0, 36.5]], 18), null],
    [prismX(hexagon(14, 6.2), 3, 0.3).translate(18, 0, 0), STEEL],
    [box(8, 0.4, 3, 40, 0, 0), BRASS], [box(8, 0.4, 3, 32, 5.6, 0).rotateZ(0.4), BRASS],
  ],

  // === Câbles et connectique ===
  jumper: () => dupont(null),
  // Câble Grove : 4 conducteurs (noir, rouge, blanc, jaune), boîtiers HY2.0 blancs
  grove_cable: () => [
    ...[[-1.5, BLACK], [-0.5, '#c4263c'], [0.5, '#f1efe7'], [1.5, null]].map(([z, color]) => [bend([[-50, 2.3, z * 1.05], [-20, 6, z + 7], [20, 4.5, z - 7], [50, 2.3, z * 1.05]], 0.5, 24, 6), color]),
    ...[-1, 1].flatMap((s) => [
      [rbox(6, 4.6, 9.6, 0.4, s * 54, 2.3, 0, 0.3), WHITE],
      [box(3, 0.6, 1.2, s * 54, 4.9, -2.6), WHITE], [box(3, 0.6, 1.2, s * 54, 4.9, 2.6), WHITE],
    ]),
  ],
  // Nappe 40 conducteurs, fil rouge du repère, connecteurs IDC aux deux bouts
  ribbon: () => [
    [box(110, 0.9, 50.8, 0, 0.45, 0), null],
    [flats(Array.from({ length: 39 }, (_, i) => [0, -24.13 + i * 1.27, 110, 0.2]), 'y', 0.91), '#8c8f95'],
    [flats([[0, -24.76, 110, 1.1]], 'y', 0.92), '#b0232c'],
    ...[-1, 1].flatMap((s) => [
      [rbox(9, 7, 56, 0.6, s * 59.5, 2.2, 0, 0.3), '#26282e'],
      [box(9.2, 1.4, 52, s * 59.5, 6.3, 0), '#3a3b40'],
      [box(1.6, 4, 4.5, s * 64.8, 2.2, 0), '#26282e'],
    ]),
  ],
  // Câble réseau enroulé à plat, attache velcro, fiches RJ45 avec manchons
  ethernet: () => {
    const path = [[100, 2.6, 34], [80, 2.6, 34], [62, 2.6, 26]];
    for (let i = 0; i <= 72; i++) { const a = (i / 24) * Math.PI * 2 + 0.45; const r = 56 - (i / 24) * 5.6; path.push([Math.cos(a) * r, 2.6, Math.sin(a) * r]); }
    path.push([50, 8.6, 6], [72, 8.6, -14], [88, 3, -22], [100, 2.6, -22]);
    return [
      [bend(path, 2.6, 320, 8), null],
      [box(10, 7, 21, 0, 3, -46), '#26282e'],
      ...rj45Plug(100, 2.6, 34), ...rj45Plug(100, 2.6, -22),
    ];
  },
  // Fiche RJ45 transparente : conducteurs colorés visibles, languette, contacts dorés
  rj45: () => [
    [rbox(21, 8, 11.7, 0.8, 0, 4, 0, 0.5), null],
    ...['#f2a33a', '#f7f2e8', '#3a9a4a', '#2a6fd0', '#f7f2e8', '#3a9a4a', '#f7f2e8', '#7a4a1e'].map((color, i) => [flats([[-3, -4.45 + i * 1.27, 12, 0.7]], 'y', 8.01), color]),
    [flats(Array.from({ length: 8 }, (_, i) => [8.2, -4.45 + i * 1.27, 3.2, 0.55]), 'y', 8.02), GOLD],
    [box(13, 1.2, 6, 0, 0, 0).rotateZ(-0.16).translate(-1, 9, 0), null],
    [flats([[0, 4, 7, 5]], '-x', -10.51), '#9aa2a8'],
  ],
  // Manchon RJ45 : fourreau, protège-languette, queue nervurée
  rj45_boot: () => [
    [box(14, 10, 13.5, 0, 5, 0), null],
    [warp(box(10, 3, 6, -1, 11.5, 0), (v) => { if (v.x > 0) v.y = Math.min(v.y, 10 + (v.y - 10) * 0.2); }), null],
    [flats([[0, 5, 11, 7]], 'x', 7.01), HOLE],
    [latheX([[5.5, -7], [4.6, -12, 1], [3.6, -20, 1], [3.2, -24], [0, -24]], 12, 0, 5, 0), null],
    ...[[-10, 4.95], [-13.5, 4.45], [-17, 3.95], [-20.5, 3.55]].map(([x, r]) => [latheX([[r, x - 1.2], [r, x]], 12, 0, 5, 0), null]),
  ],
  // Connecteur IDC femelle 2 x 20 : contacts, détrompeur, capot de serrage
  idc: () => [
    [rbox(51, 6.5, 9, 0.5, 0, 3.25 + 2.5, 0, 0.3), null],
    [box(52, 2.5, 9.4, 0, 1.25, 0), '#4a4c52'],
    [flats(grid(20, 2, 2.54, 2.54, 1.1), 'y', 9.01), HOLE],
    [box(4.5, 3.5, 1.4, 0, 5.5, 5.2), null],
    [flats([[-24.5, -3.5, 1.6, 1.6]], 'y', 9.02), SILK],
  ],
  // Bornier à vis 3 points au pas de 5,08 : entrées de fil, vis fendues
  terminal_block: () => {
    const xs = [-5.08, 0, 5.08];
    return [
      [extrudeX(outline([[4.1, 0], [-4.1, 0], [-4.1, 7.5], [-2.6, 10], [2.6, 10], [4.1, 8]]), 15.24), null],
      [flats(xs.map((x) => [x, 3.8, 3.4, 3.6]), 'z', 4.11), HOLE],
      [flats(xs.slice(1).map((x) => [x - 2.54, 4, 0.3, 7.6]), 'z', 4.12), '#1b4b9a'],
      ...xs.flatMap((x) => [[cyl(2, 0.04, x, 10, 0, 14), HOLE], [cyl(1.6, 0.5, x, 9.6, 0, 14), STEEL], [box(3.2, 0.2, 0.5, x, 10.1, 0), '#55595f'], [box(0.8, 3.5, 0.8, x, -1.75, 0), STEEL]]),
    ];
  },
  // Cosse œillet isolée : manchon évasé, fût, languette percée
  crimp: () => {
    const c = 21; const r = 4; const w = 2.2; const a0 = Math.PI - Math.asin(w / r);
    const tongue = [[12, -w]];
    for (let i = 0; i <= 20; i++) { const a = -a0 + (i / 20) * 2 * a0; tongue.push([c + r * Math.cos(a), r * Math.sin(a)]); }
    tongue.push([12, w]);
    return [
      [latheX([[1.8, 0], [2.8, 0], [3.2, 0.4], [3.2, 2.5], [2.6, 3.2], [2.6, 9], [2, 10.5]], 16, 0, 3.2), null],
      [latheX([[0, 0.3], [1.8, 0.3]], 16, 0, 3.2), HOLE],
      [latheX([[1.7, 10.2], [1.7, 12.5], [1, 13], [0, 13]], 12, 0, 3.2), STEEL],
      [slab(outline(tongue, [hole(c, 0, 2.2)]), 0.8, 2.8, 0.1), STEEL],
    ];
  },
  // Fiche banane 4 mm : corps strié, broche à lamelles
  banana: () => [
    [latheX([[0, 0], [2.2, 0], [3.4, 1.5, 1], [4, 6], [4, 22], [4.6, 23], [4.6, 25], [3.2, 26]], 18), null],
    ...around(10, (a) => [box(14, 0.6, 0.8, 14, 4, 0).rotateX(a), null]),
    [latheX([[2, 26], [2, 28], [2.1, 30, 1], [2.3, 33, 1], [2.1, 36, 1], [2, 38, 1], [1.6, 41], [0, 41.5]], 14), CHROME],
    [box(7, 4.8, 0.25, 33.5, 0, 0), '#6f747b'], [box(7, 0.25, 4.8, 33.5, 0, 0), '#6f747b'],
    [latheX([[0, -0.05], [1.6, -0.05]], 12), HOLE],
  ],
  // Pince crocodile isolée : mâchoires dentées, gaine souple
  alligator: () => [
    [extrudeZ(outline([[-6, 0], [22, 0.6], [30, 1.8], [32, 2.6], [22, 2.2], [-6, 1.8]]), 5), STEEL],
    [extrudeZ(outline([[-6, 4.8], [22, 4.4], [32, 3.4], [30, 5], [22, 6.6], [-6, 6.6]]), 5), STEEL],
    ...Array.from({ length: 6 }, (_, i) => [box(0.8, 0.8, 5, 8 + i * 3.6, 2.4, 0), STEEL]),
    [cylZ(1.2, 5.6, -2, 3.3, 0, 10), CHROME],
    [latheX([[0, -30], [2.4, -29.5, 1], [3.2, -26, 1], [4, -18, 1], [5.4, -8, 1], [5.8, 0, 1], [5.4, 3], [0, 3]], 16).scale(1, 1.15, 0.85).translate(0, 3.3, 0), null],
  ],
  // Pointe de touche : fiche arrière, poignée striée, garde, pointe acier
  probe: () => [
    [latheX([[0, -50], [3.2, -50], [4, -49], [4, -38], [3.6, -37], [3.8, -30, 1], [4.4, -20, 1], [4.4, -6, 1], [4, -3], [7, -2.5], [7.2, -1.5], [7, 0], [3.4, 0.5], [2.6, 8, 1], [1.6, 14], [0.7, 14]], 18), null],
    ...around(8, (a) => [box(16, 0.6, 1, -14, 4.4, 0).rotateX(a), null]),
    [latheX([[0.65, 14], [0.6, 26, 1], [0.3, 28.5, 1], [0, 29]], 8), CHROME],
    [latheX([[0, -50.05], [2, -50.05]], 12), HOLE],
  ],
  // Borne de laboratoire : collerette, trou transversal, capuchon moleté, tige filetée
  binding_post: () => [
    [lathe([[0, -10], ...thread(2, 1.75, -10, 0, 0.7), [2, 0], [0, 0]], 12), BRASS],
    [slab(hexagon(8, 1.9), 2.2, -6, 0.3), BRASS],
    [lathe([[0, 0], [6.5, 0], [6.5, 2], [4.5, 2], [4.5, 8], [5.6, 8], [5.8, 8.6, 1], [5.8, 16, 1], [5.2, 17], [2, 17], [2, 16.8]], 24), null],
    ...around(18, (a) => [box(0.6, 7, 0.8, 5.8, 12.3, 0).rotateY(a), null]),
    [cylX(1.2, 9.2, 0, 6, 0, 12), HOLE],
    [cyl(2, 0.05, 0, 16.7, 0, 14), HOLE],
  ],
  // Fiche d'alimentation 5,5 x 2,1 : surmoulage strié, fût métallique
  dc_jack: () => [
    [latheX([[0, -18], [2, -18], [2, 0], [3, 1, 1], [3, 8, 1], [4.5, 14, 1], [5, 16], [5, 26], [4.6, 27], [3, 27]], 18), null],
    ...around(10, (a) => [box(9, 0.5, 0.8, 20.5, 5, 0).rotateX(a), null]),
    [latheX([[2.75, 26.9], [2.75, 36.5], [2.4, 37], [1.2, 37], [1.2, 30]], 18), CHROME],
  ],
  // Connecteur DB9 : bride à colonnettes, coquille en D, isolant, 9 broches
  dsub: () => {
    const outer = [[-8.4, -3.1], [8.4, -3.1], [7.1, 3.1], [-7.1, 3.1]];
    const inner = [[-7.6, -2.4], [7.6, -2.4], [6.5, 2.4], [-6.5, 2.4]];
    const pins = [...Array.from({ length: 5 }, (_, i) => [-5.54 + i * 2.77, -1.42]), ...Array.from({ length: 4 }, (_, i) => [-4.155 + i * 2.77, 1.42])];
    return [
      [box(18, 6, 10, 0, 3, 0), '#26282e'],
      [slab(roundRect(31, 12.5, 1, [hole(-12.5, 0, 1.6), hole(12.5, 0, 1.6)]), 0.8, 6, 0.1), null],
      [slab(outline(outer, [polyHole(inner)]), 6, 6.8, 0.2), null],
      [slab(outline(inner), 4, 6.8), '#2a2b30'],
      ...pins.map(([x, z]) => [cyl(0.5, 2, x, 10.8, z, 8), GOLD]),
      ...[-12.5, 12.5].map((x) => [slab(hexagon(4.8, 1.3), 4.5, 6.8, 0.2).translate(x, 0, 0), STEEL]),
    ];
  },

  // === Cartes et modules ===
  // Raspberry Pi 4 modèle B (85 x 56) d'après le plan mécanique officiel
  raspberry_pi: () => {
    const holes = [[-39, -24.5], [19, -24.5], [-39, 24.5], [19, 24.5]];
    const top = 1.4;
    const pins = Array.from({ length: 40 }, (_, i) => [-10 - 24.13 + (i >> 1) * 2.54, -24.5 + (i % 2 ? 1.27 : -1.27)]);
    return [
      ...board(85, 56, holes, null, 3, 1.375, top),
      // GPIO 40 broches
      [box(50.8, 2.5, 5.1, -10, top + 1.25, -24.5), BLACK],
      ...pins.map(([x, z]) => [box(0.64, 6, 0.64, x, top + 3.5, z), GOLD]),
      // SoC sous capot, mémoire, contrôleur USB, PMIC, module Wi-Fi blindé
      [rbox(15, 1.2, 15, 0.6, -16.5, top + 0.6, 2.5, 0.2), '#c3c7cd'],
      [flats([[-16.5, 2.5, 8, 1.2], [-16.5, 4.5, 6, 0.8]], 'y', top + 1.21), '#8e939a'],
      [box(9, 1.1, 14, -4.5, top + 0.55, 3), '#17181c'],
      [box(7, 0.9, 7, 13, top + 0.45, -1), '#17181c'],
      [box(5, 0.8, 5, -31, top + 0.4, 12), '#17181c'],
      [rbox(10, 1.6, 12, 0.4, -33, top + 0.8, -12, 0.2), STEEL],
      // Ethernet et USB (débordent du bord de 2 mm)
      [box(21, 13.5, 16, 34.5, top + 6.75, 17.75), STEEL],
      [flats([[17.75, top + 6, 12, 8.5]], 'x', 45.01), HOLE],
      [flats([[12.5, top + 12, 2, 1.2], [23, top + 12, 2, 1.2]], 'x', 45.02), '#e8c33a'],
      ...[[-19, '#1d1e22'], [-1, '#2a6fd0']].flatMap(([z, tongue]) => [
        [box(17.5, 16, 13.1, 35.75, top + 8, z), STEEL],
        [flats([[z, top + 4.4, 12, 5.6], [z, top + 11.6, 12, 5.6]], 'x', 44.51), HOLE],
        [flats([[z, top + 5.4, 11, 1.8], [z, top + 12.6, 11, 1.8]], 'x', 44.52), tongue],
      ]),
      // Bord inférieur : USB-C, deux micro-HDMI, jack audio
      [rbox(9, 3.2, 7.5, 0.3, -31.3, top + 1.6, 25.25, 0.3), STEEL],
      [box(6.5, 3, 7.5, -16.5, top + 1.5, 25.25), STEEL], [box(6.5, 3, 7.5, -3, top + 1.5, 25.25), STEEL],
      [flats([[-31.3, top + 1.6, 7, 1.6], [-16.5, top + 1.5, 5, 1.4], [-3, top + 1.5, 5, 1.4]], 'z', 29.01), HOLE],
      [box(7, 6, 12, 11.5, top + 3, 22), '#17181c'],
      [latheZ([[3, 28], [3, 30], [1.8, 30], [1.8, 28.2]], 14, 11.5, top + 3, 0), '#17181c'],
      [latheZ([[0, 29.6], [1.8, 29.6]], 14, 11.5, top + 3, 0), HOLE],
      // Connecteurs caméra (CSI) et écran (DSI), PoE, microSD dessous
      [box(3.2, 5.5, 22, 2.7, top + 2.75, 11), '#f0f0ee'], [box(3.4, 1, 22, 2.7, top + 5.6, 11), '#26282e'],
      [box(3.2, 5.5, 22, -39.5, top + 2.75, 0), '#f0f0ee'], [box(3.4, 1, 22, -39.5, top + 5.6, 0), '#26282e'],
      [box(5.1, 2.5, 5.1, 22.5, top + 1.25, -22), BLACK],
      ...grid(2, 2, 2.54, 2.54, 0, 22.5, -22).map(([x, z]) => [box(0.64, 6, 0.64, x, top + 3.5, z), GOLD]),
      [box(11.5, 1.4, 12, -37, -0.7, 0), STEEL],
      // Sérigraphie et voyants
      [flats([[-6, -16, 22, 1.4], [-11, -13.6, 12, 1]], 'y', top + 0.02), SILK],
      [flats([[-40, 18, 1.4, 0.8]], 'y', top + 0.03), '#e03a3a'], [flats([[-40, 15.5, 1.4, 0.8]], 'y', top + 0.03), '#3ad06a'],
    ];
  },
  // Raspberry Pi dans son boîtier officiel : socle rouge, capot blanc, logo en relief, ouvertures
  pi_case: () => [
    [rbox(94, 10, 66, 6, 0, 5, 0, 1), '#c4263c'],
    [rbox(94, 16, 66, 6, 0, 18, 0, 1.5), null],
    ...[[0, 3.4, 4.2], [-3.6, 6.4, 3.3], [3.6, 6.4, 3.3], [-4.6, 1.2, 3.3], [4.6, 1.2, 3.3], [0, -2.6, 3.3], [-3.3, -4.4, 2.6], [3.3, -4.4, 2.6]]
      .map(([x, z, r]) => [cyl(r, 0.5, x - 4, 26, z, 16), '#e4e4e0']),
    [cyl(2.2, 0.5, -6.5, 26, -9.5, 10).scale(1, 1, 0.6), '#e4e4e0'], [cyl(2.2, 0.5, -1.5, 26, -9.5, 10).scale(1, 1, 0.6), '#e4e4e0'],
    [flats([[-19, 17, 13, 14], [-1, 17, 13, 14], [17.75, 16, 15, 12]], 'x', 47.01), HOLE],
    [flats([[-31.3, 13, 9, 3.6], [-16.5, 13, 7, 3.4], [-3, 13, 7, 3.4], [11.5, 13, 6, 6]], 'z', 33.01), HOLE],
    [flats([[-44, 24, 3, 1.2]], 'y', 26.01), '#3ad06a'],
  ],
  // Carte à matrice 8 x 8 (Sense HAT) : LED allumées en arc-en-ciel, joystick, connecteur dessous
  sense_hat: () => {
    const holes = [[-29, -24.5], [29, -24.5], [-29, 24.5], [29, 24.5]];
    const leds = [];
    for (let i = 0; i < 64; i++) {
      const x = -5 + ((i % 8) - 3.5) * 3.9; const z = 3 + (Math.floor(i / 8) - 3.5) * 3.9;
      leds.push([box(3.2, 1.2, 3.2, x, 2.2, z), WHITE]);
      leds.push([flats([[x, z, 1.8, 1.8]], 'y', 2.82), `#${new THREE.Color().setHSL(((i % 8) + Math.floor(i / 8)) / 16, 0.85, 0.55).getHexString()}`]);
    }
    return [
      ...board(65, 56.5, holes, null, 3, 1.375),
      ...leds,
      [box(5.2, 2, 5.2, 24, 2.6, 18), '#26282e'], [cyl(1, 2, 24, 3.6, 18, 10), '#3a3b42'], [cyl(1.8, 0.8, 24, 5.4, 18, 14), BLACK],
      [box(51, 8.5, 5.1, 0, -4.25, -24.5), BLACK],
      [flats(Array.from({ length: 40 }, (_, i) => [-24.13 + (i >> 1) * 2.54, -24.5 + (i % 2 ? 1.27 : -1.27), 1.2, 1.2]), 'y', 1.62), GOLD],
      ...[[18, -8], [24, -8], [18, 2], [26, 6]].map(([x, z]) => [box(3, 0.9, 3, x, 2.05, z), '#17181c']),
      [flats([[20, 24, 14, 1.2], [-24, 22, 8, 1]], 'y', 1.62), SILK],
    ];
  },
  // Carte microSD : contour à encoche, étiquette, contacts dorés dessous
  sd_card: () => [
    [slab(outline([[-7.5, -5.5], [7.5, -5.5], [7.5, 5.5], [-1.6, 5.5], [-2.3, 4.6], [-5.4, 4.6], [-5.8, 5.3], [-7.5, 5.5]]), 0.8, 0, 0.1), null],
    [flats([[2.5, 0, 9, 9.6]], 'y', 0.81), '#c4263c'],
    [flats([[3.5, -2.5, 5, 1.6], [3.5, 0.5, 3.2, 1], [-4.8, 0, 3, 3]], 'y', 0.82), SILK],
    [flats(Array.from({ length: 8 }, (_, i) => [-6.2, -4.4 + i * 1.1, 2.4, 0.7]), '-y', -0.01), GOLD],
  ],
  // Module capteur : micro électret, comparateur, potentiomètre de seuil, LED, broches coudées
  sensor: () => [
    ...board(32, 20, [[-13, -6.5], [-13, 6.5]], null, 1.2, 1.5),
    [lathe([[0, 1.6], [4.8, 1.6], [4.8, 6.4], [4.4, 6.8], [0, 6.8]], 20).translate(-4, 0, 0), STEEL],
    [cyl(4, 0.05, -4, 6.8, 0, 20), '#2a2b30'],
    [box(5, 1.5, 4, 5, 2.35, -4.5), '#17181c'],
    [box(6.5, 4.8, 4.5, 4.6, 4, 4.8), '#2a5fc4'], [cyl(1, 0.6, 2.4, 6.4, 4.8, 12), BRASS],
    [box(1.6, 0.8, 0.8, -12.5, 2, 0), '#e03a3a'], [box(1.6, 0.8, 0.8, 10, 2, -8.5), '#3ad06a'],
    [box(2.5, 2.5, 7.6, 14, 2.85, 0), BLACK],
    ...[-2.54, 0, 2.54].map((z) => [box(7, 0.64, 0.64, 18.5, 2.85, z), GOLD]),
    [flats([[11.5, -6, 2, 0.8], [11.5, 6, 2, 0.8], [-1, 8.2, 10, 0.9]], 'y', 1.62), SILK],
  ],
  // Module Grove 20 x 40 : connecteur blanc 4 points, composant, sérigraphie
  grove_module: () => [
    ...board(40, 20, [[0, -7.5], [0, 7.5]], null, 1, 1.1),
    [rbox(5, 6, 10, 0.3, -15, 4.6, 0, 0.2), WHITE],
    [flats([[-15, 0, 3.2, 8]], 'y', 7.61), '#d9d6cc'], [flats(grid(1, 4, 0, 2, 0.6, -15, 0), 'y', 7.62), GOLD],
    [box(6, 1.6, 6, 10, 2.4, 0), '#17181c'],
    [cyl(1.6, 1.4, 4, 1.6, 6, 12), '#e8c33a'],
    ...[[4, -5], [6.5, -5], [15, 6]].map(([x, z]) => [box(1.6, 0.6, 0.8, x, 1.9, z), '#2a2b30']),
    [flats([[-9, 7.5, 6, 1.2], [6, -8, 10, 1], [-9, -7.5, 4, 1]], 'y', 1.62), SILK],
  ],
  // Capteur à ultrasons HC-SR04 (45 x 20) : émetteur et récepteur, quartz, 4 broches
  ultrasonic: () => [
    ...board(45, 20, [[-20.5, -8], [20.5, -8], [-20.5, 8], [20.5, 8]], null, 0.8, 1),
    ...[-13, 13].flatMap((x) => [
      [lathe([[0, 1.6], [8, 1.6], [8, 13.2], [7.6, 13.6], [6.3, 13.6], [6.3, 13.3]], 24).translate(x, 0, 0), STEEL],
      [cyl(6.3, 0.05, x, 13.25, 0, 24), '#3c3e44'],
      [flats(grid(6, 6, 1.8, 1.8, 0.7, x, 0).filter(([u, v]) => (u - x) ** 2 + v ** 2 < 25), 'y', 13.31), '#6d7077'],
    ]),
    [rbox(11, 3.5, 4.6, 2, 0, 3.35, -7, 0.4), CHROME],
    [box(10.2, 2.5, 2.5, 0, 2.85, 8.6), BLACK],
    ...[-3.81, -1.27, 1.27, 3.81].map((x) => [box(0.64, 0.64, 7, x, 2.85, 13.3), GOLD]),
    [flats([[-13, -9, 1.2, 1.2], [13, -9, 1.2, 1.2], [0, 5.5, 9, 0.8]], 'y', 1.62), SILK],
  ],
  // Afficheur LCD 16 x 2 (80 x 36) : cadre noir, écran rétroéclairé, cellules de caractères, pastilles
  lcd: () => [
    ...board(80, 36, [[-37.5, -15.5], [37.5, -15.5], [-37.5, 15.5], [37.5, 15.5]], null, 1, 1.25),
    [box(71.2, 7.5, 24.2, 0, 1.6 + 3.75, 0), '#141518'],
    [box(64.5, 0.1, 14.5, 0, 9.12, 0), '#3d7be0'],
    [flats(Array.from({ length: 32 }, (_, i) => [-26.6 + (i % 16) * 3.55, (i < 16 ? -3 : 3), 2.95, 5.55]), 'y', 9.18), '#5d95ee'],
    [flats([[-24.8, -3, 2.9, 5.5], [-21.25, -3, 2.9, 5.5], [-17.7, -3, 2.9, 5.5], [-14.15, -3, 2.9, 5.5], [-10.6, -3, 2.9, 5.5]], 'y', 9.19), '#e9f1ff'],
    [box(2.5, 6, 14, -37, 4.6, 0), '#8c8f95'], [box(2.5, 6, 14, 37, 4.6, 0), '#8c8f95'],
    [flats(Array.from({ length: 16 }, (_, i) => [-32 + i * 2.54, -16.2, 1.6, 1.6]), 'y', 1.61), GOLD],
    [flats(Array.from({ length: 16 }, (_, i) => [-32 + i * 2.54, -16.2, 0.8, 0.8]), 'y', 1.62), HOLE],
  ],
  // Plaque d'essai 830 points : rangées de 5, rails d'alimentation rouge et bleu, gorge centrale
  breadboard: () => {
    const main = [];
    for (let c = 0; c < 63; c++) for (const s of [-1, 1]) for (let k = 0; k < 5; k++) main.push([(c - 31) * 2.54, s * (3.81 + k * 2.54), 1.1, 1.1]);
    const rails = [];
    for (const z of [-22.86, -20.32, 20.32, 22.86]) for (let g = 0; g < 10; g++) for (let k = 0; k < 5; k++) rails.push([-69.85 + g * 15.24 + k * 2.54, z, 1.1, 1.1]);
    return [
      [rbox(165, 9, 55, 1.2, 0, 4.5, 0, 0.4), null],
      [flats([[0, 0, 158, 2.4]], 'y', 9.01), '#cbc5b4'],
      [flats([...main, ...rails], 'y', 9.02), '#3c3e44'],
      [flats([[0, -25.4, 150, 0.5], [0, 17.8, 150, 0.5]], 'y', 9.02), '#c4263c'],
      [flats([[0, -17.8, 150, 0.5], [0, 25.4, 150, 0.5]], 'y', 9.02), '#2a5fc4'],
    ];
  },
  // Plaque à trous (pastilles cuivrées au pas de 2,54)
  perfboard: () => [
    [slab(roundRect(70, 50, 0.6, [[-32, -22], [32, -22], [-32, 22], [32, 22]].map(([x, z]) => hole(x, z, 1.6))), 1.6), null],
    [flats(grid(24, 16, 2.54, 2.54, 1.8), 'y', 1.61), '#d9a066'],
    [flats(grid(24, 16, 2.54, 2.54, 0.8), 'y', 1.62), HOLE],
  ],
  // Carte d'extension (HAT) : connecteur 40 points dessous, circuits, bornier, LED, condensateur
  red_board: () => {
    const holes = [[-29, -24.5], [29, -24.5], [-29, 24.5], [29, 24.5]];
    return [
      ...board(65, 56.5, holes, null, 3, 1.375),
      [box(51, 8.5, 5.1, 0, -4.25, -24.5), BLACK],
      [flats(Array.from({ length: 40 }, (_, i) => [-24.13 + (i >> 1) * 2.54, -24.5 + (i % 2 ? 1.27 : -1.27), 1.2, 1.2]), 'y', 1.62), GOLD],
      [box(10, 1.6, 6, -10, 2.4, 0), '#17181c'], [box(5, 1.4, 4, 6, 2.3, -6), '#17181c'],
      [extrudeX(outline([[3.8, 0], [-3.8, 0], [-3.8, 7], [-2.4, 9], [2.4, 9], [3.8, 7.5]]), 10.16).translate(24, 1.6, 6), '#2a6fd0'],
      ...[21.46, 26.54].flatMap((x) => [[cyl(1.5, 0.4, x, 10.4, 6, 12), STEEL], [flats([[x, 10, 3, 3]], 'z', 9.81), HOLE]]),
      [cyl(3, 7, 10, 1.6, 14, 16), '#27408b'], [cyl(2.9, 0.1, 10, 8.6, 14, 16), STEEL],
      ...[[-24, 16, '#e03a3a'], [-20, 16, '#3ad06a'], [-16, 16, '#e8c33a']].map(([x, z, color]) => [lathe([[0, 1.6], [1.5, 1.6], [1.5, 4, 1], [1.2, 5, 1], [0, 5.4]], 12).translate(x, 0, z), color]),
      [flats([[-12, 10, 18, 1.2], [14, -14, 12, 1]], 'y', 1.62), SILK],
    ];
  },

  // === Robotique ===
  // Motoréducteur 25 mm (type JGA25-370) : réducteur, axe en D excentré, moteur, cosses
  motor: () => {
    const y = 12.5;
    return [
      [latheX([[0, 0], [11.8, 0], [12.5, 0.7], [12.5, 20.3], [12.2, 21]], 28, 0, y), null],
      ...[3.5, 9.5, 15.5].map((x) => [latheX([[12.55, x], [12.55, x + 0.5]], 28, 0, y), '#7d8188']),
      ...[-8.5, 8.5].map((z) => [cylX(1.5, 0.1, -0.04, y, z, 10), HOLE]),
      [latheX([[0, -2.5], [4, -2.5], [4, 0]], 16, 0, y + 7, 0), CHROME],
      [prismX(dShape(2, 1.5), 12, 0.1).translate(-14.5, y + 7, 0), CHROME],
      [latheX([[12.2, 21], [12.2, 51], [11.8, 52], [11.6, 52]], 28, 0, y), STEEL],
      [latheX([[11.6, 52], [11.6, 55], [10, 56], [0, 56]], 24, 0, y), '#26282e'],
      ...[-6, 6].map((z) => [box(4, 2.4, 0.4, 57.6, y, z), BRASS]),
      [flats([[0, y, 4, 4]], 'x', 56.01), '#55595f'],
    ];
  },
  // Moteur pas à pas NEMA 17 : flasques aluminium, stator noir, axe en D, connecteur et 4 fils
  stepper: () => [
    [slab(chamferSquare(42.3, 5, [[-15.5, -15.5], [15.5, -15.5], [-15.5, 15.5], [15.5, 15.5]].map(([x, z]) => hole(x, z, 1.5))), 8, 0, 0.4), ALU],
    [slab(chamferSquare(42, 3.5), 24, 8, 0.2), null],
    ...[14, 20, 26].map((y) => [slab(chamferSquare(42.2, 3.6), 0.4, y), '#3a3c42']),
    [slab(chamferSquare(42.3, 5, [[-15.5, -15.5], [15.5, -15.5], [-15.5, 15.5], [15.5, 15.5]].map(([x, z]) => hole(x, z, 1.5))), 8, 32, 0.4), ALU],
    [lathe([[0, 40], [11, 40], [11, 41.6], [10.6, 42], [0, 42]], 28), ALU],
    [slab(dShape(2.5, 2), 22, 42, 0.15), CHROME],
    [box(5, 5.5, 15, 23.6, 4, 0), WHITE],
    ...['#c4263c', '#2a5fc4', '#2e9d6a', '#17181c'].map((color, i) => [bend([[26, 4, -4.5 + i * 3], [36, 3, -6 + i * 4], [52, 2, -8 + i * 5], [70, 1.5, -6 + i * 4]], 0.8, 14, 6), color]),
  ],
  // Servomoteur standard : boîtier, pattes de fixation, palonnier en croix, étiquette, câble 3 fils
  servo: () => [
    [rbox(40.7, 26.5, 19.7, 1, 0, 13.25, 0, 0.6), null],
    [slab(roundRect(54.5, 18, 1, [hole(-24.6, -5, 2.2), hole(-24.6, 5, 2.2), hole(24.6, -5, 2.2), hole(24.6, 5, 2.2)]), 2.5, 26.5, 0.3), null],
    [rbox(40.7, 8.5, 19.7, 1, 0, 33.25, 0, 0.6), null],
    [lathe([[0, 37.5], [7.2, 37.5], [7, 39.3, 1], [6, 40.3], [0, 40.3]], 24).translate(-10.2, 0, 0), null],
    [cyl(4.5, 1, 4, 37.5, 0, 18), null],
    [cyl(2.9, 2, -10.2, 40.3, 0, 18), BRASS],
    [cyl(5, 3.2, -10.2, 40.6, 0, 20), WHITE],
    [rbox(38, 2, 6, 2.8, -10.2, 44.5, 0, 0.4), WHITE], [rbox(6, 2, 30, 2.8, -10.2, 44.5, 0, 0.4), WHITE],
    [flats([[-25.2, 0, 1, 1], [-21.2, 0, 1, 1], [0.8, 0, 1, 1], [4.8, 0, 1, 1], [-10.2, -10, 1, 1], [-10.2, 10, 1, 1]], 'y', 45.51), HOLE],
    [cyl(1.4, 0.1, -10.2, 45.5, 0, 12), '#55595f'],
    [flats([[0, 14, 32, 14]], 'z', 9.86), '#ecebe6'], [flats([[0, 18, 32, 3]], 'z', 9.87), '#2a5fc4'],
    ...[['#6b3e1e', -1.2], ['#c4263c', 0], ['#e8912c', 1.2]].map(([color, z]) => [bend([[20.5, 3, z], [32, 2.4, z + 3], [48, 1.2, z], [58, 1.2, z]], 0.6, 16, 6), color]),
    [box(8, 2.6, 7.6, 62, 1.3, 0), BLACK],
  ],
  // Roue de robot 65 mm : pneu à pavés, jante ajourée, moyeu à méplat
  robot_wheel: () => [
    [lathe([[26, 0], [30.5, 0], [32.4, 1.5, 1], [33, 5, 1], [33, 21, 1], [32.4, 24.5, 1], [30.5, 26], [26, 26], [26, 0]], 40), '#17181c'],
    ...around(30, (a) => [box(1.2, 20, 3.2, 33.2, 13, 0).rotateY(a), '#17181c']),
    [lathe([[22, 1], [26.2, 1], [26.2, 25], [22, 25], [22, 1]], 40), null],
    [slab(disc(22.2, [hole(0, 0, 3.2), ...around(6, (a) => hole(Math.cos(a) * 14, Math.sin(a) * 14, 5))]), 3, 11.5, 0.3), null],
    [lathe([[3.2, 3], [6.5, 3], [6.5, 23], [3.2, 23], [3.2, 3]], 20), null],
    [slab(dShape(2.8, 1.9), 0.1, 23, 0), HOLE],
  ],
  // Pneu seul : flancs arrondis, bande de roulement à pavés
  tyre: () => [
    [lathe([[17, 0], [23, 0], [25.4, 1.2, 1], [26.4, 4, 1], [26.6, 9, 1], [26.4, 14, 1], [25.4, 16.8, 1], [23, 18], [17, 18], [17.6, 9, 1], [17, 0]], 40), null],
    ...around(28, (a) => [box(1.2, 6.5, 3.6, 26.7, 4.6, 0).rotateY(a), null]),
    ...around(28, (a) => [box(1.2, 6.5, 3.6, 26.7, 13.4, 0).rotateY(a + Math.PI / 28), null]),
  ],
  // Poulie crantée : flasques, denture, moyeu à deux vis de pression
  pulley: () => [
    [slab(disc(26.6, [hole(0, 0, 4)]), 1.2, 0, 0.2), null],
    [slab(gear(36, 24.4, 23.2, 4), 7, 1.2), null],
    [slab(disc(26.6, [hole(0, 0, 4)]), 1.2, 8.2, 0.2), null],
    [lathe([[4, 9.4], [9, 9.4], [9, 17], [8.6, 17.4], [4, 17.4], [4, 9.4]], 24), null],
    [cylX(1.4, 0.4, 8.95, 13.4, 0, 10), HOLE], [cylZ(1.4, 0.4, 0, 13.4, 8.95, 10), HOLE],
    [flats(around(6, (a) => [Math.cos(a) * 16, Math.sin(a) * 16, 2.4, 2.4]), 'y', 9.41), '#123a66'],
  ],
  // Plaque perforée : trous M4 au pas de 8
  plate: () => [[slab(roundRect(120, 48, 3, grid(15, 6, 8, 8, 0).map(([x, z]) => hole(x, z, 2.1))), 2.5, 0, 0), null]],

  // === Alimentation ===
  // Pile AA (Ø 14,5 x 50,5) : gaine, bande cuivrée, téton positif ; mise à l'échelle d'après le nom
  battery: () => [
    [latheX([[0, -25.25], [6.4, -25.25], [7.05, -24.9, 1], [7.25, -24.3, 1], [7.25, 23.3], [7.05, 23.75, 1], [6.4, 24], [2.6, 24]], 24), null],
    [latheX([[7.27, 13], [7.27, 23.3]], 24), '#c47a2c'],
    [latheX([[0, -25.27], [5.6, -25.27], [5.6, -25.2]], 20), STEEL],
    [latheX([[2.6, 24], [2.6, 24.9, 1], [2.3, 25.2, 1], [0, 25.25]], 16), STEEL],
    [new THREE.CylinderGeometry(7.26, 7.26, 22, 6, 1, true, -0.4, 0.8).rotateZ(-Math.PI / 2).translate(-6, 0, 0), SILK],
  ],
  // Pile 9 V : bande cuivrée, pression positive ronde et négative hexagonale
  battery_9v: () => [
    [rbox(26.5, 17.5, 46.5, 2.2, 0, 8.75, -1, 0.8), null],
    [rbox(26.7, 17.7, 8, 2.3, 0, 8.75, 18.3, 0.8), '#c47a2c'],
    [rbox(25.5, 16.5, 1.2, 2, 0, 8.75, 22.8, 0.3), '#2a2b30'],
    [latheZ([[0, 23.3], [2.4, 23.3], [2.4, 25.5], [2.9, 26, 1], [2.9, 27, 1], [2.2, 27.6], [0, 27.6]], 16, -6.35, 8.75, 0), STEEL],
    [prismZ(hexagon(7.7, 2.6), 3.5, 0.2).translate(6.35, 8.75, 23.3), STEEL],
    [flats([[0, -8, 18, 22]], 'y', 17.51), '#f4f1e6'], [flats([[0, -15, 18, 4]], 'y', 17.52), '#c47a2c'],
  ],
  // Support 4 piles AA : bac, séparations, ressorts, contacts, fils rouge et noir
  battery_holder: () => {
    const cells = [-21, -7, 7, 21];
    return [
      [box(62.5, 1.5, 58, 0, 0.75, 0), null],
      [box(62.5, 15, 1.5, 0, 7.5, 28.25), null], [box(62.5, 15, 1.5, 0, 7.5, -28.25), null],
      [box(1.5, 15, 58, 30.5, 7.5, 0), null], [box(1.5, 15, 58, -30.5, 7.5, 0), null],
      ...[-14, 0, 14].map((z) => [box(58, 6, 1.2, 0, 4.5, z), null]),
      ...cells.flatMap((z) => [
        [coil(3.6, 5, 0.4, 1.4).rotateZ(-Math.PI / 2).translate(-29.5, 7.5, z), STEEL],
        [box(0.5, 8, 7, 29.4, 7.5, z), STEEL],
        [flats([[z, 12, 4, 0.8]], 'x', -29.74), SILK],
      ]),
      [bend([[-31, 3, -24], [-40, 2.5, -26], [-55, 1, -22], [-70, 1, -24]], 0.8, 12, 6), '#c4263c'],
      [bend([[-31, 3, -20], [-40, 2.5, -20], [-55, 1, -16], [-70, 1, -18]], 0.8, 12, 6), BLACK],
    ];
  },

  // === Outils ===
  // Tournevis de précision : corps moleté, capuchon pivotant, tige fine
  precision_driver: () => [
    [latheX([[0, -48], [4.5, -48], [5, -47.4, 1], [5.2, -46, 1], [5.2, -41], [4.2, -40.5]], 18, 0, 5.2), '#2a6fd0'],
    [latheX([[4.2, -40.5], [4.6, -40, 1], [4.6, -5, 1], [4.2, -3], [3.6, 0, 1], [2.6, 2.5, 1], [1.6, 4]], 16, 0, 5.2), null],
    ...around(12, (a) => [box(30, 0.5, 0.7, -23, 0, 0).translate(0, 4.6, 0).rotateX(a).translate(0, 5.2, 0), '#5d6168']),
    [cylX(1.2, 46, 27, 5.2, 0, 10), STEEL],
    [warp(box(4, 2.2, 1.6, 51.5, 5.2, 0), (v) => { if (v.x > 51) v.z *= 0.3; }), STEEL],
  ],
  // Coffret d'embouts ouvert : 24 embouts hexagonaux dans leurs logements
  driver_set: () => {
    const tips = [];
    for (const z of [-15, 15]) {
      for (let i = 0; i < 12; i++) {
        const x = -55 + i * 10;
        tips.push([prismZ(hexagon(6.35), 18, 0.2).translate(x, 13.5, z - 11), STEEL]);
        tips.push([latheZ([[3.2, 0], [2.2, 4, 1], [1.2, 7], [0.4, 7.4]], 8, x, 13.5, z + 7), STEEL]);
      }
    }
    return [
      [rbox(130, 14, 75, 4, 0, 7, 0, 1), null],
      [rbox(124, 0.6, 69, 3, 0, 14.2, 0, 0.1), '#33363c'],
      ...[-15, 15].map((z) => [flats([[0, z, 122, 8]], 'y', 14.51), '#141518']),
      ...tips,
      [cylX(2, 120, 0, 13, -38, 10), DARK],
      [flats([[0, 7, 40, 4]], 'z', 37.51), '#8fc23a'],
    ];
  },
  // Pince coupante diagonale : tête courte, tranchants, poignées gainées
  cutter: () => [
    [slab(outline([[26, -8], [38, -9.5], [48, -8], [56, -3.5], [58.5, 0], [56, 3.5], [48, 8], [38, 9.5], [26, 8]]), 9, 0, 1), STEEL],
    [flats([[47, 0, 22, 0.5]], 'y', 9.01), '#5b5f66'],
    [flats([[47, -1.4, 18, 1.5], [47, 1.4, 18, 1.5]], 'y', 9.005), '#e6e8eb'],
    [cyl(5.2, 10, 30, -0.5, 0, 18), '#e2e5e9'],
    ...[-1, 1].flatMap((s) => [
      [bend([[26, 4.5, s * 5], [18, 4.5, s * 7.5], [12, 4.5, s * 9]], 4, 6, 8), STEEL],
      [bend([[14, 4.5, s * 8.5], [-20, 4.5, s * 13], [-60, 4.5, s * 18], [-90, 4.5, s * 20]], 5.4, 18, 10), null],
      [new THREE.SphereGeometry(6, 12, 8).translate(-90, 4.5, s * 20), null],
    ]),
  ],
  // Clé Allen coudée, section hexagonale
  hex_key: () => [[bend([[-50, 2, 0], [8, 2, 0], [16, 2, 0.6], [20.4, 2, 4], [21.4, 2, 10], [21.4, 2, 26]], 2, 40, 6), null]],
  // Brucelles : deux lames plates soudées à l'arrière, stries de prise
  tweezers: () => {
    const blade = () => slab(outline([[-60, -3], [-20, -3.5], [30, -2.4], [56, -0.8], [60, -0.4], [60, 0.4], [56, 0.8], [30, 2.4], [-20, 3.5], [-60, 3]]), 0.9, 0, 0.2);
    const lift = (g) => g.translate(60, 0, 0).rotateZ(0.085).translate(-60, 1.2, 0);
    return [
      [blade(), null],
      [lift(blade()), null],
      [lift(flats(Array.from({ length: 10 }, (_, i) => [-12 + i * 2.6, 0, 0.6, 5]), 'y', 0.91)), '#8d9198'],
      [box(10, 2.4, 7, -56, 1.2, 0), null],
    ];
  },
  // Réglet acier 300 mm : graduations millimétriques, chiffres, trou de suspension
  ruler: () => {
    const ticks = Array.from({ length: 291 }, (_, i) => { const l = i % 10 === 0 ? 6 : i % 5 === 0 ? 4 : 2.5; return [-145 + i, -12.5 + l / 2, 0.25, l]; });
    const digits = Array.from({ length: 29 }, (_, i) => [-135 + i * 10 + 1.6, -3.5, 1.4, 2.4]);
    return [
      [slab(roundRect(300, 25, 1.5, [hole(143, 0, 2.5)]), 0.8, 0, 0.1), null],
      [flats(ticks, 'y', 0.81), '#26282e'],
      [flats(digits, 'y', 0.81), '#4a4e55'],
      [flats([[-120, 6, 30, 3]], 'y', 0.81), '#7c8086'],
    ];
  },
  // Cutter à lame sécable 18 mm : corps, insert caoutchouc, curseur, lame à segments
  knife: () => [
    [slab(outline([[-75, -11], [55, -11], [68, -7], [68, 8], [55, 12], [-70, 12], [-75, 9]]), 13, 0, 1.5), null],
    [slab(outline([[-62, -8], [24, -8], [30, -4], [30, 5], [24, 9], [-62, 9]]), 0.8, 12.8, 0.2), '#26282e'],
    ...Array.from({ length: 10 }, (_, i) => [box(1.2, 0.5, 14, -55 + i * 8, 13.6, 0.5), '#3a3b42']),
    [rbox(18, 7, 3.4, 1, 6, 6.5, 13.4, 0.6), '#26282e'],
    ...Array.from({ length: 5 }, (_, i) => [box(0.8, 7.2, 0.8, -1 + i * 3.5, 6.5, 15.2), '#3a3b42']),
    [slab(outline([[40, -9], [84, -9], [95, 9], [40, 9]]), 0.5, 6.25), CHROME],
    ...[73, 81].map((x) => [box(0.4, 0.55, 16).rotateY(-0.55).translate(x, 6.5, 0), '#7c8086']),
  ],
  // Pinceau plat : manche en bois, virole aplatie, soies
  brush: () => [
    [latheX([[0, -80], [3, -79.6, 1], [4.4, -77, 1], [4.6, -70, 1], [5.6, -40, 1], [6.4, -12, 1], [6.6, 0]], 14, 0, 7), null],
    [latheX([[6.6, -0.5], [7, 0, 1], [7, 22, 1], [6.8, 22.5], [0, 22.5]], 16).scale(1, 0.6, 1.5).translate(0, 7, 0), STEEL],
    [warp(box(28, 7, 20, 36, 7, 0), (v) => { if (v.x > 30) { const k = 1 - (v.x - 30) / 30; v.y = 7 + (v.y - 7) * k; } }), '#d7c08f'],
  ],
  // Pointeau octogonal : tête chanfreinée, corps, cône, pointe
  punch: () => [
    [latheX([[0, -55], [3.4, -55], [4.6, -53.8], [4.8, -52], [4.8, -22], [4.6, -20, 1], [3, -5, 1], [1.6, 8, 1], [1, 14], [0, 18]], 8, 0, 4.8), null],
    ...[-48, -44, -40, -36, -32, -28].map((x) => [latheX([[4.85, x], [4.85, x + 1]], 8, 0, 4.8), '#6f747b']),
  ],
  // Trousse à outils souple : fermeture éclair, tirette, poignée, étiquette
  pouch: () => [
    [rbox(220, 22, 110, 14, 0, 11, 0, 6), null],
    [rbox(221, 2.4, 111, 14.5, 0, 11, 0, 0.6), '#2a2b30'],
    [rbox(221.4, 0.8, 111.4, 14.7, 0, 11, 0, 0.2), '#a9adb4'],
    [box(4, 1.5, 12, 112, 11, 30), STEEL], [rbox(5, 1.4, 14, 2, 112, 11, 41, 0.4), '#2a2b30'],
    [rbox(46, 0.6, 16, 2, -60, 22.1, 0, 0.2), '#c4263c'],
    [bend([[-112, 11, -20], [-124, 11, -16], [-130, 11, 0], [-124, 11, 16], [-112, 11, 20]], 2, 16, 6), '#2a2b30'],
  ],
  // Pompe à dessouder : corps aluminium, buse PTFE, bouton de déclenchement, piston
  desolder: () => [
    [latheX([[0, 98], [1.4, 98], [1.6, 97.5], [3.2, 86, 1], [5.6, 80], [5.6, 76]], 16, 0, 9.8), '#ece7d6'],
    [latheX([[5.6, 76], [9.4, 76], [9.8, 75], [9.8, 70], [9.4, 70]], 20, 0, 9.8), BLACK],
    [latheX([[9.6, 70], [9.6, -60]], 22, 0, 9.8), null],
    ...[66, 62, 58].map((x) => [latheX([[9.65, x], [9.65, x - 1.5]], 22, 0, 9.8), '#2f63ad']),
    [latheX([[9.4, -60], [9.8, -61], [9.8, -66], [3, -66.5], [2.6, -66.5]], 20, 0, 9.8), BLACK],
    [cylX(2.5, 30, -81, 9.8, 0, 12), CHROME],
    [latheX([[0, -110], [6, -110], [7, -108.5, 1], [7.2, -102, 1], [7, -97, 1], [6, -95.5], [2.5, -95.5]], 18, 0, 9.8), BLACK],
    [rbox(12, 4, 6, 2, 50, 19.2, 0, 1), BLACK],
  ],

  // === Divers ===
  // Boîte de rangement : bac, couvercle à fenêtre et compartiments, loquets, charnières
  case: () => [
    [rbox(120, 20, 80, 4, 0, 10, 0, 1), null],
    [rbox(122, 6, 82, 4.5, 0, 23, 0, 1.2), null],
    [rbox(110, 0.3, 70, 3, 0, 26.05, 0, 0.1), '#a9d6f5'],
    [flats([[-27.5, 0, 1, 68], [0, 0, 1, 68], [27.5, 0, 1, 68], [0, 0, 108, 1]], 'y', 26.21), '#7fb6de'],
    ...[-30, 30].map((x) => [rbox(14, 12, 3, 1, x, 18, 41.6, 0.5), '#26282e']),
    ...[-35, 35].map((x) => [cylX(2.4, 20, x, 20, -41.5, 12), null]),
    [flats([[0, 9, 40, 9]], 'z', 40.01), '#f7f5ee'],
  ],
  // Sachet zip : fermeture, bande blanche inscriptible
  bag: () => [
    [rbox(60, 2.6, 80, 2, 0, 1.3, 0, 1.2), null],
    [box(60, 0.3, 6, 0, 0.4, -37), '#cfd8dd'],
    [flats([[0, -32.5, 58, 0.9], [0, -30.6, 58, 0.9]], 'y', 2.62), '#3a86d0'],
    [flats([[0, -20, 50, 14]], 'y', 2.63), '#f7f5ee'],
    [flats([[0, -23, 44, 0.4], [0, -19, 44, 0.4], [0, -15, 44, 0.4]], 'y', 2.64), '#b9bec4'],
  ],
  // Mousse à prédécouper (pick and pluck)
  foam: () => [
    [box(90, 22, 60, 0, 11, 0), null],
    [flats([...Array.from({ length: 11 }, (_, i) => [-37.5 + i * 7.5, 0, 0.6, 60]), ...Array.from({ length: 7 }, (_, i) => [0, -22.5 + i * 7.5, 90, 0.6])], 'y', 22.01), '#33363b'],
  ],
};

export const MODEL_NAMES = Object.keys(MODELS);

const geometryCache = new Map();

/** Assemble un modèle : une géométrie avec couleurs de sommets, centrée en X-Z, posée sur y = 0. */
function modelGeometry(visual, color) {
  const key = `${visual}|${color}`;
  if (geometryCache.has(key)) return geometryCache.get(key);
  const positions = [];
  const normals = [];
  const colors = [];
  const paint = new THREE.Color();
  for (const [part, partColor] of (MODELS[visual] ?? MODELS.box)()) {
    const flat = part.index ? part.toNonIndexed() : part;
    // Boucle plutôt que push(...tableau) : les câbles comptent trop de sommets pour la pile d'appels
    for (const value of flat.attributes.position.array) positions.push(value);
    for (const value of flat.attributes.normal.array) normals.push(value);
    paint.set(partColor ?? color ?? '#8b909a');
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
let ghostMaterial = null;
/** Objet présent : couleurs du modèle. Objet emprunté : silhouette grise translucide, « sa place est ici ». */
function material(borrowed = false) {
  if (borrowed) {
    ghostMaterial ??= new THREE.MeshStandardMaterial({ color: 0x9aa1ad, roughness: 0.9, metalness: 0, transparent: true, opacity: 0.32, depthWrite: false });
    ghostMaterial.userData.shared = true;
    return ghostMaterial;
  }
  sharedMaterial ??= new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.5, metalness: 0.1, side: THREE.DoubleSide });
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
  if (visual === 'battery') {
    const size = batterySize(name);
    return new THREE.Vector3(size.length / 50.5, size.diameter / 14.5, size.diameter / 14.5);
  }
  const { diameter, length } = sizeFromName(name);
  if (visual === 'screw' && (diameter || length)) {
    const d = (diameter ?? 3) / 3;
    return new THREE.Vector3(((length ?? 12) + 1.8) / 13.8, d, d);
  }
  if ((visual === 'nut' || visual === 'washer') && diameter) return new THREE.Vector3(diameter / 3, diameter / 3, diameter / 3);
  return new THREE.Vector3(1, 1, 1);
}

/**
 * Modèle d'un objet pour une vignette : un exemplaire, ou un petit tas pour les pièces en vrac, trop fines seules
 * (une résistance et ses longues pattes). La géométrie reste partagée : ne pas la libérer.
 */
export function thumbModel(entry) {
  const visual = resolveVisual(entry);
  const geometry = modelGeometry(visual, entry.visual_color ?? DEFAULT_TINT[visual]);
  const scale = scaleFor(visual, entry.name);
  if (LOOSE.has(visual) && visual !== 'box') {
    const size = geometry.userData.size.clone().multiply(scale);
    const side = Math.max(size.x, size.z) * 2.2;
    return buildObjects(1, { width: side, depth: side, height: size.y * 2.5 }, [{ ...entry, item_id: 1, quantity: 6 }]);
  }
  const mesh = new THREE.Mesh(geometry, material());
  mesh.scale.copy(scale);
  return mesh;
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
  // Plusieurs objets dans un même rangement : une case chacun, en grille proche de la forme du rangement
  const usableW = inside.width - 2 * margin;
  const usableD = inside.depth - 2 * margin;
  const columns = Math.max(1, Math.min(entries.length, Math.round(Math.sqrt((entries.length * usableW) / Math.max(usableD, 1)))));
  const rows = Math.ceil(entries.length / columns);

  entries.forEach((entry, index) => {
    const row = Math.floor(index / columns);
    // La dernière rangée, si elle est incomplète, occupe toute la largeur
    const inRow = row === rows - 1 ? entries.length - columns * (rows - 1) : columns;
    const cellW = usableW / inRow;
    const cell = { x: margin + (index % columns) * cellW, z: margin + row * (usableD / rows), width: cellW, depth: usableD / rows };
    const visual = resolveVisual(entry);
    const geometry = modelGeometry(visual, entry.visual_color ?? DEFAULT_TINT[visual]);
    const scale = scaleFor(visual, entry.name);
    const size = geometry.userData.size.clone().multiply(scale);
    const { placements, fit } = placeInCell(entry, visual, size, cell, inside.height, randomFrom(unitId * 7919 + entry.item_id * 104729));
    if (placements.length === 0) return;

    const mesh = new THREE.InstancedMesh(geometry, material(Boolean(entry.borrowed)), placements.length);
    if (entry.borrowed) mesh.renderOrder = 1; // transparent : dessiné après les objets présents
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
    mesh.userData.itemName = entry.name;
    mesh.userData.entry = entry;
    group.add(mesh);
  });
  return group;
}
