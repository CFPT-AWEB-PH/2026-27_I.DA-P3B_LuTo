<?php

declare(strict_types=1);

// Corrige l'atelier réaménagé le 2026-11-09 avec une profondeur erronée (4,50 m au lieu de 12 m) :
//   - salle portée à 8,10 m × 12 m (hauteur 3,20 m), porte de référence à 1,40 m du coin ;
//   - fenêtres sur tout le mur de gauche du plan ;
//   - grandes armoires A à F contre le mur du bas : déplacées seulement si elles sont encore au mur des 4,50 m
//     (si quelqu'un les a déjà recalées à la main, sa disposition est gardée) ;
//   - grandes armoires G à J (80 cm, vides) remplacées par des armoires de 1 m après les 3,23 m libres du mur de droite.
// Les armoires à tiroirs ne bougent pas ; aucun stock n'est touché.
// Une seule transaction, contrôlée par les règles habituelles : en cas de doute, rien n'est modifié.
//
// Usage : php bin/corriger-atelier-12m.php

use App\Audit;
use App\Controller\StorageUnitController;
use App\Database;
use App\Space\SpaceRepository as Repo;
use App\Space\SpaceValidator;
use App\Stock\ItemIndex;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/lib/atelier-plan.php';

$pdo = Database::connection();
$fail = static function (string $message): never {
    fwrite(STDERR, "Arrêt : {$message}\nRien n'a été modifié.\n");
    exit(1);
};

$stmt = $pdo->prepare("SELECT id FROM rooms WHERE name = 'Atelier'");
$stmt->execute();
$roomId = (int) ($stmt->fetchColumn() ?: $fail('salle « Atelier » introuvable.'));
$room = Repo::room($pdo, $roomId);
if (!in_array($room['outline'], [[[0, 0], [4500, 0], [4500, 8100], [0, 8100]], atelierOutline()], true)) {
    $fail('contour inattendu pour l\'atelier (ni 8,10 m × 4,50 m, ni 8,10 m × 12 m).');
}
$adminId = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_protected = 1 AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
$adminId = $adminId === false ? $fail('aucun administrateur actif.') : (int) $adminId;

$types = [];
foreach ($pdo->query('SELECT * FROM storage_types') as $t) {
    $types[$t['name']] = $t;
}

$top = [];
$children = [];
foreach (Repo::units($pdo, $roomId) as $u) {
    $children[(int) $u['parent_id']][] = (int) $u['id'];
    if ($u['parent_id'] === null) {
        $top[$u['name']] = (int) $u['id'];
    }
}
$descendants = static function (int $id) use (&$children, &$descendants): array {
    $all = [$id];
    foreach ($children[$id] ?? [] as $child) {
        array_push($all, ...$descendants($child));
    }
    return $all;
};

// Les armoires G à J sont remplacées : elles doivent être vides et sans emprunt en cours qui y revienne
$replace = [];
foreach (['G', 'H', 'I', 'J'] as $letter) {
    $id = $top["Grande armoire {$letter}"] ?? $fail("« Grande armoire {$letter} » introuvable.");
    if ((int) Repo::unit($pdo, $id)['width_mm'] === ATELIER_TALL_W) {
        $fail("« Grande armoire {$letter} » fait déjà 1 m de large : correction déjà faite ?");
    }
    $ids = $descendants($id);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stock = $pdo->prepare("SELECT COUNT(*) FROM stock_levels WHERE quantity > 0 AND unit_id IN ({$in})");
    $stock->execute($ids);
    $loans = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE status = 'open' AND origin_unit_id IN ({$in})");
    $loans->execute($ids);
    if ((int) $stock->fetchColumn() > 0 || (int) $loans->fetchColumn() > 0) {
        $fail("« Grande armoire {$letter} » contient du stock ou un emprunt en cours en vient : déplacez-le d'abord.");
    }
    $replace[$letter] = $id;
}

$places = [];
foreach (atelierTallPlaces() as [$letter, $x, $z, $rotation]) {
    $places[$letter] = [$x, $z, $rotation];
}

$total = Database::transaction(static function (PDO $pdo) use ($roomId, $top, $replace, $places, $types): int {
    foreach ($replace as $id) {
        $pdo->prepare('DELETE FROM storage_units WHERE id = ?')->execute([$id]); // contenu vide supprimé en cascade
    }
    $pdo->prepare(
        'UPDATE rooms SET outline = ?, height_mm = ?, door_offset_mm = ?, door_width_mm = 1000, door_height_mm = 2100, description = ? WHERE id = ?'
    )->execute([json_encode(atelierOutline()), ATELIER_HEIGHT, ATELIER_DOOR_OFFSET, ATELIER_DESCRIPTION, $roomId]);
    $pdo->prepare('DELETE FROM room_openings WHERE room_id = ?')->execute([$roomId]);
    $insert = $pdo->prepare('INSERT INTO room_openings (room_id, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (atelierOpenings() as $o) {
        $insert->execute([$roomId, $o['kind'], $o['name'], $o['wall_index'], $o['offset_mm'], $o['width_mm'], $o['height_mm'], $o['sill_mm']]);
    }
    // A à F : contre le nouveau mur du bas, sauf si elles y ont déjà été recalées à la main
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
        [$x, , $rotation] = $places[$letter];
        $pdo->prepare('UPDATE storage_units SET pos_x_mm = ? WHERE id = ? AND pos_x_mm = 4500 AND rotation_deg = ?')
            ->execute([$x, $top["Grande armoire {$letter}"] ?? throw new RuntimeException("Grande armoire {$letter} introuvable."), $rotation]);
    }

    $room = Repo::room($pdo, $roomId, true);
    SpaceValidator::room($room);
    SpaceValidator::openingsStillValid($room, Repo::openings($pdo, $roomId));
    foreach (Repo::units($pdo, $roomId) as $u) {
        if ($u['parent_id'] === null) {
            SpaceValidator::unit($pdo, $room, $u, Repo::type($pdo, (int) $u['type_id']), null);
        }
    }
    // G à J : armoires de 1 m, mêmes étagères et boîtes que les autres
    $key = 1;
    $slots = [];
    $ids = [];
    foreach (['G', 'H', 'I', 'J'] as $letter) {
        [$x, $z, $rotation] = $places[$letter];
        StorageUnitController::insertNode($pdo, $room, atelierTallCabinet($types, $letter, $x, $z, $rotation, $key, $slots), null, 0, $ids);
    }
    Repo::rebuildPaths($pdo, $roomId);
    $count = $pdo->prepare('SELECT COUNT(*) FROM storage_units WHERE room_id = ?');
    $count->execute([$roomId]);
    return (int) $count->fetchColumn();
});

$items = $pdo->prepare('SELECT DISTINCT s.item_id FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id WHERE u.room_id = ?');
$items->execute([$roomId]);
ItemIndex::reindex($pdo, array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN)));
Audit::log($adminId, 'room.rearranged', 'room', $roomId, ['correction' => '8,10 m × 12 m, fenêtres, armoires G à J de 1 m']);
echo "Atelier corrigé : 8,10 m × 12 m × 3,20 m, {$total} rangements.\n"
    . "  Porte de référence à 1,40 m du coin, 6 fenêtres sur le mur de gauche\n"
    . "  Grandes armoires A à F contre le mur du bas ; G à J (1 m) sur le mur de droite après 3,23 m libres\n";
