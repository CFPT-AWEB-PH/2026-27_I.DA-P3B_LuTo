<?php

declare(strict_types=1);

// Crée la salle « Atelier » : armoires à tiroirs, bacs, établis, rayonnage et objets, reconstitués d'après
// les photos de l'atelier (novembre 2026). Les étiquettes reprennent ce qui se lit sur les photos ;
// LES QUANTITÉS SONT DES ESTIMATIONS, à corriger à l'inventaire : chaque objet le signale dans sa description.
// Usage : php bin/seed-atelier.php         (refuse de tourner si la salle existe déjà)
// Tout passe par les règles habituelles d'emplacement et par le journal des mouvements de stock.

use App\Audit;
use App\Controller\StorageUnitController;
use App\Database;
use App\Space\SpaceRepository as Repo;
use App\Stock\ItemIndex;
use App\Stock\StockService;

require __DIR__ . '/../bootstrap.php';

const ROOM_NAME = 'Atelier';
const NOTE = 'Quantité estimée d\'après une photo de l\'atelier, à vérifier à l\'inventaire.';

$pdo = Database::connection();
$exists = $pdo->prepare('SELECT 1 FROM rooms WHERE name = ?');
$exists->execute([ROOM_NAME]);
if ($exists->fetchColumn() !== false) {
    fwrite(STDERR, 'La salle « ' . ROOM_NAME . " » existe déjà : rien n'a été modifié.\n");
    exit(1);
}
$adminId = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_protected = 1 AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
if ($adminId === false) {
    fwrite(STDERR, "Aucun administrateur actif : le journal des mouvements a besoin d'un auteur.\n");
    exit(1);
}
$adminId = (int) $adminId;

$types = [];
foreach ($pdo->query('SELECT * FROM storage_types') as $t) {
    $types[$t['name']] = $t;
}
foreach (['Armoire', 'Tiroir', 'Bac', 'Boîte plastique', 'Plateau à outils', 'Établi', 'Rayonnage', 'Étagère'] as $name) {
    if (!isset($types[$name])) {
        fwrite(STDERR, "Type de rangement manquant : {$name}\n");
        exit(1);
    }
}

// ===== Contenu : [étiquette, nom de l'objet, modèle 3D, quantité estimée, couleur facultative] =====

$screw = static fn (string $label, int $q): array => [$label, "Vis {$label}", 'screw', $q];
$value = static fn (string $v, string $kind, string $color, int $q): array => [$v, "Résistance {$v} {$kind}", 'resistor', $q, $color];
$carbon = static fn (array $values): array => array_map(static fn ($v) => $value($v, 'carbone', '#d9bd8c', 60), $values);
$metal = static fn (array $values): array => array_map(static fn ($v) => $value($v, 'couche métal', '#7fa8d8', 45), $values);
$elec = static fn (string $v, int $q): array => [$v, "Condensateur chimique {$v}", 'capacitor', $q];
$film = static fn (string $v, string $color, int $q): array => [$v, "Condensateur film {$v}", 'film_cap', $q, $color];

