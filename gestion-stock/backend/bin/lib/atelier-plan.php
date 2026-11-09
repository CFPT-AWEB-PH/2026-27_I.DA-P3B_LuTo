<?php

declare(strict_types=1);

// Plan de l'atelier relevé à la main (novembre 2026), partagé par amenager-atelier.php et corriger-atelier-12m.php.
//
// Salle de 8,10 m (murs du haut et du bas du plan) × 12 m (murs de gauche et de droite), hauteur 3,20 m.
//   - mur du haut : 2,30 m libres, les 4 armoires à tiroirs (1 m × 70 cm × 1 m), la porte du couloir (1 m), 80 cm ;
//   - mur de droite : 1,40 m, la porte de référence (1 m), 3,23 m libres, 4 grandes armoires (G à J) ;
//   - mur du bas : 6 grandes armoires (A à F) depuis le coin gauche ;
//   - mur de gauche : des fenêtres sur toute la longueur.
// Grande armoire : 1 m × 80 cm × 1,77 m, portes bleues, 5 étagères garnies de boîtes comme sur la photo.
//
// Repère (docs/ARCHITECTURE.md §3) : origine au coin gauche du mur de la porte de référence, vue de l'intérieur.
// La porte de référence est sur le mur de droite du plan : X descend ce mur (du haut vers le bas du plan),
// Z traverse la salle vers la gauche. Point du plan (sx depuis la gauche, sy depuis le haut) → X = sy, Z = 8100 − sx.

const ATELIER_WIDTH = 8100;   // murs du haut et du bas du plan (axe Z)
const ATELIER_DEPTH = 12000;  // murs de gauche et de droite du plan (axe X, mur de la porte)
const ATELIER_HEIGHT = 3200;
const ATELIER_DOOR_OFFSET = 1400;
const ATELIER_DESCRIPTION = 'Aménagée d\'après le plan relevé : 8,10 m × 12 m, hauteur 3,20 m, fenêtres sur tout le mur de gauche. '
    . 'Quantités des armoires à tiroirs estimées d\'après photos, à vérifier à l\'inventaire.';

const ATELIER_TALL_H = 1770;
const ATELIER_TALL_D = 800;
const ATELIER_TALL_W = 1000;
const ATELIER_TALL_PANEL = 19;
const ATELIER_NAVY = '#26357a';
// Étagères de haut en bas : [nom des boîtes, colonnes, boîtes empilées, hauteur, profondeur, couleur]
const ATELIER_SHELVES = [
    ['Boîte transparente', 3, 2, 160, 600, '#dfe5ec'],
    ['Boîte bleue', 5, 2, 150, 360, '#2f6fd6'],
    ['Grande boîte', 3, 1, 300, 600, '#dfe5ec'],
    ['Bac', 4, 1, 220, 560, '#dfe5ec'],
    ['Boîte transparente', 3, 2, 160, 600, '#dfe5ec'],
];

/** Contour de la salle. @return list<array{int, int}> */
function atelierOutline(): array
{
    return [[0, 0], [ATELIER_DEPTH, 0], [ATELIER_DEPTH, ATELIER_WIDTH], [0, ATELIER_WIDTH]];
}

/**
 * Ouvertures autres que la porte de référence.
 * Mur 3 (de (0, 8100) à (0, 0)) = mur du haut du plan, parcouru de gauche à droite : la distance est celle du plan.
 * Mur 2 (de (12000, 8100) à (0, 8100)) = mur de gauche, parcouru de bas en haut : six fenêtres de 1,80 m.
 *
 * @return list<array{kind: string, name: string, wall_index: int, offset_mm: int, width_mm: int, height_mm: int, sill_mm: int}>
 */
