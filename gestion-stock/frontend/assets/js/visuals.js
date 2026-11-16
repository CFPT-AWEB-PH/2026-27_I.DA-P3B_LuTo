// Apparence 3D des objets : modèles disponibles et reconnaissance d'après le nom.
// Sans dépendance à Three.js : utilisé aussi par les formulaires.
// Liste tirée des photos de l'atelier ; à garder en phase avec ItemController::VISUALS (contrôlé par tests/frontend/visuals_test.mjs).

export const VISUAL_GROUPS = [
  ['Visserie et fixations', {
    screw: 'Vis, boulon', nut: 'Écrou', washer: 'Rondelle', standoff: 'Entretoise, tube', hex_spacer: 'Entretoise hexagonale',
    insert: 'Insert laiton', rivet: 'Rivet plastique', rubber_foot: 'Pied caoutchouc', grommet: 'Passe-fil, œillet', bracket: 'Équerre, support',
    shaft: 'Axe, tige', coupler: 'Moyeu, accouplement', caster: 'Roulette', set_screw: 'Vis sans tête',
  }],
  ['Composants', {
    resistor: 'Résistance', resistor_strip: 'Résistances en bande', capacitor: 'Condensateur chimique', film_cap: 'Condensateur film',
    trimmer: 'Potentiomètre ajustable', potentiometer: 'Potentiomètre', led: 'LED', chip: 'Circuit intégré', ic_socket: 'Support de circuit',
    pin_header: 'Barrette de broches', heatsink: 'Dissipateur', relay: 'Relais', toggle: 'Interrupteur à levier', rocker: 'Interrupteur à bascule',
    push_button: 'Bouton-poussoir', rotary_switch: 'Commutateur rotatif', knob: 'Bouton de réglage', fuse_holder: 'Porte-fusible', joystick_cap: 'Capuchon de joystick',
  }],
  ['Câbles et connectique', {
    wire: 'Câble, fil', jumper: 'Fil de prototypage (Dupont)', grove_cable: 'Câble Grove', ribbon: 'Nappe', ethernet: 'Câble réseau',
    rj45: 'Fiche RJ45', rj45_boot: 'Manchon RJ45', idc: 'Connecteur de nappe', terminal_block: 'Bornier', crimp: 'Cosse à sertir',
    banana: 'Fiche banane', alligator: 'Pince crocodile', probe: 'Pointe de touche', binding_post: 'Borne', dc_jack: 'Fiche d’alimentation', dsub: 'Connecteur D-Sub',
    dc_adapter_f: 'Adaptateur jack femelle à bornier', dc_adapter_m: 'Adaptateur jack mâle à bornier',
  }],
  ['Cartes et modules', {
    raspberry_pi: 'Raspberry Pi', pi_case: 'Raspberry Pi en boîtier', sense_hat: 'Carte à matrice de LED', sd_card: 'Carte microSD', sensor: 'Module capteur',
    grove_module: 'Module Grove', ultrasonic: 'Capteur à ultrasons', lcd: 'Afficheur LCD', breadboard: 'Plaque d’essai', perfboard: 'Plaque à trous', red_board: 'Carte d’extension',
    sd_full: 'Carte SD', holey_board: 'Plaque de prototypage noire', student_board: 'Montage sur plaque à bandes', keypad: 'Clavier 16 touches', ir_sensor: 'Capteur de distance infrarouge',
  }],
  ['Modules Gadgeteer', {
    fez_spider: 'Carte mère FEZ Spider', fez_cerbuino: 'Carte FEZ Cerbuino Bee', g120_th: 'Module processeur G120 TH',
    gadgeteer_button: 'Module bouton', gadgeteer_button_s7: 'Module 7 boutons (Button S7)', gadgeteer_joystick: 'Module joystick', gadgeteer_pot: 'Module potentiomètre',
    gadgeteer_light: 'Module capteur de lumière', gadgeteer_led7: 'Module 7 LED en cercle', gadgeteer_led_strip: 'Module barre de LED', gadgeteer_tunes: 'Module buzzer',
    gadgeteer_display: 'Afficheur à caractères', gadgeteer_t35: 'Écran tactile 3,5 pouces',
    gadgeteer_usb_dp: 'Alimentation USB et jack (USB Client DP)', gadgeteer_usb_edp: 'Alimentation micro-USB et jack (EDP)', gadgeteer_usb_sp: 'Module mini-USB (USB Client SP)',
    gadgeteer_extender: 'Module Extender', gadgeteer_breakout: 'Module Breakout', gadgeteer_tb10: 'Module Breakout à bornier (TB10)', gadgeteer_hub: 'Module Hub AP5',
    gadgeteer_ethernet: 'Module Ethernet', gadgeteer_enc28: 'Module Ethernet ENC28', gadgeteer_rs232: 'Module RS232', gadgeteer_sd: 'Module carte SD',
    gadgeteer_relay: 'Module relais', gadgeteer_motor: 'Module pilote de moteurs', gadgeteer_io60: 'Module IO60P16', gadgeteer_maxo: 'Module MaxO',
    seeed_module: 'Module Seeed (boussole, gyroscope…)', gadgeteer_small: 'Petit module capteur',
  }],
  ['Robotique', {
    motor: 'Motoréducteur', stepper: 'Moteur pas à pas', servo: 'Servomoteur', wheel: 'Roue, poulie', robot_wheel: 'Roue de robot', tyre: 'Pneu',
    pulley: 'Poulie crantée', beam: 'Profilé, barre', plate: 'Plaque perforée',
  }],
  ['Alimentation', { battery: 'Pile, accu', battery_9v: 'Pile 9 V', battery_holder: 'Support de piles', holder_18650: 'Support d’accus 18650' }],
  ['Outils', {
    screwdriver: 'Tournevis', precision_driver: 'Tournevis de précision', driver_set: 'Coffret d’embouts', pliers: 'Pince', cutter: 'Pince coupante', wrench: 'Clé plate',
    hex_key: 'Clé Allen', hammer: 'Marteau', tweezers: 'Brucelles', ruler: 'Réglet', knife: 'Cutter', brush: 'Pinceau', punch: 'Pointeau', pouch: 'Trousse à outils', desolder: 'Pompe à dessouder',
  }],
  ['Divers', { box: 'Pièce, boîtier', case: 'Boîte de rangement', bag: 'Sachet', foam: 'Mousse' }],
];

