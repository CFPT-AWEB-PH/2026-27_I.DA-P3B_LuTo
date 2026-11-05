// Objets affichés dans les rangements : modèles légers en millimètres, dessinés en instances
// (un seul appel de rendu par objet, quel que soit le nombre de pièces affichées).
// Le nombre de pièces visibles suit la quantité jusqu'à un plafond : c'est une représentation,
// la quantité exacte reste celle du panneau de propriétés.

import * as THREE from '/vendor/three/three.module.min.js';
import { batterySize, resolveVisual, sizeFromName } from '../visuals.js';

const S = 0.001;
const STEEL = '#c9ccd2';
const DARK = '#2b2d33';
const MAX_LOOSE = 70;
const MAX_NEAT = 36;
// Petites pièces en vrac ; les autres sont rangées côte à côte
const LOOSE = new Set(['screw', 'nut', 'washer', 'resistor', 'capacitor', 'chip', 'box',
  'standoff', 'hex_spacer', 'insert', 'rivet', 'grommet', 'bracket', 'coupler', 'film_cap', 'trimmer', 'led', 'ic_socket', 'relay', 'toggle', 'rocker', 'push_button', 'knob',
  'rj45', 'rj45_boot', 'idc', 'terminal_block', 'crimp', 'banana', 'alligator', 'binding_post', 'dc_jack', 'sd_card', 'sensor', 'grove_module', 'caster', 'potentiometer', 'fuse_holder', 'pin_header', 'dsub']);
const BRASS = '#c9a24a';
const PCB_GREEN = '#1f6b3f';
const PCB_BLUE = '#1b3f8f';
const PCB_BLACK = '#17181c';
const DEFAULT_TINT = {
  capacitor: '#27408b', chip: '#26282e', wire: '#30333a', screwdriver: '#e0632a', pliers: '#2f9aa8',
  wrench: STEEL, hammer: '#c8a06a', beam: '#2f7fd6', wheel: '#2f7fd6', box: '#8b909a', screw: STEEL, nut: STEEL, washer: STEEL, resistor: '#d9bd8c',
  standoff: '#1d1e22', hex_spacer: BRASS, insert: BRASS, rivet: '#efe9d8', rubber_foot: '#1d1e22', grommet: '#1d1e22', bracket: '#1d1e22', shaft: STEEL, coupler: STEEL, caster: '#e8e4da',
  resistor_strip: '#f2cf2e', film_cap: '#a8324a', trimmer: '#2a5fc4', potentiometer: '#1d1e22', led: '#e8c33a', ic_socket: '#1d1e22', pin_header: '#1d1e22', heatsink: '#1d1e22', relay: '#f1efe7',
  toggle: STEEL, rocker: '#1d1e22', push_button: '#1d1e22', rotary_switch: '#1d1e22', knob: '#dfe1e4', fuse_holder: '#1d1e22',
  jumper: '#5a3fc4', grove_cable: '#d8c23a', ribbon: '#a9abb0', ethernet: '#8d9095', rj45: '#e9eef0', rj45_boot: '#2a6fd0', idc: '#b4b6ba', terminal_block: '#e07a2c', crimp: '#2a4fb5',
  banana: '#c4263c', alligator: '#1d1e22', probe: '#1d1e22', binding_post: '#c4263c', dc_jack: '#1d1e22', dsub: STEEL,
  raspberry_pi: PCB_GREEN, pi_case: '#f4f4f2', sense_hat: PCB_GREEN, sd_card: '#17181c', sensor: PCB_BLACK, grove_module: PCB_BLUE, ultrasonic: PCB_BLUE, lcd: PCB_BLUE, breadboard: '#eeebdf', perfboard: '#c98a42', red_board: '#c9262f',
  motor: '#a9acb3', stepper: '#1d1e22', servo: '#1d1e22', robot_wheel: '#e0522a', tyre: '#17181c', pulley: '#2f7fd6', plate: '#2f7fd6',
  battery: '#17181c', battery_9v: '#17181c', battery_holder: '#1d1e22',
  precision_driver: '#5d6168', driver_set: '#17181c', cutter: '#2f9aa8', hex_key: '#26282e', tweezers: STEEL, ruler: '#d6d9dd', knife: '#2a5fc4', brush: '#c8a06a', punch: '#26282e', pouch: '#1d1e22', desolder: '#26282e',
  case: '#2f6fd6', bag: '#dfe6ea', foam: '#dcdcdc',
};

