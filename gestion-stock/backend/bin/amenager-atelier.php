<?php

declare(strict_types=1);

// Réaménage la salle « Atelier » d'après le plan relevé à la main (novembre 2026) :
//   - salle de 8,10 m × 4,50 m, hauteur 3,20 m ;
//   - mur du haut du plan : 2,30 m libres, les 4 armoires à tiroirs (1 m × 70 cm × 1 m), une porte de 1 m, 80 cm ;
//   - mur du bas : 6 grandes armoires (1 m × 80 cm × 1,77 m) depuis le coin gauche ;
//   - mur de droite : la porte de référence près du coin, puis 4 grandes armoires sur 3,23 m (80 cm de façade).
// Les armoires à tiroirs gardent leur contenu (objets, stock, historique) ; elles passent de 75 à 70 cm de profondeur.
// Le stock du rayonnage est déplacé dans des boîtes de la grande armoire A par l'opération habituelle (historique,
// emprunts en cours rattachés au nouvel emplacement), puis le rayonnage est retiré. Les autres meubles (établis,
// copies) ne sont retirés que s'ils sont vides : sinon le script s'arrête sans rien modifier.
//
// Usage : php bin/amenager-atelier.php            (refuse de tourner deux fois)
//
// Repère (docs/ARCHITECTURE.md §3) : origine au coin gauche du mur de la porte de référence vue de l'intérieur.
// La porte de référence est sur le mur de droite du plan : X descend ce mur (du haut vers le bas du plan),
// Z traverse la salle vers la gauche du plan. Point du plan (sx depuis la gauche, sy depuis le haut) → X = sy, Z = 8100 − sx.

use App\Audit;
use App\Controller\StorageUnitController;
use App\Database;
use App\Space\Geometry;
use App\Space\SpaceRepository as Repo;
use App\Space\SpaceValidator;
use App\Stock\ItemIndex;
use App\Stock\StockService;

require __DIR__ . '/../bootstrap.php';

const ROOM_NAME = 'Atelier';
const WIDTH = 8100;   // murs du haut et du bas du plan
const DEPTH = 4500;   // murs de gauche et de droite du plan
const HEIGHT = 3200;
const DRAWER_CABINETS = [
    'Armoire 1 : visserie et composants',
    'Armoire 2 : composants et connectique',
    'Armoire 3 : informatique, robotique et outils',
    'Armoire 4 : outillage',
];
const RACK = 'Rayonnage';
const COMMENT = 'Réaménagement de l\'atelier : le rayonnage est remplacé par les grandes armoires.';

$pdo = Database::connection();
$fail = static function (string $message): never {
    fwrite(STDERR, "Arrêt : {$message}\nRien n'a été modifié.\n");
    exit(1);
};

$stmt = $pdo->prepare('SELECT id FROM rooms WHERE name = ?');
$stmt->execute([ROOM_NAME]);
$roomId = $stmt->fetchColumn() ?: $fail('salle « ' . ROOM_NAME . ' » introuvable.');
$roomId = (int) $roomId;

$adminId = $pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_protected = 1 AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
$adminId = $adminId === false ? $fail('aucun administrateur actif (auteur des mouvements).') : (int) $adminId;

$types = [];
foreach ($pdo->query('SELECT * FROM storage_types') as $t) {
    $types[$t['name']] = $t;
}
foreach (['Armoire', 'Étagère', 'Boîte plastique'] as $name) {
    isset($types[$name]) || $fail("type de rangement manquant : {$name}.");
}

/** Toutes les unités de la salle, par id, et la liste des enfants de chacune. */
$loadUnits = static function () use ($pdo, $roomId): array {
    $units = [];
    $children = [];
    foreach (Repo::units($pdo, $roomId) as $u) {
        $units[(int) $u['id']] = $u;
        $children[(int) $u['parent_id']][] = (int) $u['id'];
    }
    return [$units, $children];
};
[$units, $children] = $loadUnits();

$descendants = static function (int $id) use (&$children, &$descendants): array {
    $all = [$id];
    foreach ($children[$id] ?? [] as $child) {
        array_push($all, ...$descendants($child));
    }
    return $all;
};
$stockIn = static function (array $ids) use ($pdo): array {
    if ($ids === []) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT item_id, unit_id, quantity FROM stock_levels WHERE quantity > 0 AND unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY unit_id, item_id');
    $stmt->execute($ids);
    return $stmt->fetchAll();
};

// ===== Vérifications : rien n'est modifié tant que tout n'est pas d'aplomb =====

