// Contrôle de cohérence des modèles 3D : chaque modèle proposé dans les formulaires existe dans le moteur 3D,
// est accepté par le serveur, et les noms des pièces de l'atelier mènent au bon modèle.
// Usage : node tests/frontend/visuals_test.mjs

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
// Les fichiers du site sont des modules ES en .js, sans package.json : on les charge par leur contenu
const load = (path) => import('data:text/javascript;base64,' + readFileSync(join(root, path)).toString('base64'));
const { resolveVisual, VISUALS, LARGE_VISUALS } = await load('frontend/assets/js/visuals.js');
let failed = 0;
const check = (label, ok, detail = '') => {
  if (!ok) failed++;
  console.log(`${ok ? '  ok  ' : 'ÉCHEC '} ${label}${ok || !detail ? '' : ` : ${detail}`}`);
};

const names = Object.keys(VISUALS);

// Liste du serveur : ItemController::VISUALS
const php = readFileSync(join(root, 'backend/src/Controller/ItemController.php'), 'utf8');
const serverList = php.slice(php.indexOf('public const VISUALS = ['), php.indexOf('];', php.indexOf('public const VISUALS = [')));
const server = [...serverList.matchAll(/'([a-z0-9_]+)'/g)].map((m) => m[1]);
check('Mêmes modèles côté serveur et côté interface',
  names.every((n) => server.includes(n)) && server.every((n) => names.includes(n)),
  [...names.filter((n) => !server.includes(n)), ...server.filter((n) => !names.includes(n))].join(', '));

// La colonne items.visual est un VARCHAR(20)
check('Identifiants de 20 caractères au plus', names.every((n) => n.length <= 20), names.filter((n) => n.length > 20).join(', '));

// Modèles réellement dessinés : clés de MODELS dans item-models.js (lecture du texte, sans charger Three.js)
const models = readFileSync(join(root, 'frontend/assets/js/three/item-models.js'), 'utf8');
const drawn = new Set([...models.slice(models.indexOf('const MODELS = {')).matchAll(/^ {2}([a-z0-9_]+): \(\) =>/gm)].map((m) => m[1]));
check('Chaque modèle proposé est dessiné', names.filter((n) => n !== 'auto').every((n) => drawn.has(n)), names.filter((n) => n !== 'auto' && !drawn.has(n)).join(', '));
check('Aucun modèle dessiné sans libellé', [...drawn].every((n) => names.includes(n)), [...drawn].filter((n) => !names.includes(n)).join(', '));
check('Grands modèles connus', [...LARGE_VISUALS].every((n) => drawn.has(n)), [...LARGE_VISUALS].filter((n) => !drawn.has(n)).join(', '));

// Reconnaissance d'après le nom
const expected = {
  'Module Button 1.4': 'gadgeteer_button',
  'Module Button S7': 'gadgeteer_button_s7',
  'Module LightSense 1.3': 'gadgeteer_light',
  'Module LED7R': 'gadgeteer_led7',
  'Module LED Strip': 'gadgeteer_led_strip',
  'Module USB Client DP 1.3': 'gadgeteer_usb_dp',
  'Module USB Client EDP 1.1': 'gadgeteer_usb_edp',
  'Module USB Client SP': 'gadgeteer_usb_sp',
  'Module Extender 1.3': 'gadgeteer_extender',
  'Module Breakout 1.0': 'gadgeteer_breakout',
  'Module Breakout TB10': 'gadgeteer_tb10',
  'Module Character Display': 'gadgeteer_display',
  'Écran Display T35': 'gadgeteer_t35',
  'Clavier Keypad KP16': 'keypad',
  'Carte FEZ Spider II': 'fez_spider',
  'Carte FEZ Cerbuino Bee': 'fez_cerbuino',
  'Module G120 TH': 'g120_th',
  'Module Joystick': 'gadgeteer_joystick',
  'Capuchons de joystick': 'joystick_cap',
  'Module Ethernet J11D': 'gadgeteer_ethernet',
  'Module Ethernet ENC28': 'gadgeteer_enc28',
  'Module Motor Driver L298': 'gadgeteer_motor',
  'Module Relay X1': 'gadgeteer_relay',
  'Module IO60P16': 'gadgeteer_io60',
  'Module MaxO': 'gadgeteer_maxo',
  'Module Hub AP5': 'gadgeteer_hub',
  'Module Compass (Seeed)': 'seeed_module',
  'Module Gyro 1.2': 'seeed_module',
  'Module Accel G248': 'gadgeteer_small',
  'Module Potentiometer': 'gadgeteer_pot',
  'Module RS232': 'gadgeteer_rs232',
  'Module SD Card': 'gadgeteer_sd',
  'Module Tunes': 'gadgeteer_tunes',
  'Cartes SD 128 Mo': 'sd_full',
  'Holey Board': 'holey_board',
  'Supports 3 accus 18650': 'holder_18650',
  'Adaptateurs jack femelle à bornier': 'dc_adapter_f',
  'Adaptateurs jack mâle à bornier': 'dc_adapter_m',
  'Vis sans tête M3 x 6': 'set_screw',
  'Capteurs de distance infrarouge Sharp': 'ir_sensor',
  'Montages d’élèves': 'student_board',
  // Les règles d'avant ne doivent pas avoir bougé
  'Vis M3 x 12': 'screw',
  'Relais 5 V': 'relay',
  'Câbles réseau': 'ethernet',
  'Boutons-poussoirs': 'push_button',
  'LED 5 mm': 'led',
  'Cartes microSD groupe A': 'sd_card',
  'Potentiomètre 10 kΩ': 'potentiometer',
  'Afficheurs LCD 1602': 'lcd',
  'Fiches DC mâles à bornier': 'dc_adapter_m',
  'Raspberry Pi 4': 'raspberry_pi',
  'Bornier gris': 'terminal_block',
  'Supports de circuit DIL 16': 'ic_socket',
  'Entretoises plastique noires 15 mm': 'standoff',
};
for (const [name, visual] of Object.entries(expected)) {
  const got = resolveVisual({ name, visual: 'auto' });
  check(`« ${name} » → ${visual}`, got === visual, `obtenu ${got}`);
}

console.log(failed ? `\n${failed} échec(s).` : '\nTout est cohérent.');
process.exit(failed ? 1 : 0);