const alongX = (geometry) => geometry.rotateZ(Math.PI / 2);
const cylX = (radius, length, x = 0, y = 0, z = 0, segments = 8) => alongX(new THREE.CylinderGeometry(radius, radius, length, segments)).translate(x, y, z);
const box = (lx, ly, lz, x = 0, y = 0, z = 0) => new THREE.BoxGeometry(lx, ly, lz).translate(x, y, z);
const cyl = (radius, height, x = 0, y = 0, z = 0, segments = 8) => new THREE.CylinderGeometry(radius, radius, height, segments).translate(x, y + height / 2, z);
const coneX = (r1, r2, length, x = 0, y = 0, z = 0, segments = 8) => alongX(new THREE.CylinderGeometry(r1, r2, length, segments)).translate(x, y, z);
const ring = (radius, tube, y = 0, segments = 14, x = 0, z = 0) => new THREE.TorusGeometry(radius, tube, 4, segments).rotateX(Math.PI / 2).translate(x, y, z);
const bend = (points, radius = 0.6, segments = 16, sides = 4) => new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points.map(([x, y, z]) => new THREE.Vector3(x, y, z))), segments, radius, sides, false);
const coil = (radius, turns, tube, height = 1) => {
  const points = [];
  for (let i = 0; i <= turns * 12; i++) {
    const a = (i / 12) * Math.PI * 2;
    points.push(new THREE.Vector3(Math.cos(a) * radius * (1 + 0.05 * Math.sin(i)), (i / (turns * 12)) * height * turns + tube, Math.sin(a) * radius));
  }
  return new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points), turns * 12, tube, 4, false);
};

/** Circuit imprimé générique : plaque, puis composants posés dessus. */
const pcb = (lx, lz, color, thickness = 1.6) => [box(lx, thickness, lz, 0, thickness / 2, 0), color];

