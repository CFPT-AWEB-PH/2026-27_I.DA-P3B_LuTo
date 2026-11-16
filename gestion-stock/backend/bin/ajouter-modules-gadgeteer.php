<?php

declare(strict_types=1);

// Met le catalogue de l'atelier en accord avec les photos des tiroirs de modules (12 novembre 2026) :
//   - corrige les objets que le premier inventaire avait décrits de loin (nom et modèle 3D), sans toucher à leur stock,
//     à leur emplacement ni à leur historique, et seulement s'ils portent encore le nom et le modèle d'origine
//     (un objet déjà renommé à la main est laissé tel quel) ;
//   - ajoute les pièces qui manquaient, SANS STOCK : leur quantité se compte ensuite (page « Comptage à la caméra »)
//     en choisissant le rangement où elles se trouvent.
//
// Usage : php bin/ajouter-modules-gadgeteer.php               montre ce qui serait fait, ne modifie rien
//         php bin/ajouter-modules-gadgeteer.php --appliquer   une seule transaction, tout ou rien
// Peut être relancé : ce qui est déjà fait est ignoré.

use App\Audit;
use App\Database;
use App\Stock\ItemIndex;

require __DIR__ . '/../bootstrap.php';

const NOTE = 'Identifié d\'après les photos des tiroirs du 12 novembre 2026. Quantité à compter.';
const CATEGORY = 'Modules Gadgeteer';

$apply = in_array('--appliquer', $argv, true);
$pdo = Database::connection();

$adminId = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_protected = 1 AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
if ($adminId === false) {
    fwrite(STDERR, "Aucun administrateur actif : le journal d'audit a besoin d'un auteur.\n");
    exit(1);
}
$adminId = (int) $adminId;

// ===== Corrections : [nom d'origine, modèle d'origine, nouveau nom, nouveau modèle] =====
// Uniquement ce que les photos rapprochées permettent d'affirmer.
$corrections = [
    ['Encodeurs rotatifs sur module', 'sensor', 'Modules LED7R (7 LED en cercle)', 'gadgeteer_led7'],
    ['Modules à barrette', 'sensor', 'Modules LED Strip (barre de 7 LED)', 'gadgeteer_led_strip'],
    ['Afficheurs LCD 1602', 'lcd', 'Modules Character Display (afficheur 16 x 2)', 'gadgeteer_display'],
    ['Montages d\'élèves', 'perfboard', 'Montages d\'élèves', 'student_board'],
    ['Supports 3 accus 18650', 'battery_holder', 'Supports 3 accus 18650', 'holder_18650'],
    ['Supports 4 accus 18650', 'battery_holder', 'Supports 4 accus 18650', 'holder_18650'],
    ['Fiches DC mâles à bornier', 'dc_jack', 'Adaptateurs jack mâle à bornier', 'dc_adapter_m'],
    ['Fiches DC femelles à bornier', 'dc_jack', 'Adaptateurs jack femelle à bornier', 'dc_adapter_f'],
    ['Cartes rouges à joystick', 'red_board', 'Modules USB Client DP 1.3 (alimentation USB et jack)', 'gadgeteer_usb_dp'],
    ['Modules capteurs noirs', 'sensor', 'Petits modules Gadgeteer à trier', 'gadgeteer_small'],
    // Même nom, modèle plus fidèle
    ['Modules joystick', 'sensor', 'Modules joystick', 'gadgeteer_joystick'],
    ['Modules buzzer', 'sensor', 'Modules buzzer', 'gadgeteer_tunes'],
    ['Modules lecteur microSD', 'sd_card', 'Modules lecteur microSD', 'gadgeteer_sd'],
];

// ===== Ajouts : [nom, modèle, catégorie, couleur facultative] =====
$additions = [
    // Tiroir de modules (bacs bleus)
    ['Modules Button 1.4', 'gadgeteer_button', CATEGORY],
    ['Modules Button S7 (7 boutons)', 'gadgeteer_button_s7', CATEGORY],
    ['Modules LightSense 1.3 (capteur de lumière)', 'gadgeteer_light', CATEGORY],
    ['Modules LED7R (7 LED en cercle)', 'gadgeteer_led7', CATEGORY],
    ['Modules LED Strip (barre de 7 LED)', 'gadgeteer_led_strip', CATEGORY],
    ['Modules USB Client DP 1.3 (alimentation USB et jack)', 'gadgeteer_usb_dp', CATEGORY],
    ['Modules USB Client EDP 1.1 (alimentation micro-USB et jack)', 'gadgeteer_usb_edp', CATEGORY],
    ['Modules USB Client SP (mini-USB)', 'gadgeteer_usb_sp', CATEGORY],
    ['Modules Extender 1.3', 'gadgeteer_extender', CATEGORY],
    ['Modules Breakout 1.0', 'gadgeteer_breakout', CATEGORY],
    ['Modules Breakout TB10 (bornier 10 points)', 'gadgeteer_tb10', CATEGORY],
    ['Modules Character Display (afficheur 16 x 2)', 'gadgeteer_display', CATEGORY],
    ['Modules SD Card', 'gadgeteer_sd', CATEGORY],
    ['Cartes SD 128 Mo', 'sd_full', 'Composants'],
    ['Plaques Holey Board (prototypage)', 'holey_board', CATEGORY],
    ['Vis sans tête M3 x 6 (DIN 913)', 'set_screw', 'Visserie'],
    ['Entretoises hexagonales métal M3', 'hex_spacer', 'Visserie', '#c9ccd2'],
    // Tiroir des cartes et capteurs (bacs rouges)
    ['Cartes FEZ Spider', 'fez_spider', CATEGORY],
    ['Cartes FEZ Cerbuino Bee', 'fez_cerbuino', CATEGORY],
    ['Modules processeur G120 TH', 'g120_th', CATEGORY],
    ['Modules Hub AP5', 'gadgeteer_hub', CATEGORY],
    ['Écrans Display T35 (tactile 3,5 pouces)', 'gadgeteer_t35', CATEGORY],
    ['Claviers Keypad KP16 (16 touches)', 'keypad', CATEGORY],
    ['Modules Joystick', 'gadgeteer_joystick', CATEGORY],
    ['Capuchons de joystick', 'joystick_cap', 'Composants'],
    ['Modules Ethernet J11D', 'gadgeteer_ethernet', CATEGORY],
    ['Modules Ethernet ENC28', 'gadgeteer_enc28', CATEGORY],
    ['Modules Motor Driver L298', 'gadgeteer_motor', CATEGORY],
    ['Modules Relay X1 (relais)', 'gadgeteer_relay', CATEGORY],
    ['Modules IO60P16 (60 entrées-sorties)', 'gadgeteer_io60', CATEGORY],
    ['Modules MaxO (32 sorties)', 'gadgeteer_maxo', CATEGORY],
    ['Modules Compass (Seeed, boussole)', 'seeed_module', CATEGORY],
    ['Modules Gyro 1.2 (Seeed, gyroscope)', 'seeed_module', CATEGORY],
    ['Modules Accelerometer 1.1 (Seeed)', 'seeed_module', CATEGORY],
    ['Modules Accel G248 (accéléromètre)', 'gadgeteer_small', CATEGORY],
    ['Modules Potentiometer', 'gadgeteer_pot', CATEGORY],
    ['Modules RS232', 'gadgeteer_rs232', CATEGORY],
    ['Capteurs de distance infrarouge', 'ir_sensor', 'Composants'],
];