// Chaque tiroir : [nom, catégorie, colonnes, type des rangements, contenu]
$cabinets = [
    'Armoire 1 : visserie et composants' => [
        ['Visserie M2 à M5', 'Visserie', 7, 'Bac', [
            $screw('M5 x 6.5', 60), $screw('M5 x 14', 15), $screw('M5 x 24', 8), ['M5', 'Écrou M5', 'nut', 30], ['M4', 'Écrou M4', 'nut', 25], $screw('M4 x 6', 40),
            $screw('M3.5 x 7', 12), $screw('M3 x 3.5', 45), $screw('M3 x 4', 25), $screw('M3 x 6', 35), $screw('M3 x 8', 30), $screw('M3 x 10', 50), $screw('M3 x 12', 40),
            $screw('M3 x 16', 45), $screw('M3 x 20', 30), $screw('M3 x 23', 10), $screw('M3 x 30', 35), ['M3', 'Écrou M3', 'nut', 60],
            $screw('M2.5 x 6', 25), $screw('M2.5 x 8', 30), $screw('M2.5 x 12', 8), $screw('M2.5 x 16', 6), ['M2.5', 'Écrou M2.5', 'nut', 20], $screw('M2 x 10', 10), $screw('M2 x 23', 12),
            ['Rondelle', 'Rondelles M3 à M5', 'washer', 50],
        ]],
        ['Inserts et entretoises laiton', 'Visserie', 5, 'Bac', [
            ['Rondelles nylon', 'Rondelles nylon', 'washer', 70, '#efe9d8'], ['Écrous', 'Écrous hexagonaux M6', 'nut', 70], ['Écrous M4', 'Écrous hexagonaux M4', 'nut', 70],
            ['Inserts', 'Inserts laiton M3', 'insert', 70], ['Inserts M4', 'Inserts laiton M4', 'insert', 60], ['Vis BTR', 'Vis BTR M3 x 8', 'screw', 70], ['Vis tête', 'Vis tête fraisée M3', 'screw', 70],
            ['Colonnettes', 'Entretoises hexagonales laiton M3', 'hex_spacer', 30], ['Axes', 'Axes acier 3 mm', 'shaft', 25], ['Moyeux', 'Moyeux de fixation', 'coupler', 12],
        ]],
        ['Entretoises et petits modules', 'Composants', 6, 'Boîte plastique', [
            ['Entretoises 15', 'Entretoises plastique noires 15 mm', 'standoff', 70], ['Entretoises 25', 'Entretoises plastique noires 25 mm', 'standoff', 70], ['Entretoises 10', 'Entretoises plastique noires 10 mm', 'standoff', 70],
            ['Blanches', 'Entretoises plastique blanches', 'standoff', 40, '#efe9d8'], ['Capteurs', 'Modules capteurs noirs', 'sensor', 40], ['Encodeurs', 'Encodeurs rotatifs sur module', 'sensor', 30],
            ['Barrettes', 'Modules à barrette', 'sensor', 40], ['LCD', 'Afficheurs LCD 1602', 'lcd', 6], ['Borniers', 'Modules à bornier', 'terminal_block', 6], ['Prototypes', 'Montages d\'élèves', 'perfboard', 5],
        ]],
        ['Alimentation et modules', 'Composants', 5, 'Boîte plastique', [
            ['Support 3', 'Supports 3 accus 18650', 'battery_holder', 6], ['Support 4', 'Supports 4 accus 18650', 'battery_holder', 6], ['Fiches DC', 'Fiches DC mâles à bornier', 'dc_jack', 25], ['Fiches F', 'Fiches DC femelles à bornier', 'dc_jack', 25],
            ['LED', 'LED 5 mm', 'led', 60, '#7fd8c4'], ['Rouges', 'Cartes rouges à joystick', 'red_board', 12], ['Joysticks', 'Modules joystick', 'sensor', 15], ['Buzzers', 'Modules buzzer', 'sensor', 20],
            ['microSD', 'Modules lecteur microSD', 'sd_card', 8], ['Barrettes', 'Modules à barrettes', 'pin_header', 20],
        ]],
        ['Résistances carbone (kΩ)', 'Résistances', 6, 'Bac', $carbon(['10 kΩ', '12 kΩ', '15 kΩ', '18 kΩ', '22 kΩ', '27 kΩ', '33 kΩ', '39 kΩ', '47 kΩ', '56 kΩ', '68 kΩ', '82 kΩ', '100 kΩ', '120 kΩ', '150 kΩ', '180 kΩ', '220 kΩ', '270 kΩ', '330 kΩ', '390 kΩ', '470 kΩ', '560 kΩ', '680 kΩ', '820 kΩ', '1 MΩ', '2.2 MΩ', '3.3 MΩ'])],
        ['Résistances carbone (Ω)', 'Résistances', 6, 'Bac', $carbon(['22 Ω', '27 Ω', '33 Ω', '47 Ω', '56 Ω', '68 Ω', '82 Ω', '100 Ω', '120 Ω', '150 Ω', '180 Ω', '220 Ω', '270 Ω', '330 Ω', '390 Ω', '470 Ω', '560 Ω', '680 Ω', '820 Ω', '1 kΩ', '1.2 kΩ', '1.5 kΩ', '1.8 kΩ', '2.2 kΩ', '2.7 kΩ', '3.3 kΩ', '3.9 kΩ'])],
        ['Résistances couche métal', 'Résistances', 7, 'Bac', $metal(['12 Ω', '15 Ω', '27 Ω', '47 Ω', '120 Ω', '150 Ω', '160 Ω', '220 Ω', '330 Ω', '390 Ω', '510 Ω', '560 Ω', '680 Ω', '820 Ω', '1.2 kΩ', '1.5 kΩ', '1.6 kΩ', '1.8 kΩ', '2.2 kΩ', '2.4 kΩ', '2.7 kΩ', '3 kΩ', '3.3 kΩ', '3.6 kΩ', '4.3 kΩ', '5.6 kΩ', '6.8 kΩ', '8.2 kΩ', '10 kΩ', '15 kΩ', '22 kΩ', '33 kΩ', '47 kΩ', '56 kΩ', '91 kΩ', '100 kΩ', '180 kΩ', '240 kΩ', '270 kΩ', '330 kΩ', '390 kΩ', '470 kΩ', '560 kΩ', '2.7 MΩ', '5.6 MΩ', '6.2 MΩ', '10 MΩ'])],
    ],
    'Armoire 2 : composants et connectique' => [
        ['Condensateurs chimiques', 'Condensateurs', 6, 'Bac', [
            $elec('1 µF 50 V', 40), $elec('2.2 µF 50 V', 30), $elec('4.7 µF 50 V', 40), $elec('10 µF 35 V', 60), $elec('22 µF 25 V', 25), $elec('47 µF 25 V', 40), $elec('100 µF 25 V', 35), $elec('220 µF 16 V', 25),
            $elec('470 µF 25 V', 15), $elec('1000 µF 25 V', 12), $elec('2200 µF 35 V', 10), $elec('4700 µF 63 V', 3), $elec('10000 µF 40 V', 2), ['Axiaux', 'Condensateurs axiaux bleus', 'capacitor', 25, '#2a6fd0'],
        ]],
        ['Condensateurs film', 'Condensateurs', 7, 'Bac', [
            $film('1 nF', '#a8324a', 30), $film('2.2 nF', '#a8324a', 30), $film('4.7 nF', '#a8324a', 30), $film('10 nF', '#a8324a', 30), $film('22 nF', '#a8324a', 25), $film('47 nF', '#a8324a', 25), $film('100 nF', '#a8324a', 35),
            $film('220 nF', '#2a5fc4', 30), $film('470 nF', '#e8c33a', 25), $film('1 µF', '#e4e0d2', 25), $film('2.2 µF', '#e4e0d2', 20), $film('4.7 µF', '#e4e0d2', 15), $film('10 µF', '#e4e0d2', 8), ['Céramique', 'Condensateurs céramique', 'film_cap', 40, '#c9a24a'],
        ]],
        ['Dissipateurs et supports de circuits', 'Composants', 6, 'Bac', [
            ['Équerres', 'Équerres de fixation métal', 'bracket', 10, '#c9ccd2'], ['Dissipateur L', 'Dissipateurs grand format', 'heatsink', 2], ['Dissipateur M', 'Dissipateurs moyens', 'heatsink', 6], ['Dissipateur S', 'Dissipateurs TO-220', 'heatsink', 12],
            ['DIL 8', 'Supports de circuit DIL 8', 'ic_socket', 40], ['DIL 14', 'Supports de circuit DIL 14', 'ic_socket', 40], ['DIL 16', 'Supports de circuit DIL 16', 'ic_socket', 40], ['DIL 18', 'Supports de circuit DIL 18', 'ic_socket', 30], ['DIL 28', 'Supports de circuit DIL 28', 'ic_socket', 20], ['Boîtier 9 V', 'Boîtier à pile', 'battery_holder', 1], ['Cosses', 'Cosses à fil', 'crimp', 12, '#b9bcc2'],
        ]],
        ['Potentiomètres et ajustables', 'Composants', 6, 'Bac', [
            ['2.2 kΩ', 'Potentiomètre 2.2 kΩ', 'potentiometer', 8], ['10 kΩ', 'Potentiomètre 10 kΩ', 'potentiometer', 5], ['470 kΩ', 'Potentiomètre 470 kΩ', 'potentiometer', 18], ['Écrous', 'Écrous de potentiomètre', 'nut', 30, '#1d1e22'],
            ['1 kΩ', 'Ajustable 1 kΩ', 'trimmer', 20], ['5 kΩ', 'Ajustable 5 kΩ', 'trimmer', 15], ['50 kΩ', 'Ajustable 50 kΩ', 'trimmer', 15], ['100 kΩ', 'Ajustable 100 kΩ', 'trimmer', 25], ['500 kΩ', 'Ajustable 500 kΩ', 'trimmer', 30], ['1 MΩ', 'Ajustable 1 MΩ', 'trimmer', 12], ['Rondelles', 'Rondelles de potentiomètre', 'washer', 30],
        ]],
        ['Interrupteurs et relais', 'Composants', 6, 'Bac', [
            ['Commut. 1x12', 'Commutateurs rotatifs 1 x 12', 'rotary_switch', 15], ['Rotatifs', 'Commutateurs rotatifs', 'rotary_switch', 4], ['Sondes', 'Pointes de touche noires', 'probe', 25], ['Sondes rouges', 'Pointes de touche rouges', 'probe', 15],
            ['Relais 5 V', 'Relais 5 V', 'relay', 25], ['Relais 12 V', 'Relais 12 V', 'relay', 8], ['Relais 2A', 'Relais 2A 250 V', 'relay', 8, '#2a6fd0'], ['Touches', 'Touches de clavier', 'push_button', 15], ['Boutons', 'Boutons-poussoirs', 'push_button', 25],
            ['Levier 2A', 'Interrupteurs à levier 2A', 'toggle', 25], ['Levier', 'Interrupteurs à levier', 'toggle', 25], ['Bascule', 'Interrupteurs à bascule', 'rocker', 20], ['Unipolaire', 'Interrupteurs unipolaires', 'toggle', 12], ['Boutons bleus', 'Boutons de réglage bleus', 'knob', 4, '#2a5fc4'],
        ]],
        ['Fiches, RJ45 et bornes', 'Connectique', 6, 'Bac', [
            ['RJ45', 'Fiches RJ45', 'rj45', 40], ['Manchon rouge', 'Manchons RJ45 rouges', 'rj45_boot', 60, '#c4263c'], ['Manchon jaune', 'Manchons RJ45 jaunes', 'rj45_boot', 60, '#f2cf2e'], ['Manchon vert', 'Manchons RJ45 verts', 'rj45_boot', 50, '#2e9d6a'], ['Manchon bleu', 'Manchons RJ45 bleus', 'rj45_boot', 40, '#2a6fd0'],
            ['Banane noire', 'Fiches banane noires', 'banana', 30, '#1d1e22'], ['Banane bleue', 'Fiches banane bleues', 'banana', 25, '#2a6fd0'], ['Banane rouge', 'Fiches banane rouges', 'banana', 45, '#c4263c'], ['Banane jaune', 'Fiches banane jaunes', 'banana', 20, '#f2cf2e'],
            ['Crocodiles', 'Pinces crocodile', 'alligator', 12], ['Capuchons', 'Capuchons de pince crocodile', 'alligator', 30], ['Bornes', 'Bornes de laboratoire', 'binding_post', 15], ['Œillets', 'Passe-fils caoutchouc', 'grommet', 40],
        ]],
        ['Cosses, borniers et connecteurs', 'Connectique', 8, 'Bac', [
            ['Fourche bleue', 'Cosses fourche bleues', 'crimp', 50], ['Bout bleu', 'Cosses femelles bleues', 'crimp', 40], ['Rouges', 'Cosses femelles rouges', 'crimp', 40, '#c4263c'], ['Jaunes', 'Cosses jaunes', 'crimp', 35, '#f2cf2e'], ['Rouge fine', 'Cosses à sertir rouges', 'crimp', 25, '#c4263c'],
            ['IDC 40', 'Connecteurs de nappe 40 points', 'idc', 60], ['IDC 20', 'Connecteurs de nappe 20 points', 'idc', 45], ['IDC 10', 'Connecteurs de nappe 10 points', 'idc', 20], ['Pieds', 'Pieds caoutchouc adhésifs', 'rubber_foot', 3],
            ['Bornier gris', 'Borniers gris', 'terminal_block', 40, '#9a9da3'], ['Bornier orange', 'Borniers orange', 'terminal_block', 30], ['Rivets', 'Rivets plastique', 'rivet', 60], ['Porte-fusibles', 'Porte-fusibles', 'fuse_holder', 25], ['Boutons', 'Boutons de réglage gris', 'knob', 8], ['D-Sub', 'Connecteurs D-Sub', 'dsub', 3], ['Barrettes', 'Barrettes de broches', 'pin_header', 40],
        ]],
    ],
    'Armoire 3 : informatique, robotique et outils' => [
        ['Raspberry Pi 4 et Sense HAT', 'Raspberry Pi', 6, 'Bac', [
            ['Sense 1', 'Raspberry Pi 4 avec Sense HAT', 'sense_hat', 2], ['Pi 1', 'Raspberry Pi 4', 'raspberry_pi', 6], ['Pi 2', 'Raspberry Pi 4 (réserve)', 'raspberry_pi', 8], ['Nappes', 'Nappes 40 points', 'ribbon', 10], ['Sense 2', 'Sense HAT', 'sense_hat', 3], ['Divers', 'Accessoires Raspberry Pi', 'bag', 2],
        ]],
        ['Raspberry Pi en boîtier', 'Raspberry Pi', 6, 'Bac', [
            ['PIB2101', 'Raspberry Pi en boîtier PIB2101', 'pi_case', 1], ['PIB2102', 'Raspberry Pi en boîtier PIB2102', 'pi_case', 1], ['PIB2103', 'Raspberry Pi en boîtier PIB2103', 'pi_case', 1], ['PIB2104', 'Raspberry Pi en boîtier PIB2104', 'pi_case', 1], ['PIB2105', 'Raspberry Pi en boîtier PIB2105', 'pi_case', 1], ['PIB2106', 'Raspberry Pi en boîtier PIB2106', 'pi_case', 1],
            ['PIB2107', 'Raspberry Pi en boîtier PIB2107', 'pi_case', 1], ['PIB2108', 'Raspberry Pi en boîtier PIB2108', 'pi_case', 1], ['PIB2109', 'Raspberry Pi en boîtier PIB2109', 'pi_case', 1], ['PIB2110', 'Raspberry Pi en boîtier PIB2110', 'pi_case', 1], ['PIB2111', 'Raspberry Pi en boîtier PIB2111', 'pi_case', 1], ['Réseau', 'Câbles réseau', 'ethernet', 14],
            ['Cartes', 'Raspberry Pi sans boîtier', 'raspberry_pi', 4], ['microSD A', 'Cartes microSD groupe A', 'sd_card', 10], ['microSD B', 'Cartes microSD groupe B', 'sd_card', 10], ['microSD C', 'Cartes microSD groupe C', 'sd_card', 10], ['Extensions', 'Cartes d\'extension rouges', 'red_board', 3],
        ]],
        ['Fils de prototypage', 'Connectique', 5, 'Bac', [
            ['Bleu', 'Fils de prototypage bleus', 'jumper', 15, '#2a5fc4'], ['Vert', 'Fils de prototypage verts', 'jumper', 15, '#2e9d6a'], ['Blanc', 'Fils de prototypage blancs', 'jumper', 25, '#e4e0d2'], ['Jaune', 'Fils de prototypage jaunes', 'jumper', 20, '#f2cf2e'], ['Noir', 'Fils de prototypage noirs', 'jumper', 30, '#1d1e22'], ['Orange', 'Fils de prototypage orange', 'jumper', 15, '#e07a2c'],
            ['LED', 'LED couleurs assorties', 'led', 35], ['Barrettes M', 'Barrettes de broches mâles', 'pin_header', 15], ['Barrettes F', 'Barrettes de broches femelles', 'pin_header', 20], ['Plaques', 'Plaques à trous', 'perfboard', 3], ['Essai', 'Plaques d\'essai', 'breadboard', 2], ['Arc-en-ciel', 'Nappes de fils arc-en-ciel', 'ribbon', 2, '#d8c23a'], ['Adaptateurs', 'Adaptateurs GPIO', 'red_board', 6, '#1b3f8f'],
        ]],
        ['Modules Grove', 'Modules', 5, 'Bac', [
            ['Câbles', 'Câbles Grove', 'grove_cable', 25], ['Câbles 2', 'Câbles Grove longs', 'grove_cable', 25], ['Connecteurs', 'Connecteurs Grove', 'terminal_block', 40, '#f1efe7'], ['Émetteurs IR', 'Émetteurs infrarouges Grove', 'grove_module', 15], ['Relais', 'Relais Grove', 'grove_module', 12],
            ['Glissières', 'Potentiomètres à glissière Grove', 'grove_module', 8], ['LCD', 'Afficheurs LCD Grove', 'lcd', 6], ['Ultrasons', 'Capteurs à ultrasons Grove', 'ultrasonic', 18], ['Base', 'Cartes de base Grove', 'red_board', 5, '#1b3f8f'], ['Sons', 'Capteurs de son Grove', 'grove_module', 8], ['Servos', 'Servomoteurs', 'servo', 6], ['Élévateurs', 'Convertisseurs de tension', 'sensor', 12, '#1b3f8f'],
        ]],
        ['Robotique Makeblock', 'Robotique', 6, 'Bac', [
            ['Servos', 'Servomoteurs Makeblock', 'servo', 4], ['Poulies', 'Poulies crantées', 'pulley', 15], ['Pneus', 'Pneus', 'tyre', 12], ['Pinces', 'Pinces de robot', 'bracket', 8], ['Supports', 'Supports noirs', 'bracket', 40], ['Axes', 'Axes acier', 'shaft', 12],
            ['Équerres', 'Équerres bleues', 'bracket', 15, '#2f7fd6'], ['Profilés 24', 'Profilés perforés 24 cm', 'beam', 8], ['Profilés 16', 'Profilés perforés 16 cm', 'beam', 8], ['Profilés 8', 'Profilés perforés 8 cm', 'beam', 8], ['Plaques', 'Plaques perforées bleues', 'plate', 6], ['Tournevis', 'Tournevis Makeblock', 'screwdriver', 1, '#2a6fd0'],
        ]],
        ['Moteurs et robots mobiles', 'Robotique', 5, 'Bac', [
            ['Pas à pas', 'Moteurs pas à pas NEMA 17', 'stepper', 10], ['Réducteurs', 'Motoréducteurs 37 mm', 'motor', 14], ['Réducteurs 25', 'Motoréducteurs 25 mm', 'motor', 10], ['Roulettes', 'Roulettes', 'caster', 12], ['Moyeux', 'Moyeux de roue', 'coupler', 12],
            ['Roues', 'Roues de robot', 'robot_wheel', 16], ['Châssis', 'Pièces de châssis blanches', 'plate', 10, '#efeff0'], ['Suiveurs', 'Cartes suiveur de ligne', 'red_board', 6], ['Câbles', 'Câbles spiralés', 'wire', 6], ['Ultrasons', 'Capteurs à ultrasons', 'ultrasonic', 6, '#c9262f'],
        ]],
        ['Piles et accus', 'Alimentation', 6, 'Bac', [
            ['D', 'Piles D', 'battery', 15], ['C', 'Piles C', 'battery', 10], ['AA', 'Piles AA', 'battery', 18], ['AAA', 'Piles AAA', 'battery', 14], ['Accus', 'Accus Ni-MH AA', 'battery', 12, '#7fb83a'], ['18650', 'Accus 18650', 'battery', 8, '#3a7fc4'],
        ]],
    ],
];