$top = [];
foreach ($children[0] ?? [] as $id) {
    $top[$units[$id]['name']] = $id;
}
foreach ($top as $name => $id) {
    if (str_starts_with($name, 'Grande armoire ')) {
        $fail('la salle a déjà été réaménagée (« ' . $name . ' » existe).');
    }
}
foreach (DRAWER_CABINETS as $name) {
    isset($top[$name]) || $fail("armoire à tiroirs introuvable : « {$name} ».");
}
$rackId = $top[RACK] ?? null;
$toRemove = [];
foreach ($top as $name => $id) {
    if (in_array($name, DRAWER_CABINETS, true) || $id === $rackId) {
        continue;
    }
    if ($stockIn($descendants($id)) !== []) {
        $fail("« {$name} » contient du stock. Videz-le ou déplacez son contenu, puis relancez.");
    }
    $toRemove[$name] = $id;
}
$rackStock = $rackId === null ? [] : $stockIn($descendants($rackId));
// Boîtes à recréer : celles qui ont du stock, et celles d'où vient un emprunt en cours (le retour doit y revenir)
$rackLoaned = [];
if ($rackId !== null) {
    $ids = $descendants($rackId);
    $stmt = $pdo->prepare("SELECT DISTINCT origin_unit_id FROM loans WHERE status = 'open' AND origin_unit_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $rackLoaned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
$rackBoxes = array_values(array_unique([...array_map(static fn ($s) => (int) $s['unit_id'], $rackStock), ...$rackLoaned]));
sort($rackBoxes);

// ===== Grandes armoires : 1,77 m, façade bleue, 5 étagères garnies comme sur la photo =====

// Étagères de haut en bas : [nom des boîtes, colonnes, boîtes empilées, hauteur, profondeur, couleur]
const SHELVES = [
    ['Boîte transparente', 3, 2, 160, 600, '#dfe5ec'],
    ['Boîte bleue', 5, 2, 150, 360, '#2f6fd6'],
    ['Grande boîte', 3, 1, 300, 600, '#dfe5ec'],
    ['Bac', 4, 1, 220, 560, '#dfe5ec'],
    ['Boîte transparente', 3, 2, 160, 600, '#dfe5ec'],
];
const TALL_H = 1770;
const TALL_D = 800;
const TALL_PANEL = 19;
const NAVY = '#26357a';

$key = 1;
$slots = []; // boîtes de la grande armoire A qui recevront le contenu du rayonnage, dans l'ordre
$tallCabinet = static function (string $letter, int $width, int $x, int $z, int $rotation) use ($types, &$key, &$slots): array {
    $innerW = $width - 2 * TALL_PANEL;
    $innerD = TALL_D - TALL_PANEL;
    $innerH = TALL_H - 2 * TALL_PANEL;
    $levelH = intdiv($innerH, count(SHELVES));
    $shelfType = $types['Étagère'];
    $boxType = $types['Boîte plastique'];
    $levels = [];
    foreach (SHELVES as $i => [$boxName, $columns, $stack, $boxH, $boxD, $color]) {
        $boxW = intdiv($innerW, $columns);
        $boxes = [];
        $n = 0;
        for ($s = 0; $s < $stack; $s++) {
            for ($c = 0; $c < $columns; $c++) {
                $n++;
                $boxes[] = [
                    'id' => $key, 'type_id' => (int) $boxType['id'], 'name' => "{$boxName} {$n}", 'label' => sprintf('%s%d.%d', $letter, $i + 1, $n),
                    'width_mm' => $boxW, 'depth_mm' => $boxD, 'height_mm' => $boxH,
                    'pos_x_mm' => $c * $boxW, 'pos_y_mm' => $s * $boxH, 'pos_z_mm' => $innerD - $boxD,
                    'panel_mm' => 3, 'color' => $color, 'children' => [],
                ];
                // Rangées du bas d'abord, puis la rangée empilée : l'ordre où l'on range réellement
                if ($letter === 'A' && in_array($i, [0, 2, 4], true)) {
                    $slots[] = $key;
                }
                $key++;
            }
        }
        $levels[] = [
            'id' => $key++, 'type_id' => (int) $shelfType['id'], 'name' => 'Étagère ' . ($i + 1) . match ($i) { 0 => ' (haut)', count(SHELVES) - 1 => ' (bas)', default => '' },
            'width_mm' => $innerW, 'depth_mm' => $innerD, 'height_mm' => $levelH,
            'pos_x_mm' => 0, 'pos_y_mm' => $innerH - ($i + 1) * $levelH, 'pos_z_mm' => 0,
            'panel_mm' => TALL_PANEL, 'color' => $shelfType['default_color'], 'sort_order' => $i, 'children' => $boxes,
        ];
    }
    return [
        'id' => $key++, 'type_id' => (int) $types['Armoire']['id'], 'name' => "Grande armoire {$letter}", 'label' => $letter,
        'description' => 'Armoire haute à portes bleues, cinq étagères garnies de boîtes.',
        'width_mm' => $width, 'depth_mm' => TALL_D, 'height_mm' => TALL_H,
        'pos_x_mm' => $x, 'pos_y_mm' => 0, 'pos_z_mm' => $z, 'rotation_deg' => $rotation,
        'panel_mm' => TALL_PANEL, 'color' => NAVY, 'sort_order' => 10 + ord($letter) - ord('A'), 'children' => $levels,
    ];
};

$trees = [];
// Mur du bas du plan (X = 4500) : dos au mur, façades vers la salle (rotation 270), de gauche à droite
foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $letter) {
    $sx = $i * 1010;
    $trees[] = $tallCabinet($letter, 1000, DEPTH, WIDTH - $sx - 1000, 270);
}
// Mur de droite du plan (Z = 0, mur de la porte) : de bas en haut, 80 cm de façade, 3,23 m en tout
foreach (['G', 'H', 'I', 'J'] as $i => $letter) {
    $trees[] = $tallCabinet($letter, 800, DEPTH - 800 - $i * 810, 0, 0);
}
count($slots) >= count($rackBoxes) || $fail('pas assez de boîtes dans la grande armoire A pour le contenu du rayonnage.');

// ===== 1. Structure : salle, portes, armoires à tiroirs, grandes armoires (une seule transaction) =====

$created = Database::transaction(static function (PDO $pdo) use ($roomId, $units, $top, $toRemove, $rackId, $trees, $descendants, $types): array {
    $room = Repo::room($pdo, $roomId, true);

    foreach ($toRemove as $id) {
        $pdo->prepare('DELETE FROM storage_units WHERE id = ?')->execute([$id]); // enfants supprimés en cascade
    }

    $pdo->prepare(
        'UPDATE rooms SET outline = ?, height_mm = ?, door_offset_mm = 150, door_width_mm = 1000, door_height_mm = 2100, description = ? WHERE id = ?'
    )->execute([
        json_encode([[0, 0], [DEPTH, 0], [DEPTH, WIDTH], [0, WIDTH]]), HEIGHT,
        'Aménagée d\'après le plan relevé : 8,10 m × 4,50 m, hauteur 3,20 m. Quantités des armoires à tiroirs estimées d\'après photos, à vérifier à l\'inventaire.',
        $roomId,
    ]);
    // Mur 4 (de (0, 8100) à (0, 0)) = mur du haut du plan, parcouru de gauche à droite : la distance est celle du plan
    $pdo->prepare('DELETE FROM room_openings WHERE room_id = ?')->execute([$roomId]);
    $pdo->prepare("INSERT INTO room_openings (room_id, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm) VALUES (?, 'door', 'Porte du couloir', 3, 6300, 1000, 2100, 0)")
        ->execute([$roomId]);

    // Armoires à tiroirs contre le mur du haut, de gauche à droite à partir de 2,30 m, façades vers la salle
    foreach (DRAWER_CABINETS as $i => $name) {
        $id = $top[$name];
        $sx = 2300 + $i * 1000;
        $pdo->prepare('UPDATE storage_units SET pos_x_mm = 0, pos_y_mm = 0, pos_z_mm = ?, rotation_deg = 90, sort_order = ? WHERE id = ?')->execute([WIDTH - $sx, $i, $id]);
        resizeDepth($pdo, $id, 700);
    }
    // Rayonnage : posé au milieu le temps de déplacer son contenu
    if ($rackId !== null) {
        $pdo->prepare('UPDATE storage_units SET pos_x_mm = 1500, pos_y_mm = 0, pos_z_mm = 3000, rotation_deg = 0 WHERE id = ?')->execute([$rackId]);
    }

    $room = Repo::room($pdo, $roomId, true);
    SpaceValidator::room($room);
    SpaceValidator::openingsStillValid($room, Repo::openings($pdo, $roomId));
    // Chaque meuble existant doit tenir dans la nouvelle salle sans chevauchement
    foreach (Repo::units($pdo, $roomId) as $u) {
        if ($u['parent_id'] === null) {
            SpaceValidator::unit($pdo, $room, $u, Repo::type($pdo, (int) $u['type_id']), null);
        }
    }

    $ids = [];
    foreach ($trees as $i => $tree) {
        StorageUnitController::insertNode($pdo, $room, $tree, null, 100 + $i, $ids);
    }
    Repo::rebuildPaths($pdo, $roomId);
    return $ids;
});

/**
 * Nouvelle profondeur d'un meuble ; son contenu est réduit d'autant, en proportion, sur toute la hiérarchie
 * (tiroirs, bacs, plateaux), puis contrôlé par les règles habituelles.
 */
function resizeDepth(PDO $pdo, int $id, int $newDepth): void
{
    $unit = Repo::unit($pdo, $id);
    $type = Repo::type($pdo, (int) $unit['type_id']);
    $old = Geometry::interior($type['shape'], (int) $unit['width_mm'], (int) $unit['depth_mm'], (int) $unit['height_mm'], (int) $unit['panel_mm']);
    $new = Geometry::interior($type['shape'], (int) $unit['width_mm'], $newDepth, (int) $unit['height_mm'], (int) $unit['panel_mm']);
    $pdo->prepare('UPDATE storage_units SET depth_mm = ? WHERE id = ?')->execute([$newDepth, $id]);
    if ($old === null || $new === null) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id, pos_z_mm, depth_mm FROM storage_units WHERE parent_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $child) {
        $ratio = $new['depth'] / $old['depth'];
        $z = (int) floor((int) $child['pos_z_mm'] * $ratio);
        $end = (int) floor(((int) $child['pos_z_mm'] + (int) $child['depth_mm']) * $ratio);
        $pdo->prepare('UPDATE storage_units SET pos_z_mm = ? WHERE id = ?')->execute([$z, (int) $child['id']]);
        resizeDepth($pdo, (int) $child['id'], $end - $z);
    }
    $unit = Repo::unit($pdo, $id);
    SpaceValidator::childrenFit($pdo, $unit, $type, 'cm');
}