function atelierOpenings(): array
{
    $openings = [['kind' => 'door', 'name' => 'Porte du couloir', 'wall_index' => 3, 'offset_mm' => 6300, 'width_mm' => 1000, 'height_mm' => 2100, 'sill_mm' => 0]];
    for ($i = 0; $i < 6; $i++) {
        $openings[] = ['kind' => 'window', 'name' => 'Fenêtre ' . ($i + 1), 'wall_index' => 2, 'offset_mm' => 100 + $i * 2000, 'width_mm' => 1800, 'height_mm' => 1800, 'sill_mm' => 900];
    }
    return $openings;
}

/**
 * Places des grandes armoires : [lettre, x, z, rotation].
 * Mur du bas (X = 12000) : dos au mur, façades vers la salle (rotation 270), de gauche à droite.
 * Mur de droite (Z = 0) : façades vers la salle (rotation 0), de bas en haut, après la porte et les 3,23 m libres.
 *
 * @return list<array{string, int, int, int}>
 */
function atelierTallPlaces(): array
{
    $places = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $letter) {
        $places[] = [$letter, ATELIER_DEPTH, ATELIER_WIDTH - $i * 1010 - ATELIER_TALL_W, 270];
    }
    $start = ATELIER_DOOR_OFFSET + 1000 + 3230;
    foreach (['J', 'I', 'H', 'G'] as $i => $letter) {
        $places[] = [$letter, $start + $i * 1010, 0, 0];
    }
    usort($places, static fn ($a, $b) => strcmp($a[0], $b[0]));
    return $places;
}

/**
 * Arbre d'une grande armoire, prêt pour StorageUnitController::insertNode.
 * $key : compteur des clés provisoires ; $slots reçoit les clés des boîtes de l'armoire A, dans l'ordre où l'on range.
 *
 * @param array<string, array<string, mixed>> $types
 * @return array<string, mixed>
 */
function atelierTallCabinet(array $types, string $letter, int $x, int $z, int $rotation, int &$key, array &$slots): array
{
    $innerW = ATELIER_TALL_W - 2 * ATELIER_TALL_PANEL;
    $innerD = ATELIER_TALL_D - ATELIER_TALL_PANEL;
    $innerH = ATELIER_TALL_H - 2 * ATELIER_TALL_PANEL;
    $levelH = intdiv($innerH, count(ATELIER_SHELVES));
    $shelfType = $types['Étagère'];
    $boxType = $types['Boîte plastique'];
    $levels = [];
    foreach (ATELIER_SHELVES as $i => [$boxName, $columns, $stack, $boxH, $boxD, $color]) {
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
                if ($letter === 'A' && in_array($i, [0, 2, 4], true)) {
                    $slots[] = $key;
                }
                $key++;
            }
        }
        $levels[] = [
            'id' => $key++, 'type_id' => (int) $shelfType['id'], 'name' => 'Étagère ' . ($i + 1) . match ($i) { 0 => ' (haut)', count(ATELIER_SHELVES) - 1 => ' (bas)', default => '' },
            'width_mm' => $innerW, 'depth_mm' => $innerD, 'height_mm' => $levelH,
            'pos_x_mm' => 0, 'pos_y_mm' => $innerH - ($i + 1) * $levelH, 'pos_z_mm' => 0,
            'panel_mm' => ATELIER_TALL_PANEL, 'color' => $shelfType['default_color'], 'sort_order' => $i, 'children' => $boxes,
        ];
    }
    return [
        'id' => $key++, 'type_id' => (int) $types['Armoire']['id'], 'name' => "Grande armoire {$letter}", 'label' => $letter,
        'description' => 'Armoire haute à portes bleues, cinq étagères garnies de boîtes.',
        'width_mm' => ATELIER_TALL_W, 'depth_mm' => ATELIER_TALL_D, 'height_mm' => ATELIER_TALL_H,
        'pos_x_mm' => $x, 'pos_y_mm' => 0, 'pos_z_mm' => $z, 'rotation_deg' => $rotation,
        'panel_mm' => ATELIER_TALL_PANEL, 'color' => ATELIER_NAVY, 'sort_order' => 10 + ord($letter) - ord('A'), 'children' => $levels,
    ];
}