// Outils : un tiroir de bacs et un tiroir de plateaux rainurés, dans la quatrième armoire
$toolDrawers = [
    ['Outils à main', 'Outils', 4, 'Bac', [
        ['Pinces plates', 'Pinces plates isolées', 'pliers', 2, '#c4263c'], ['Pinces', 'Pinces de précision', 'pliers', 3], ['Coupantes', 'Pinces coupantes', 'cutter', 3], ['Dénudage', 'Pinces à dénuder', 'cutter', 2],
        ['Coffret', 'Coffret d\'embouts', 'driver_set', 2], ['Précision', 'Jeu de tournevis de précision', 'precision_driver', 6], ['Allen', 'Clés Allen', 'hex_key', 6], ['Pointeau', 'Pointeaux', 'punch', 2],
    ]],
    ['Outillage long', 'Outils', 4, 'Plateau à outils', [
        ['Marteaux', 'Marteaux', 'hammer', 3], ['Trousses', 'Trousses à outils', 'pouch', 2], ['Cutter', 'Cutters', 'knife', 1], ['Pinceau', 'Pinceaux', 'brush', 1],
        ['Clés', 'Clés plates', 'wrench', 6], ['Réglet', 'Réglets 300 mm', 'ruler', 1], ['Brucelles', 'Brucelles', 'tweezers', 3], ['Pompe', 'Pompes à dessouder', 'desolder', 1],
        ['Tournevis', 'Tournevis rouges', 'screwdriver', 6, '#d8391e'], ['Plats', 'Tournevis plats', 'screwdriver', 3, '#26282e'], ['Bleus', 'Tournevis bleus', 'screwdriver', 4, '#2a9ad0'], ['Jaunes', 'Tournevis jaunes', 'screwdriver', 3, '#f2c12e'],
        ['Précision', 'Tournevis de précision bleus', 'precision_driver', 8], ['Philips', 'Tournevis de précision noirs', 'precision_driver', 4, '#26282e'], ['Testeur', 'Tournevis testeurs', 'screwdriver', 2, '#e8c33a'], ['Isolés', 'Tournevis isolés', 'screwdriver', 3, '#c4263c'],
    ]],
];