export const VISUALS = { auto: 'Automatique (d’après le nom)', ...Object.assign({}, ...VISUAL_GROUPS.map(([, models]) => models)) };

/** Objets assez grands pour être montrés dans un grand bac dans les aperçus. */
export const LARGE_VISUALS = new Set(['screwdriver', 'precision_driver', 'driver_set', 'pliers', 'cutter', 'wrench', 'hex_key', 'hammer', 'tweezers', 'ruler', 'knife', 'brush', 'punch', 'pouch', 'desolder',
  'beam', 'plate', 'wheel', 'robot_wheel', 'tyre', 'pulley', 'wire', 'ribbon', 'ethernet', 'jumper', 'grove_cable', 'resistor_strip', 'breadboard', 'perfboard', 'raspberry_pi', 'pi_case', 'sense_hat',
  'lcd', 'stepper', 'motor', 'case', 'bag', 'foam', 'battery_holder', 'heatsink', 'red_board',
  'gadgeteer_button_s7', 'gadgeteer_display', 'gadgeteer_t35', 'keypad', 'fez_spider', 'fez_cerbuino', 'g120_th', 'gadgeteer_io60', 'gadgeteer_maxo', 'gadgeteer_hub',
  'holey_board', 'holder_18650', 'student_board']);

// De la règle la plus précise à la plus générale : « câble réseau » avant « câble »
const RULES = [
  // Modules .NET Gadgeteer (GHI Electronics, Seeed) et pièces des mêmes tiroirs : avant « bouton », « relais », « ethernet », « module »…
  [/button s7|bouton s7|7 boutons/, 'gadgeteer_button_s7'],
  [/module.*bouton|bouton.*gadgeteer|\bbutton\b/, 'gadgeteer_button'],
  [/light.?sense|(module|capteur).*lumiere/, 'gadgeteer_light'],
  [/led.?7r|\bled7\b|7 led.*cercle/, 'gadgeteer_led7'],
  [/led.?strip|barre.*\bled\b|\bled\b.*barre/, 'gadgeteer_led_strip'],
  [/usb client edp|\bedp\b/, 'gadgeteer_usb_edp'],
  [/usb client dp/, 'gadgeteer_usb_dp'],
  [/usb client|usb.?serial|module.*usb/, 'gadgeteer_usb_sp'],
  [/\bextender\b/, 'gadgeteer_extender'],
  [/\btb.?10\b|breakout.*bornier/, 'gadgeteer_tb10'],
  [/\bbreakout\b/, 'gadgeteer_breakout'],
  [/character display|afficheur.*(gadgeteer|caracteres)/, 'gadgeteer_display'],
  [/display te?35|\bte?35\b|ecran tactile/, 'gadgeteer_t35'],
  [/keypad|clavier/, 'keypad'],
  [/fez spider|\bspider\b/, 'fez_spider'],
  [/cerbuino/, 'fez_cerbuino'],
  [/\bg120/, 'g120_th'],
  [/(capuchon|chapeau).*joystick|joystick.*(capuchon|chapeau)/, 'joystick_cap'],
  [/joystick/, 'gadgeteer_joystick'],
  [/\benc.?28/, 'gadgeteer_enc28'],
  [/j11d|module.*ethernet|ethernet.*module/, 'gadgeteer_ethernet'],
  [/l298|motor driver|pilote.*moteur/, 'gadgeteer_motor'],
  [/relay x1|module.*relais|relais.*(gadgeteer|module)/, 'gadgeteer_relay'],
  [/\bio.?60/, 'gadgeteer_io60'],
  [/\bmax.?o\b/, 'gadgeteer_maxo'],
  [/hub ap5|\bap5\b/, 'gadgeteer_hub'],
  [/seeed|compass|boussole|gyro|accelerometer/, 'seeed_module'],
  [/accel g248|\bg248\b|accelerometre/, 'gadgeteer_small'],
  [/module.*potentiometre|\bpotentiometer\b/, 'gadgeteer_pot'],
  [/\brs.?232\b/, 'gadgeteer_rs232'],
  [/module.*\bsd\b|sd card|lecteur.*\bsd\b/, 'gadgeteer_sd'],
  [/\btunes\b|module.*buzzer/, 'gadgeteer_tunes'],
  [/gadgeteer/, 'gadgeteer_small'],
  [/holey|plaque.*prototypage/, 'holey_board'],
  [/montages?\b.*(eleve|bande)|plaque a bandes|stripboard/, 'student_board'],
  [/support.*18650|18650.*support|porte.?accus?/, 'holder_18650'],
  [/(adaptateur|jack|fiche|prise).*femelle.*bornier|bornier.*(jack|dc).*femelle/, 'dc_adapter_f'],
  [/(adaptateur|jack|fiche|prise).*bornier|bornier.*(jack|dc)\b/, 'dc_adapter_m'],
  [/vis sans tete|din.?913|vis pointeau/, 'set_screw'],
  [/\bsharp\b|gp2y|(capteur|detecteur).*distance|distance.*infrarouge/, 'ir_sensor'],
  [/\bcartes? sd\b|\bsdhc\b|\bsdxc\b/, 'sd_full'],
  [/tournevis.*(precision|horloger)|(precision|horloger).*tournevis/, 'precision_driver'],
  [/coffret|jeu d.?embouts|embouts?/, 'driver_set'],
  [/tournevis/, 'screwdriver'],
  [/resistances?.*(bande|ruban)|(bande|ruban).*resistance/, 'resistor_strip'],
  [/potentiometres?.*ajust|ajustable|trimmer/, 'trimmer'],
  [/potentiometre|\bpot\b/, 'potentiometer'],
  [/resistance|\bohm|\d\s*[kKmM]?(ω|ohms?)\b/, 'resistor'],
  [/condensateur.*(film|polyester|mkt|mks)|\bmkt\b|\bwima\b|\bmks\b/, 'film_cap'],
  [/condensateur|capacite|\b\d+\s*[µu]f\b/, 'capacitor'],
  [/entretoises?.*(hex|laiton|m\d)|colonnette/, 'hex_spacer'],
  [/entretoise|\btube\b/, 'standoff'],
  [/\binserts?\b/, 'insert'],
  [/ecrou/, 'nut'],
  [/rondelle/, 'washer'],
  [/rivet/, 'rivet'],
  [/\bpieds?\b.*(caoutchouc|adhesif)|caoutchouc/, 'rubber_foot'],
  [/passe.?fil|oeillet|grommet/, 'grommet'],
  [/roulette|caster/, 'caster'],
  [/moyeu|accouplement|coupleur|\bhub\b/, 'coupler'],
  [/pinces? croco|crocodile/, 'alligator'],
  [/pinces? coupante|coupe.?fil|coupantes?/, 'cutter'],
  [/\bbrucelles?\b|\bprecelles?\b|\bpincettes?\b/, 'tweezers'],
  [/\bpinces?\b/, 'pliers'],
  [/\bcles? allen\b|\bcles? (a |males? )?six.?pans?\b|\bbtr\b|\bhex key\b/, 'hex_key'],
  [/\bcles?\b/, 'wrench'],
  [/marteau|maillet/, 'hammer'],
  [/\breglet\b|\bregle\b/, 'ruler'],
  [/\bcutter\b|couteau/, 'knife'],
  [/pinceau|brosse/, 'brush'],
  [/pointeau|\bpoincon\b/, 'punch'],
  [/trousse|pochette|etui/, 'pouch'],
  [/dessouder|dessoudage|\berrsa\b|\bersa\b/, 'desolder'],
  [/nappe/, 'ribbon'],
  [/(fil|cable).*(dupont|jumper|prototypage)|dupont|jumper/, 'jumper'],
  [/(cable|fil).*grove|grove.*(cable|fil)/, 'grove_cable'],
  [/(cable|cordon).*(reseau|ethernet|rj45)|ethernet|patch/, 'ethernet'],
  [/manchon|boot rj|capuchon rj/, 'rj45_boot'],
  [/\brj.?45\b/, 'rj45'],
  [/\bidc\b|connecteur.*(nappe|he10)|\bhe.?10\b/, 'idc'],
  [/bornier|borne a vis|terminal block/, 'terminal_block'],
  [/\bcosses?\b|sertir|faston/, 'crimp'],
  [/banane/, 'banana'],
  [/pointe de touche|sonde|\bprobe\b/, 'probe'],
  [/\bbornes?\b/, 'binding_post'],
  [/\bjack\b|fiche.*(alim|dc)|\bdc\b.*(fiche|prise)/, 'dc_jack'],
  [/d.?sub|centronics|\bdb.?\d+\b/, 'dsub'],
  [/raspberry.*boitier|boitier.*raspberry/, 'pi_case'],
  [/sense.?hat|matrice/, 'sense_hat'],
  [/raspberry|raspi|\brpi\b/, 'raspberry_pi'],
  [/\bsd\b|micro.?sd|carte memoire/, 'sd_card'],
  [/ultrason|hc.?sr04/, 'ultrasonic'],
  [/\blcd\b|afficheur|ecran/, 'lcd'],
  [/grove/, 'grove_module'],
  [/capteur|module|sensor|detecteur/, 'sensor'],
  [/breadboard|plaque d.?essai/, 'breadboard'],
  [/plaque a trous|perfboard|veroboard|circuit perfore/, 'perfboard'],
  [/carte d.?extension|shield|\bhat\b/, 'red_board'],
  [/pas.?a.?pas|stepper|nema/, 'stepper'],
  [/servo/, 'servo'],
  [/motoreducteur|moteur/, 'motor'],
  [/roues? de robot|roue.*pneu/, 'robot_wheel'],
  [/\bpneus?\b/, 'tyre'],
  [/poulie.*(crant|dent)|crantee/, 'pulley'],
  [/\broues?\b|poulie/, 'wheel'],
  [/plaque perforee|\bplaques?\b/, 'plate'],
  [/profile|barre|poutre/, 'beam'],
  [/equerre|\bsupport\b.*(moteur|fixation)|bracket/, 'bracket'],
  [/\baxes?\b|\btiges?\b/, 'shaft'],
  [/support.*(pile|accu|18650)|boitier.*pile/, 'battery_holder'],
  [/\b9\s*v\b/, 'battery_9v'],
  [/\bpiles?\b|\baccus?\b|batterie|\baaa?\b|18650|duracell/, 'battery'],
  [/\bled\b|\bdel\b/, 'led'],
  [/support.*(ci|circuit)|\bsupport\b|\bsocle/, 'ic_socket'],
  [/barrette|header|broches/, 'pin_header'],
  [/dissipateur|radiateur/, 'heatsink'],
  [/relais/, 'relay'],
  [/interrupteur.*(bascule)|bascule/, 'rocker'],
  [/interrupteur|inverseur/, 'toggle'],
  [/poussoir|\bbouton.*(tact|poussoir)/, 'push_button'],
  [/commutateur/, 'rotary_switch'],
  [/\bboutons?\b/, 'knob'],
  [/fusible/, 'fuse_holder'],
  [/cable|\bfils?\b|\bspirale\b/, 'wire'],
  [/circuit|\bic\b|microcontroleur|arduino|\bne555\b/, 'chip'],
  [/\bvis\b|\bboulons?\b|\bm\d+(?:[.,]\d+)?\s*[x×*]\s*\d/, 'screw'],
  [/boite|\bbac\b/, 'case'],
  [/sachet/, 'bag'],
  [/mousse/, 'foam'],
];