// ===== 2. Contenu du rayonnage → boîtes de la grande armoire A (opération de déplacement habituelle) =====

$moved = 0;
foreach ($rackBoxes as $i => $oldId) {
    $newId = (int) $created[(string) $slots[$i]];
    $pdo->prepare('UPDATE storage_units SET name = ? WHERE id = ?')->execute([$units[$oldId]['name'], $newId]);
}
Repo::rebuildPaths($pdo, $roomId);
$pathOf = static function (int $id) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT path_text FROM storage_units WHERE id = ?');
    $stmt->execute([$id]);
    return (string) $stmt->fetchColumn();
};
foreach ($rackStock as $line) {
    $oldId = (int) $line['unit_id'];
    $newId = (int) $created[(string) $slots[array_search($oldId, $rackBoxes, true)]];
    $active = $pdo->prepare('SELECT is_active FROM items WHERE id = ?');
    $active->execute([(int) $line['item_id']]);
    if ((int) $active->fetchColumn() === 1) {
        StockService::move((int) $line['item_id'], $oldId, $newId, (string) $line['quantity'], $adminId, COMMENT);
    } else {
        // Objet archivé : l'opération de déplacement le refuse, son stock change simplement de rangement
        $pdo->prepare('UPDATE stock_levels SET unit_id = ? WHERE item_id = ? AND unit_id = ?')->execute([$newId, (int) $line['item_id'], $oldId]);
    }
    $moved++;
}
foreach ($rackBoxes as $i => $oldId) {
    $newId = (int) $created[(string) $slots[$i]];
    $pdo->prepare("UPDATE loans SET origin_unit_id = ?, origin_path = ? WHERE origin_unit_id = ? AND status = 'open'")
        ->execute([$newId, $pathOf($newId), $oldId]);
}