// Rayonnage : [étagère, [[nom de la boîte, objet, modèle, quantité, couleur]]]
$shelves = [
    ['Haut', [['Kits Grove', 'Kits Grove Starter', 'case', 4, '#8fc23a'], ['Kits SunFounder', 'Kits électronique SunFounder', 'case', 3, '#2f6fd6'], ['Ordinateurs', 'Pochettes ordinateur', 'pouch', 3]]],
    ['Milieu haut', [['Bobines', 'Fil de câblage en bobine', 'wire', 8], ['Réseau', 'Câbles réseau longs', 'ethernet', 6], ['Nappes', 'Nappes grises', 'ribbon', 6]]],
    ['Milieu bas', [['Robots', 'Boîtes de pièces de robot', 'case', 3, '#2f6fd6'], ['Plaques', 'Plaques d\'essai grand format', 'breadboard', 6], ['Mousse', 'Mousse antistatique', 'foam', 4, '#26282e']]],
    ['Bas', [['Sachets', 'Sachets de composants', 'bag', 12], ['Boîtes', 'Boîtes vides', 'case', 8, '#8fc23a'], ['Chargeurs', 'Blocs d\'alimentation', 'box', 6, '#26282e']]],
];

// ===== Construction =====

$bin = static fn (array $type, string $label, int $w, int $d, int $h, int $x, int $z, int $key): array => [
    'id' => $key, 'type_id' => (int) $type['id'], 'name' => $label, 'label' => $label, 'width_mm' => $w, 'depth_mm' => $d, 'height_mm' => $h,
    'pos_x_mm' => $x, 'pos_y_mm' => 0, 'pos_z_mm' => $z, 'panel_mm' => (int) min((int) $type['default_panel_mm'], intdiv(min($w, $d, $h), 4)), 'color' => $type['default_color'], 'children' => [],
];