/** Fil souple avec une fiche à chaque bout, en léger arc. */
const flexWire = (length, color, tip, tipColor = '#1d1e22') => [
  [bend([[-length / 2, 1, 0], [-length / 4, 5, length / 10], [length / 4, 3, -length / 10], [length / 2, 1, 0]], 0.7, 14), color],
  [box(tip[0], tip[1], tip[2], -length / 2 - tip[0] / 2, 1, 0), tipColor],
  [box(tip[0], tip[1], tip[2], length / 2 + tip[0] / 2, 1, 0), tipColor],
];

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

  // === Visserie et fixations ===
  standoff: () => [[cylX(2.5, 15, 0, 0, 0, 8), null]],
  hex_spacer: () => [[cylX(2.8, 20, 0, 0, 0, 6), null], [cylX(1.2, 6, 13, 0, 0, 6), null]],
  insert: () => [[cylX(2.6, 6, 0, 0, 0, 8), null], [cylX(3, 1, -2.6, 0, 0, 8), null]],
  rivet: () => [[cyl(2, 14, 0, 0, 0, 8), null], [new THREE.CylinderGeometry(3.6, 2, 3, 8).translate(0, 15, 0), null], [cyl(3.2, 1, 0, 0, 0, 8), null]],
  rubber_foot: () => [[box(40, 1, 40, 0, 0.5, 0), '#e9e6dc'], ...[-12, 0, 12].flatMap((x) => [-12, 0, 12].map((z) => [cyl(4.5, 3, x, 1, z, 10), null]))],
  grommet: () => [[ring(5, 2, 2, 12), null]],
  bracket: () => [[box(24, 2.5, 18, 0, 1.25, 0), null], [box(2.5, 18, 18, -10.75, 10, 0), null], ...[-3, 6].map((x) => [cyl(1.6, 0.3, x, 2.5, 0, 6), '#50535a'])],
  shaft: () => [[cylX(2, 100, 0, 0, 0, 8), null]],
  coupler: () => [[cyl(6, 8, 0, 0, 0, 12), null], [cyl(12, 2, 0, 8, 0, 14), null], ...[-7.5, 7.5].map((x) => [cyl(1.5, 0.3, x, 10, 0, 6), '#50535a'])],
  caster: () => [[cylX(12, 10, 0, 12, 0, 14).rotateY(Math.PI / 2), null], [box(14, 4, 16, 0, 25, 0), STEEL], [cyl(2.5, 12, 0, 27, 0, 8), STEEL]],

  // === Composants ===
  resistor_strip: () => [
    ...[-52, 52].map((z) => [box(130, 0.6, 7, 0, 0.3, z), null]),
    ...Array.from({ length: 14 }, (_, i) => -52 + i * 8).flatMap((x) => [
      [cylX(0.3, 104, 0, 1, 0, 3).rotateY(Math.PI / 2).translate(x, 0, 0), STEEL],
      [cylX(1.4, 7, 0, 1, 0, 6).rotateY(Math.PI / 2).translate(x, 0, 0), '#b89a6a'],
    ]),
  ],
  film_cap: () => [[box(10, 8, 5, 0, 4, 0), null], ...[-3.5, 3.5].map((x) => [cyl(0.3, 3, x, -3, 0, 4), STEEL])],
  trimmer: () => [[box(7, 7, 4.5, 0, 3.5, 0), null], [cyl(1.1, 0.6, 0, 7, 0, 8), BRASS], ...[-2.5, 0, 2.5].map((x) => [cyl(0.3, 4, x, -4, 0, 4), STEEL])],
  potentiometer: () => [[cyl(8, 6, 0, 0, 0, 16), '#b9bcc2'], [cyl(4, 5, 0, 6, 0, 10), STEEL], [cyl(3, 20, 0, 11, 0, 10), null], ...[-4, 0, 4].map((x) => [box(1.5, 0.3, 6, x, 0.2, 10), BRASS])],
  led: () => [[cyl(2.5, 4, 0, 0, 0, 10), null], [new THREE.SphereGeometry(2.5, 10, 6).translate(0, 4, 0), null], [cyl(0.25, 22, 1, -22, 0, 4), STEEL], [cyl(0.25, 18, -1, -18, 0, 4), STEEL]],
  ic_socket: () => [...[-1, 1].map((side) => [box(18, 3, 2, 0, 1.5, side * 3.3), null]), ...[-1, 1].map((side) => [box(2, 3, 8, side * 8, 1.5, 0), null])],
  pin_header: () => [[box(50, 2.5, 2.5, 0, 1.25, 0), null], ...Array.from({ length: 20 }, (_, i) => -24 + i * 2.54).map((x) => [box(0.6, 8, 0.6, x, 3, 0), BRASS])],
  heatsink: () => [[box(40, 3, 30, 0, 1.5, 0), null], ...Array.from({ length: 8 }, (_, i) => -17.5 + i * 5).map((x) => [box(1.6, 20, 30, x, 13, 0), null])],
  relay: () => [[box(20, 15, 11, 0, 7.5, 0), null], [box(14, 0.1, 7, 0, 15.05, 0), '#17181c']],
  toggle: () => [[box(13, 10, 8, 0, 5, 0), '#2a2b30'], [cyl(3, 4, 0, 10, 0, 10), null], [new THREE.CylinderGeometry(1.8, 1, 10, 8).rotateZ(0.3).translate(-1.5, 18, 0), null]],
  rocker: () => [[box(22, 12, 15, 0, 6, 0), null], [box(24, 1.5, 17, 0, 12.7, 0), null], [box(18, 2, 6, 0, 14, -3).rotateX(0.2), '#2a2b30']],
  push_button: () => [[box(12, 3.5, 12, 0, 1.75, 0), null], [cyl(3.5, 3, 0, 3.5, 0, 10), '#3a3b42']],
  rotary_switch: () => [[cyl(13, 14, 0, 0, 0, 14), '#b9bcc2'], [cyl(4, 5, 0, 14, 0, 10), STEEL], [cyl(3, 28, 0, 19, 0, 10), null]],
  knob: () => [[new THREE.CylinderGeometry(9, 11, 14, 14).translate(0, 7, 0), null], [cyl(7, 0.8, 0, 14, 0, 14), STEEL]],
  fuse_holder: () => [[cylX(6.5, 28, 0, 6.5, 0, 10), null], [cylX(8.5, 4, 12, 6.5, 0, 10), '#2a2b30'], [cylX(5, 8, 16, 6.5, 0, 10), '#3a3b42'], [box(8, 0.5, 5, -18, 3, 0), BRASS]],

  // === Câbles et connectique ===
  jumper: () => flexWire(100, null, [14, 2.5, 2.5]),
  grove_cable: () => [
    ...[-1.2, 0, 1.2].map((z) => [bend([[-50, 1, z], [-20, 5, z * 2 + 8], [20, 3, z * 2 - 8], [50, 1, z]], 0.5, 14), null]),
    [box(10, 4, 7, -55, 1.5, 0), '#f1efe7'], [box(10, 4, 7, 55, 1.5, 0), '#f1efe7'],
  ],
  ribbon: () => [[box(120, 0.8, 50, 0, 0.4, 0), null], [box(120, 0.05, 2.5, 0, 0.85, -23), '#b0232c'], [box(8, 7, 54, -60, 3.5, 0), '#26282e'], [box(8, 7, 54, 60, 3.5, 0), '#26282e']],
  ethernet: () => [[coil(60, 3, 2.4, 3), null], [box(16, 8, 12, 68, 12, 0), '#d8dde0']],
  rj45: () => [[box(20, 8, 12, 0, 4, 0), null], ...[-3, 0, 3].map((z) => [box(14, 0.3, 1, 0, 8.2, z), BRASS]), [box(10, 1, 4, 3, 8.7, 0), null]],
  rj45_boot: () => [[box(14, 11, 14, 0, 5.5, 0), null], [new THREE.CylinderGeometry(3.5, 6, 14, 8).rotateZ(Math.PI / 2).translate(14, 5.5, 0), null]],
  idc: () => [[box(44, 7, 8, 0, 3.5, 0), null], [box(40, 3, 3, 0, 8.5, 0), '#8b8e94']],
  terminal_block: () => [...[-7.5, -2.5, 2.5, 7.5].map((x) => [box(4.6, 11, 9, x, 5.5, 0), null]), ...[-7.5, -2.5, 2.5, 7.5].map((x) => [cyl(1.5, 0.5, x, 11, 0, 6), STEEL])],
  crimp: () => [[cylX(2.5, 11, 0, 2.5, 0, 8), null], [box(10, 0.6, 7, 11, 1, 0), STEEL], [cylX(1.6, 4, -7, 2.5, 0, 8), STEEL]],
  banana: () => [[cylX(3.5, 30, 0, 3.5, 0, 10), null], [cylX(2, 18, 24, 3.5, 0, 8), STEEL], [coneX(3, 4.5, 8, -19, 3.5, 0, 10), null]],
  alligator: () => [[box(38, 7, 9, 0, 3.5, 0), null], [box(16, 2, 5, 26, 2, 0), STEEL], [box(16, 2, 5, 26, 6, 0), STEEL], [cylX(1.5, 12, -24, 3.5, 0, 6), null]],
  probe: () => [[cylX(3.5, 60, 0, 3.5, 0, 8), null], [coneX(0.5, 3.5, 12, 36, 3.5, 0, 8), null], [cylX(0.6, 16, 50, 3.5, 0, 5), STEEL], [cylX(4.5, 3, -5, 3.5, 0, 8), null]],
  binding_post: () => [[cyl(6.5, 10, 0, 0, 0, 12), null], [cyl(4, 4, 0, 10, 0, 10), BRASS], [cyl(5, 7, 0, 14, 0, 12), null], [cyl(2, 10, 0, -9, 0, 6), STEEL]],
  dc_jack: () => [[cylX(5, 20, 0, 5, 0, 10), null], [cylX(2.6, 10, 15, 5, 0, 8), STEEL], [box(14, 10, 12, -16, 5, 0), '#2e9d6a'], ...[-3, 3].map((z) => [cyl(1, 0.6, -16, 10, z, 6), STEEL])],
  dsub: () => [[box(45, 8, 14, 0, 4, 0), null], [box(30, 6, 10, 0, 5, 0), '#2a2b30'], ...[-19, 19].map((x) => [cyl(2, 1, x, 8, 0, 6), '#b9bcc2'])],

  // === Cartes et modules ===
  raspberry_pi: () => [
    pcb(85, 56, null), [box(14, 1.5, 14, -10, 2.3, 2), '#26282e'], [box(50, 8.5, 5, 0, 5.8, -23.5), '#26282e'],
    [box(21, 16, 16, 32, 9.6, 18), STEEL], [box(17, 14, 13, 33, 8.6, -2), STEEL], [box(17, 14, 13, 33, 8.6, -19), STEEL],
    [box(8, 3, 8, -22, 3, 25), STEEL], [box(7, 3, 8, -6, 3, 25), STEEL], [box(7, 3, 8, 8, 3, 25), STEEL],
    [box(9, 6, 14, -38, 4, 0), '#f0f0ee'],
  ],
  pi_case: () => [[box(90, 25, 62, 0, 12.5, 0), null], [box(84, 1, 56, 0, 25.2, 0), '#e8e8e4'], [cyl(9, 0.4, 0, 25.6, 0, 16), '#c4263c'], [box(44, 0.3, 16, 0, 25.8, 18), '#f2cf2e']],
  sense_hat: () => [
    pcb(65, 56, null), [box(50, 8, 5, 0, -4, -22), '#26282e'],
    [box(34, 4, 34, -4, 3.6, 6), '#f1efe7'],
    ...Array.from({ length: 8 }, (_, x) => Array.from({ length: 8 }, (_, z) => [box(2.8, 0.8, 2.8, -15 + x * 4, 6, -5 + z * 3.2), '#ffffff'])).flat(),
  ],
  sd_card: () => [[box(15, 1, 11, 0, 0.5, 0), null], [box(5, 0.1, 9, -4, 1.05, 0), BRASS], [box(5, 0.1, 9, 3, 1.05, 0), '#f1efe7']],
  sensor: () => [pcb(32, 20, null), [box(10, 4, 8, -6, 3.6, 0), '#2a2b30'], [cyl(3, 3, 8, 1.6, 0, 10), '#d8d9dc'], [box(10, 2.5, 2.5, 0, 2.85, 8), '#26282e']],
  grove_module: () => [pcb(40, 20, null), [box(6, 6, 8, -14, 4.6, 0), '#f1efe7'], [box(9, 3, 9, 4, 3.1, 0), '#26282e'], [cyl(1.5, 1, 14, 1.6, 6, 8), '#e8c33a']],
  ultrasonic: () => [pcb(45, 20, null), ...[-13, 13].flatMap((x) => [[cyl(8, 12, x, 1.6, 0, 16), STEEL], [cyl(6, 0.3, x, 13.6, 0, 16), '#26282e']]), [box(10, 2.5, 3, 0, 2.85, -8), '#26282e']],
  lcd: () => [pcb(80, 36, null), [box(71, 7, 24, 0, 5, 0), '#17181c'], [box(62, 0.3, 16, 0, 8.6, 0), '#8fc23a'], [box(40, 2.5, 2.5, -14, -1.2, -16), '#26282e']],
  breadboard: () => [
    [box(165, 9, 55, 0, 4.5, 0), null], [box(160, 0.6, 3, 0, 9, 0), '#cfcabb'],
    ...[-24, 24].map((z) => [box(150, 0.2, 1, 0, 9.05, z), '#b0232c']),
    ...[-14, -7, 7, 14].map((z) => [box(152, 0.1, 2.5, 0, 9.05, z), '#3c3e44']),
  ],
  perfboard: () => [[box(70, 1.6, 50, 0, 0.8, 0), null], ...Array.from({ length: 6 }, (_, i) => [box(64, 0.1, 0.8, 0, 1.65, -20 + i * 8), BRASS])],
  red_board: () => [pcb(70, 50, null), [box(16, 2, 16, 0, 2.6, 0), '#26282e'], ...[-30, 30].map((x) => [box(4, 8, 40, x, 5.6, 0), '#f1efe7']), [box(30, 3, 3, 0, 3, -20), '#26282e']],

  // === Robotique ===
  motor: () => [
    [cylX(12.5, 30, -8, 12.5, 0, 16), null], [cylX(12.5, 22, 18, 12.5, 0, 16), '#c9ccd2'],
    [cylX(2, 14, 36, 12.5, 0, 8), STEEL], [box(2, 6, 8, -24, 12.5, 0), BRASS], [box(0.2, 8, 28, 2, 12.5, 12.6), '#c4263c'],
  ],
  stepper: () => [
    [box(42, 42, 40, 0, 21, 0), null], [box(42, 42, 6, 0, 21, 22), STEEL], [box(42, 42, 6, 0, 21, -22), STEEL],
    [cyl(11, 2, 0, 42, 0, 14), STEEL], [cyl(2.5, 22, 0, 44, 0, 8), STEEL],
    ...['#c4263c', '#2a5fc4', '#2e9d6a', '#17181c'].map((color, i) => [bend([[-21, 8, -6 + i * 4], [-40, 3, -10 + i * 7], [-60, 2, -6 + i * 5]], 0.8, 8), color]),
  ],
  servo: () => [
    [box(40, 36, 20, 0, 18, 0), null], [box(54, 2.5, 20, 0, 27, 0), null], [cyl(5.5, 4, -10, 36, 0, 12), '#2a2b30'],
    [box(32, 2, 6, -10, 41, 0), '#f1efe7'], [bend([[20, 8, 0], [34, 5, 3], [52, 2, 0]], 0.8, 8), '#e8912c'], [box(10, 3, 8, 56, 2, 0), '#17181c'],
  ],
  robot_wheel: () => [[cyl(33, 26, 0, 0, 0, 22), '#17181c'], [cyl(22, 27, 0, 0, 0, 18), null], [cyl(5, 28, 0, 0, 0, 10), '#17181c'], ...[0, 1, 2, 3, 4].map((i) => [box(38, 1.5, 4, 0, 27, 0).rotateY((i * Math.PI * 2) / 5), '#b23a1c'])],
  tyre: () => [[new THREE.TorusGeometry(26, 9, 8, 22).rotateX(Math.PI / 2).translate(0, 9, 0), null]],
  pulley: () => [[cyl(24, 8, 0, 0, 0, 24), null], [cyl(7, 9, 0, 0, 0, 10), '#123a66'], ...[0, 1, 2, 3].map((i) => [cyl(4, 0.2, 0, 8.7, 13, 8).rotateY((i * Math.PI) / 2), '#123a66'])],
  plate: () => [[box(120, 2.5, 48, 0, 1.25, 0), null], ...[-48, -24, 0, 24, 48].flatMap((x) => [-12, 12].map((z) => [cyl(2.2, 0.3, x, 2.5, z, 6), '#123a66']))],

  // === Alimentation ===
  // Longueur et diamètre réels d'après le nom (AA, AAA, C, D, 18650)
  battery: () => [[cylX(7.25, 40, -5, 7.25, 0, 14), null], [cylX(7.25, 9, 19.75, 7.25, 0, 14), '#c47a2c'], [cylX(2.6, 1.5, 25, 7.25, 0, 8), STEEL]],
  battery_9v: () => [[box(26, 17, 48, 0, 8.5, 0), null], [box(26, 17, 8, 0, 8.5, 20), '#c47a2c'], [cyl(3.5, 3, -6, 17, 20, 10), STEEL], [cyl(3.5, 3, 6, 17, 20, 8), BRASS]],
  battery_holder: () => [[box(78, 3, 42, 0, 1.5, 0), null], ...[-20, 20].map((z) => [box(78, 19, 2, 0, 11, z), null]), [box(2, 19, 42, -38, 11, 0), null], [box(2, 19, 42, 38, 11, 0), null], ...[-10, 10].map((z) => [cylX(9, 66, 0, 12, z, 12), '#2a2b30'])],

  // === Outils ===
  precision_driver: () => [[cylX(5, 75, -30, 5, 0, 10), null], [cylX(5.2, 8, -71, 5, 0, 12), '#2a6fd0'], [cylX(1.5, 75, 42, 5, 0, 6), STEEL]],
  driver_set: () => [[box(130, 22, 75, 0, 11, 0), null], [box(126, 0.5, 71, 0, 22.2, 0), '#8fc23a'], ...Array.from({ length: 8 }, (_, i) => [cylX(3, 100, 0, 23, -28 + i * 8, 6), '#3a3b42'])],
  cutter: () => [
    [box(26, 6, 15, 42, 3, 0), STEEL],
    [bend([[28, 3, 4], [0, 4, 10], [-50, 4, 22], [-78, 4, 26]], 5, 10, 6), null],
    [bend([[28, 3, -4], [0, 4, -10], [-50, 4, -22], [-78, 4, -26]], 5, 10, 6), null],
    [cyl(4, 7, 22, 0, 0, 8), '#7c8086'],
  ],
  hex_key: () => [[bend([[-50, 2, 0], [20, 2, 0], [27, 2, 3], [30, 2, 10], [30, 2, 30]], 2, 14, 6), null]],
  tweezers: () => [
    [bend([[-60, 2, 0], [-30, 2, 4], [20, 2, 3], [60, 1, 0.3]], 1, 10, 4), null],
    [bend([[-60, 2, 0], [-30, 2, -4], [20, 2, -3], [60, 1, -0.3]], 1, 10, 4), null],
  ],
  ruler: () => [[box(300, 1, 25, 0, 0.5, 0), null], ...Array.from({ length: 30 }, (_, i) => [box(0.8, 0.1, i % 5 === 0 ? 9 : 5, -145 + i * 10, 1.05, -10), '#26282e'])],
  knife: () => [[box(120, 13, 18, 0, 6.5, 0), null], [box(30, 1, 13, 74, 8, 0), STEEL], [box(14, 2.5, 8, 16, 13.5, 0), '#2a2b30']],
  brush: () => [[cylX(7, 130, -35, 7, 0, 8), null], [box(22, 12, 30, 40, 7, 0), STEEL], [box(40, 8, 30, 70, 7, 0), '#26282e']],
  punch: () => [[cylX(5, 80, -25, 5, 0, 8), null], [coneX(1, 5, 45, 37, 5, 0, 8), null], [cylX(6, 12, -40, 5, 0, 8), '#c4263c']],
  pouch: () => [[box(220, 22, 110, 0, 11, 0), null], [box(206, 1, 96, 0, 22.5, 0), '#2a2b30'], [box(70, 0.4, 18, -30, 23, 0), '#c4263c']],
  desolder: () => [[cylX(11, 130, -20, 11, 0, 12), null], [coneX(3, 9, 24, 57, 11, 0, 10), '#e9e4d0'], [cylX(3, 22, -95, 11, 0, 8), '#2a6fd0'], [cylX(5, 8, -110, 11, 0, 10), STEEL]],

  // === Divers ===
  case: () => [[box(120, 26, 80, 0, 13, 0), null], [box(124, 3, 84, 0, 27, 0), null], [box(50, 0.3, 26, 0, 28.6, 0), '#6fb8f2'], ...[-20, 20].map((x) => [box(12, 6, 4, x, 21, 41), null])],
  bag: () => [[box(60, 3, 80, 0, 1.5, 0), null], [box(60, 0.4, 3, 0, 3.2, -36), '#7ec2f0'], [box(34, 0.4, 24, 0, 3.2, 10), '#f7f5ee']],
  foam: () => [[box(90, 22, 60, 0, 11, 0), null]],
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
    mesh.userData.itemName = entry.name;
    group.add(mesh);
  });
  return group;
}