// ===== 3. Rayonnage retiré, chemins et index de recherche à jour =====

Database::transaction(static function (PDO $pdo) use ($rackId, $roomId, $stockIn, $descendants): void {
    if ($rackId !== null) {
        if ($stockIn($descendants($rackId)) !== []) {
            throw new RuntimeException('Le rayonnage contient encore du stock : il est conservé.');
        }
        $pdo->prepare('DELETE FROM storage_units WHERE id = ?')->execute([$rackId]);
    }
    Repo::rebuildPaths($pdo, $roomId);
});
$items = $pdo->prepare('SELECT DISTINCT s.item_id FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id WHERE u.room_id = ?');
$items->execute([$roomId]);
$itemIds = array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN));
ItemIndex::reindex($pdo, $itemIds);

$total = $pdo->prepare('SELECT COUNT(*) FROM storage_units WHERE room_id = ?');
$total->execute([$roomId]);
Audit::log($adminId, 'room.rearranged', 'room', $roomId, ['removed' => array_keys($toRemove), 'tall_cabinets' => count($trees), 'moved_lines' => $moved]);
echo 'Salle « ' . ROOM_NAME . ' » réaménagée : 8,10 m × 4,50 m × 3,20 m, ' . $total->fetchColumn() . " rangements.\n"
    . '  Armoires à tiroirs : ' . count(DRAWER_CABINETS) . " (mur du haut, 70 cm de profondeur, contenu conservé)\n"
    . '  Grandes armoires : ' . count($trees) . " (A à F mur du bas, G à J mur de droite)\n"
    . '  Retirés (vides) : ' . ($toRemove === [] ? 'aucun' : implode(', ', array_keys($toRemove))) . "\n"
    . "  Rayonnage : {$moved} ligne(s) de stock déplacée(s) dans la grande armoire A, puis retiré\n";