$nextKey = 1;
$placements = []; // clé provisoire du bac => [objet, catégorie]

/** Tiroir aux dimensions de l'intérieur du meuble, rempli d'une grille de bacs. */
$drawerNode = static function (array $spec, int $width, int $depth, int $height, int $y) use ($types, $bin, &$nextKey, &$placements): array {
    [$name, $category, $columns, $typeName, $entries] = $spec;
    $drawerType = $types['Tiroir'];
    $panel = 12;
    $innerW = $width - 2 * $panel;
    $innerD = $depth - 2 * $panel;
    $binH = min($height - $panel, 60);
    $rows = (int) ceil(count($entries) / $columns);
    $rowD = intdiv($innerD, $rows);
    $children = [];
    foreach (array_values($entries) as $i => $entry) {
        $row = intdiv($i, $columns);
        // La dernière rangée, si elle est incomplète, s'étale sur toute la largeur
        $inRow = $row === $rows - 1 ? count($entries) - $columns * ($rows - 1) : $columns;
        $binW = intdiv($innerW, $inRow);
        $key = $nextKey++;
        $children[] = $bin($types[$typeName], $entry[0], $binW, $rowD, $binH, ($i % $columns) * $binW, $row * $rowD, $key);
        $placements[$key] = [$entry, $category];
    }
    return [
        'id' => $nextKey++, 'type_id' => (int) $drawerType['id'], 'name' => $name, 'label' => $name, 'width_mm' => $width, 'depth_mm' => $depth, 'height_mm' => $height,
        'pos_x_mm' => 0, 'pos_y_mm' => $y, 'pos_z_mm' => 0, 'panel_mm' => $panel, 'color' => $drawerType['default_color'], 'children' => $children,
    ];
};