$byName = $pdo->prepare('SELECT id, name, visual, reference FROM items WHERE name = ? ORDER BY id');
$find = static function (string $name) use ($byName): array {
    $byName->execute([$name]);
    return $byName->fetchAll();
};

// ----- Plan -----
$updates = []; // [id, référence, ancien nom, nouveau nom, nouveau modèle]
$skipped = [];
foreach ($corrections as [$oldName, $oldVisual, $newName, $newVisual]) {
    $rows = array_values(array_filter($find($oldName), static fn ($r) => $r['visual'] === $oldVisual && str_starts_with($r['reference'], 'ATL-')));
    if (count($rows) === 1) {
        $updates[] = [(int) $rows[0]['id'], $rows[0]['reference'], $oldName, $newName, $newVisual];
    } elseif (count($rows) > 1) {
        $skipped[] = "« {$oldName} » : " . count($rows) . ' objets portent ce nom, à corriger à la main';
    }
}
$renamedTo = array_column($updates, 3);
$creations = [];
foreach ($additions as $entry) {
    if ($find($entry[0]) === [] && !in_array($entry[0], $renamedTo, true)) {
        $creations[] = $entry;
    }
}

echo "Corrections (stock, emplacement et historique conservés) : " . count($updates) . "\n";
foreach ($updates as [, $reference, $oldName, $newName, $visual]) {
    echo "  {$reference}  « {$oldName} »" . ($oldName === $newName ? '' : " → « {$newName} »") . "  [modèle {$visual}]\n";
}
foreach ($skipped as $line) {
    echo "  ignoré : {$line}\n";
}
echo 'Ajouts au catalogue (sans stock) : ' . count($creations) . "\n";
foreach ($creations as $entry) {
    echo "  « {$entry[0]} »  [modèle {$entry[1]}, catégorie {$entry[2]}]\n";
}
if ($updates === [] && $creations === []) {
    echo "Rien à faire : le catalogue est déjà à jour.\n";
    exit(0);
}
if (!$apply) {
    echo "\nAucune modification n'a été faite. Pour appliquer : php bin/ajouter-modules-gadgeteer.php --appliquer\n";
    exit(0);
}

// ----- Application -----
$done = Database::transaction(static function (PDO $pdo) use ($updates, $creations, $adminId): array {
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

    $ids = [];
    $update = $pdo->prepare('UPDATE items SET name = ?, visual = ? WHERE id = ?');
    foreach ($updates as [$id, , $oldName, $newName, $visual]) {
        $update->execute([$newName, $visual, $id]);
        Audit::log($adminId, 'item.updated', 'item', $id, ['name' => [$oldName, $newName], 'visual' => $visual, 'source' => 'photos 2026-11-12']);
        $ids[] = $id;
    }

    // Références GAD-001, GAD-002… à la suite de celles qui existent
    $last = $pdo->query("SELECT MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) FROM items WHERE reference REGEXP '^GAD-[0-9]+$'")->fetchColumn();
    $next = (int) $last + 1;
    $insert = $pdo->prepare(
        "INSERT INTO items (reference, name, description, category_id, unit, is_consumable, visual, visual_color, search_text, created_by)
         VALUES (?, ?, ?, ?, 'pièce', 0, ?, ?, '', ?)"
    );
    foreach ($creations as $entry) {
        $reference = sprintf('GAD-%03d', $next++);
        $insert->execute([$reference, $entry[0], NOTE, $categoryOf($entry[2]), $entry[1], $entry[3] ?? null, $adminId]);
        $id = (int) $pdo->lastInsertId();
        Audit::log($adminId, 'item.created', 'item', $id, ['reference' => $reference, 'source' => 'photos 2026-11-12']);
        $ids[] = $id;
    }
    ItemIndex::reindex($pdo, $ids);
    return $ids;
});

echo "\nFait : " . count($updates) . ' objet(s) corrigé(s), ' . count($creations) . " ajouté(s).\n";
echo "Les objets ajoutés n'ont pas de stock : comptez-les à la caméra (menu « Comptage ») en indiquant leur rangement.\n";