function normalize(text) {
  return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** Modèle à afficher : celui choisi sur la fiche, sinon déduit du nom puis de la catégorie. */
export function resolveVisual(entry) {
  if (entry.visual && entry.visual !== 'auto') return entry.visual;
  for (const source of [entry.name, entry.category]) {
    const text = normalize(source);
    for (const [pattern, visual] of RULES) if (pattern.test(text)) return visual;
  }
  return 'box';
}

/** « Vis M3 x 12 » → { diameter: 3, length: 12 } ; « Écrou M4 » → { diameter: 4 }. */
export function sizeFromName(name) {
  const text = String(name ?? '');
  const both = text.match(/\bM\s*(\d+(?:[.,]\d+)?)\s*[xX×*]\s*(\d+(?:[.,]\d+)?)/);
  if (both) return { diameter: Number(both[1].replace(',', '.')), length: Number(both[2].replace(',', '.')) };
  const one = text.match(/\bM\s*(\d+(?:[.,]\d+)?)\b/);
  return one ? { diameter: Number(one[1].replace(',', '.')) } : {};
}

// Dimensions réelles des piles : diamètre et longueur en mm
const BATTERY_SIZES = [[/18650/, 18, 65], [/\baaa\b|\blr03\b/, 10.5, 44.5], [/\baa\b|\blr6\b/, 14.5, 50.5], [/\bc\b|\blr14\b/, 26, 50], [/\bd\b|\blr20\b/, 34, 61]];

/** « Pile AA » → { diameter: 14.5, length: 50.5 } (AA par défaut). */
export function batterySize(name) {
  const text = normalize(name);
  for (const [pattern, diameter, length] of BATTERY_SIZES) if (pattern.test(text)) return { diameter, length };
  return { diameter: 14.5, length: 50.5 };
}