/** Meuble à tiroirs, tiroirs empilés de haut en bas. */
$cabinetNode = static function (string $name, array $drawers, int $x, int $z, int $rotation = 0) use ($types, $drawerNode, &$nextKey): array {
    $w = 1000; $d = 750; $h = 1000; $panel = 19;
    $innerW = $w - 2 * $panel; $innerD = $d - $panel; $innerH = $h - 2 * $panel;
    $drawerH = intdiv($innerH, 7);
    $children = [];
    foreach (array_values($drawers) as $i => $spec) {
        $children[] = $drawerNode($spec, $innerW, $innerD, $drawerH, $innerH - ($i + 1) * $drawerH);
    }
    return [
        'id' => $nextKey++, 'type_id' => (int) $types['Armoire']['id'], 'name' => $name, 'width_mm' => $w, 'depth_mm' => $d, 'height_mm' => $h,
        'pos_x_mm' => $x, 'pos_y_mm' => 0, 'pos_z_mm' => $z, 'rotation_deg' => $rotation, 'panel_mm' => $panel, 'color' => $types['Armoire']['default_color'], 'children' => $children,
    ];
};

$result = Database::transaction(static function (PDO $pdo) use ($cabinets, $toolDrawers, $shelves, $types, $cabinetNode, $bin, &$nextKey, &$placements): array {
    $floorId = (int) $pdo->query('SELECT id FROM floors ORDER BY level_no = 0 DESC, level_no LIMIT 1')->fetchColumn();
    $outline = [[0, 0], [9000, 0], [9000, 7000], [0, 7000]];
    $pdo->prepare(
        "INSERT INTO rooms (name, label, description, display_unit, outline, height_mm, wall_thickness_mm, door_offset_mm, door_width_mm, door_height_mm,
             floor_color, floor_material, wall_color, floor_id, plan_x_mm, plan_z_mm)
         VALUES (?, 'ATELIER', ?, 'cm', ?, 2900, 150, 600, 1000, 2100, '#b8ab96', 'tiles', '#ede9df', ?, 0, 0)"
    )->execute([ROOM_NAME, 'Reconstituée d\'après les photos de l\'atelier. Quantités estimées, à vérifier à l\'inventaire.', json_encode($outline), $floorId]);
    $roomId = (int) $pdo->lastInsertId();
    $room = Repo::room($pdo, $roomId, true);

    $opening = $pdo->prepare('INSERT INTO room_openings (room_id, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $opening->execute([$roomId, 'window', 'Fenêtre 1', 1, 1000, 1800, 1400, 900]);
    $opening->execute([$roomId, 'window', 'Fenêtre 2', 1, 4000, 1800, 1400, 900]);
    $opening->execute([$roomId, 'door', 'Porte du couloir', 3, 2500, 1000, 2100, 0]);

    $ids = [];
    $names = array_keys($cabinets);
    // Armoires dos au mur de la porte principale, façades vers la salle
    $trees = [
        $cabinetNode($names[0], $cabinets[$names[0]], 2000, 0),
        $cabinetNode($names[1], $cabinets[$names[1]], 3050, 0),
        $cabinetNode($names[2], $cabinets[$names[2]], 4100, 0),
        $cabinetNode('Armoire 4 : outillage', $toolDrawers, 5150, 0),
    ];
    // Établis au centre
    $bench = $types['Établi'];
    foreach ([['Établi 1', 2500, 2800], ['Établi 2', 5000, 2800]] as [$name, $x, $z]) {
        $trees[] = ['id' => $nextKey++, 'type_id' => (int) $bench['id'], 'name' => $name, 'width_mm' => 2000, 'depth_mm' => 800, 'height_mm' => 900, 'pos_x_mm' => $x, 'pos_y_mm' => 0, 'pos_z_mm' => $z, 'panel_mm' => 40, 'color' => $bench['default_color'], 'children' => []];
    }
    // Rayonnage dos au mur du fond, retourné pour faire face à la salle
    $rack = $types['Rayonnage'];
    $shelfType = $types['Étagère'];
    $levels = [];
    $innerW = 2000 - 2 * 19; $innerD = 450 - 19; $innerH = 2000 - 2 * 19;
    $levelH = intdiv($innerH, count($shelves));
    foreach (array_values($shelves) as $i => [$shelfName, $boxes]) {
        $boxW = intdiv($innerW, count($boxes));
        $children = [];
        foreach (array_values($boxes) as $j => $box) {
            $key = $nextKey++;
            $node = $bin($types['Boîte plastique'], $box[0], $boxW, $innerD, 200, $j * $boxW, 0, $key);
            $node['label'] = $box[0];
            $children[] = $node;
            $placements[$key] = [$box, 'Rangement'];
        }
        $levels[] = ['id' => $nextKey++, 'type_id' => (int) $shelfType['id'], 'name' => "Étagère {$shelfName}", 'width_mm' => $innerW, 'depth_mm' => $innerD, 'height_mm' => $levelH, 'pos_x_mm' => 0, 'pos_y_mm' => $innerH - ($i + 1) * $levelH, 'pos_z_mm' => 0, 'panel_mm' => 19, 'color' => $shelfType['default_color'], 'children' => $children];
    }
    $trees[] = ['id' => $nextKey++, 'type_id' => (int) $rack['id'], 'name' => 'Rayonnage', 'width_mm' => 2000, 'depth_mm' => 450, 'height_mm' => 2000, 'pos_x_mm' => 6500, 'pos_y_mm' => 0, 'pos_z_mm' => 7000, 'rotation_deg' => 180, 'panel_mm' => 19, 'color' => $rack['default_color'], 'children' => $levels];

    $count = 0;
    foreach ($trees as $i => $tree) {
        StorageUnitController::insertNode($pdo, $room, $tree, null, $i, $ids);
        $count += StorageUnitController::countNodes($tree);
    }
    Repo::rebuildPaths($pdo, $roomId);
    return ['room_id' => $roomId, 'ids' => $ids, 'units' => $count];
});

// Objets et stock : chaque ajout passe par l'opération de stock habituelle, donc par le journal
$categoryIds = [];
$categoryOf = static function (string $name) use ($pdo, &$categoryIds): int {
    if (!isset($categoryIds[$name])) {
        $pdo->prepare('INSERT IGNORE INTO categories (name) VALUES (?)')->execute([$name]);
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $stmt->execute([$name]);
        $categoryIds[$name] = (int) $stmt->fetchColumn();
    }
    return $categoryIds[$name];
};

$insertItem = $pdo->prepare(
    "INSERT INTO items (reference, name, description, category_id, unit, is_consumable, visual, visual_color, search_text, created_by)
     VALUES (?, ?, ?, ?, 'pièce', ?, ?, ?, '', ?)"
);
$nextRef = 1;
$itemIds = [];
$consumable = ['Visserie', 'Résistances', 'Condensateurs', 'Composants', 'Connectique', 'Alimentation'];
foreach ($placements as $key => [$entry, $category]) {
    [, $name, $visual, $quantity] = $entry;
    $color = $entry[4] ?? null;
    $reference = sprintf('ATL-%04d', $nextRef++);
    $insertItem->execute([$reference, $name, NOTE, $categoryOf($category), in_array($category, $consumable, true) ? 1 : 0, $visual, $color, $adminId]);
    $itemId = (int) $pdo->lastInsertId();
    $itemIds[] = $itemId;
    StockService::add($itemId, $result['ids'][(string) $key], number_format($quantity, 3, '.', ''), $adminId, 'Inventaire initial estimé d\'après photo');
}
ItemIndex::reindex($pdo, $itemIds);

Audit::log($adminId, 'room.seeded', 'room', $result['room_id'], ['units' => $result['units'], 'items' => count($itemIds)]);
echo 'Salle « ' . ROOM_NAME . " » créée (n° {$result['room_id']}) : {$result['units']} rangements, " . count($itemIds) . " objets.\n";
