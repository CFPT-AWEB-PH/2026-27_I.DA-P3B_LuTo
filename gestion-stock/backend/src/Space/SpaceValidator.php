<?php

declare(strict_types=1);

namespace App\Space;

use App\Http\HttpException;
use App\Http\Input;
use PDO;

/**
 * Règles de cohérence spatiale, vérifiées côté serveur avant toute écriture :
 * contour de salle valide, porte et ouvertures sur leurs murs, meubles dans la salle,
 * rangements dans le volume intérieur de leur parent, aucun chevauchement.
 */
final class SpaceValidator
{
    /** @param array<string, mixed> $room */
    public static function room(array $room): void
    {
        $outline = $room['outline'];
        $unit = $room['display_unit'];
        if ($outline[0] !== [0, 0]) {
            Input::fail('outline', "Le premier point doit être l'origine (0, 0) : le coin du mur de la porte.");
        }
        if ($outline[1][1] !== 0 || $outline[1][0] <= 0) {
            Input::fail('outline', "Le deuxième point doit se trouver sur l'axe X (z = 0) : c'est le mur de la porte.");
        }
        foreach ($outline as $i => [, $z]) {
            if ($z < 0) {
                Input::fail('outline', 'Point n° ' . ($i + 1) . " : la salle doit se trouver du côté intérieur du mur de la porte (z ≥ 0).");
            }
        }
        if (!Geometry::isSimple($outline)) {
            Input::fail('outline', 'Le contour de la salle se croise lui-même.');
        }
        if (Geometry::area($outline) < 1_000_000) {
            Input::fail('outline', 'La surface de la salle doit être d\'au moins 1 m².');
        }
        $doorWall = $outline[1][0];
        if ($room['door_offset_mm'] + $room['door_width_mm'] > $doorWall) {
            Input::fail('door_offset_mm', 'La porte dépasse du mur de la porte (longueur : ' . Length::format($doorWall, $unit) . ').');
        }
        if ($room['door_height_mm'] > $room['height_mm']) {
            Input::fail('door_height_mm', 'La porte est plus haute que la salle.');
        }
    }

    /** @param list<array{int, int}> $outline */
    public static function wallLength(array $outline, int $index): float
    {
        [$x1, $z1] = $outline[$index];
        [$x2, $z2] = $outline[($index + 1) % count($outline)];
        return hypot($x2 - $x1, $z2 - $z1);
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $opening
     * @param list<array<string, mixed>> $others autres ouvertures de la salle
     */
    public static function opening(array $room, array $opening, array $others): void
    {
        $unit = $room['display_unit'];
        $walls = count($room['outline']);
        if ($opening['wall_index'] >= $walls) {
            Input::fail('wall_index', "Ce mur n'existe pas (la salle a {$walls} murs).");
        }
        $length = self::wallLength($room['outline'], $opening['wall_index']);
        if ($opening['offset_mm'] + $opening['width_mm'] > $length) {
            Input::fail('offset_mm', "L'ouverture dépasse du mur (longueur : " . Length::format($length, $unit) . ').');
        }
        if ($opening['sill_mm'] + $opening['height_mm'] > $room['height_mm']) {
            Input::fail('height_mm', "L'ouverture dépasse la hauteur de la salle.");
        }
        $obstacles = [[
            'wall_index' => 0, 'offset_mm' => $room['door_offset_mm'], 'width_mm' => $room['door_width_mm'],
            'sill_mm' => 0, 'height_mm' => $room['door_height_mm'], 'name' => 'Porte de référence',
        ]];
        foreach ($others as $other) {
            if (($other['id'] ?? null) !== ($opening['id'] ?? false)) {
                $obstacles[] = $other;
            }
        }
        foreach ($obstacles as $other) {
            if ((int) $other['wall_index'] === $opening['wall_index']
                && Geometry::rangesOverlap($opening['offset_mm'], $opening['offset_mm'] + $opening['width_mm'], $other['offset_mm'], $other['offset_mm'] + $other['width_mm'])
                && Geometry::rangesOverlap($opening['sill_mm'], $opening['sill_mm'] + $opening['height_mm'], $other['sill_mm'], $other['sill_mm'] + $other['height_mm'])) {
                Input::fail('offset_mm', 'Chevauche « ' . ($other['name'] ?: 'ouverture') . ' ».');
            }
        }
    }

    /**
     * Après une modification de la salle, les ouvertures existantes doivent rester valides.
     *
     * @param list<array<string, mixed>> $openings
     */
    public static function openingsStillValid(array $room, array $openings): void
    {
        foreach ($openings as $opening) {
            try {
                self::opening($room, $opening, $openings);
            } catch (HttpException $e) {
                $reason = implode(' ', $e->details['fields'] ?? []);
                Input::fail('outline', 'L\'ouverture « ' . ($opening['name'] ?: 'sans nom') . " » ne serait plus valide : {$reason}");
            }
        }
    }

    /**
     * Vérifie l'emplacement d'un rangement (nouveau ou modifié).
     *
     * @param array<string, mixed> $room
     * @param array<string, mixed> $unit valeurs complètes, avec 'id' null pour une création
     * @param array<string, mixed> $type
     * @param array<string, mixed>|null $parent rangement parent avec sa forme (shape) et can_contain_units
     */
    public static function unit(PDO $pdo, array $room, array $unit, array $type, ?array $parent): void
    {
        $roomUnit = $room['display_unit'];
        if ($type['shape'] !== 'divider' && Geometry::interior($type['shape'], $unit['width_mm'], $unit['depth_mm'], $unit['height_mm'], $unit['panel_mm']) === null) {
            Input::fail('panel_mm', "Parois trop épaisses pour ces dimensions : il ne reste aucun volume intérieur.");
        }

        if ($parent === null) {
            $corners = Geometry::footprint($unit['pos_x_mm'], $unit['pos_z_mm'], $unit['width_mm'], $unit['depth_mm'], $unit['rotation_deg']);
            if (!Geometry::containsPolygon($room['outline'], $corners)) {
                Input::fail('pos_x_mm', 'Le meuble sort de la salle : vérifiez sa position, ses dimensions et sa rotation.');
            }
            if ($unit['pos_y_mm'] < 0) {
                Input::fail('pos_y_mm', 'Le meuble ne peut pas être sous le sol.');
            }
            if ($unit['pos_y_mm'] + $unit['height_mm'] > $room['height_mm']) {
                Input::fail('height_mm', 'Le meuble dépasse la hauteur de la salle (' . Length::format($room['height_mm'], $roomUnit) . ').');
            }
            foreach (self::siblings($pdo, $room['id'], null, $unit['id']) as $other) {
                $otherCorners = Geometry::footprint($other['pos_x_mm'], $other['pos_z_mm'], $other['width_mm'], $other['depth_mm'], $other['rotation_deg']);
                if (Geometry::convexOverlap($corners, $otherCorners)
                    && Geometry::rangesOverlap($unit['pos_y_mm'], $unit['pos_y_mm'] + $unit['height_mm'], $other['pos_y_mm'], $other['pos_y_mm'] + $other['height_mm'])) {
                    Input::fail('pos_x_mm', "Chevauche le meuble « {$other['name']} ».");
                }
            }
            return;
        }

        if (!(int) $parent['can_contain_units']) {
            Input::fail('parent_id', "« {$parent['name']} » ne peut pas contenir d'autres rangements.");
        }
        $inside = Geometry::interior($parent['shape'], $parent['width_mm'], $parent['depth_mm'], $parent['height_mm'], $parent['panel_mm']);
        if ($inside === null) {
            Input::fail('parent_id', "« {$parent['name']} » n'a pas de volume intérieur.");
        }
        foreach (['x' => 'pos_x_mm', 'y' => 'pos_y_mm', 'z' => 'pos_z_mm'] as $axis => $field) {
            if ($unit[$field] < 0) {
                Input::fail($field, "Position négative : le rangement sort de « {$parent['name']} ».");
            }
        }
        foreach ([['width_mm', 'pos_x_mm', 'width', 'en largeur'], ['height_mm', 'pos_y_mm', 'height', 'en hauteur'], ['depth_mm', 'pos_z_mm', 'depth', 'en profondeur']] as [$size, $pos, $key, $label]) {
            $excess = $unit[$pos] + $unit[$size] - $inside[$key];
            if ($excess > 0) {
                Input::fail($size, "Dépasse de « {$parent['name']} » {$label} de " . Length::format($excess, $roomUnit)
                    . ' (intérieur disponible : ' . Length::format($inside[$key], $roomUnit) . ').');
            }
        }
        foreach (self::siblings($pdo, $room['id'], $parent['id'], $unit['id']) as $other) {
            if (self::boxesOverlap($unit, $other)) {
                Input::fail('pos_x_mm', "Chevauche « {$other['name']} » dans « {$parent['name']} ».");
            }
        }
    }

    /**
     * Après un redimensionnement, les rangements contenus doivent encore tenir à l'intérieur.
     *
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $type
     */
    public static function childrenFit(PDO $pdo, array $unit, array $type, string $roomUnit): void
    {
        $stmt = $pdo->prepare('SELECT name, width_mm, depth_mm, height_mm, pos_x_mm, pos_y_mm, pos_z_mm FROM storage_units WHERE parent_id = ?');
        $stmt->execute([$unit['id']]);
        $children = $stmt->fetchAll();
        if ($children === []) {
            return;
        }
        if (!(int) $type['can_contain_units']) {
            Input::fail('type_id', "Ce type ne peut pas contenir de rangements, or « {$unit['name']} » en contient.");
        }
        $inside = Geometry::interior($type['shape'], $unit['width_mm'], $unit['depth_mm'], $unit['height_mm'], $unit['panel_mm']);
        foreach ($children as $child) {
            if ($inside === null
                || $child['pos_x_mm'] + $child['width_mm'] > $inside['width']
                || $child['pos_y_mm'] + $child['height_mm'] > $inside['height']
                || $child['pos_z_mm'] + $child['depth_mm'] > $inside['depth']) {
                Input::fail('width_mm', "« {$child['name']} » ne tiendrait plus à l'intérieur. Réduisez-le ou déplacez-le d'abord.");
            }
        }
    }

    /**
     * Après une modification de la salle, tous les meubles doivent y tenir encore.
     *
     * @param array<string, mixed> $room
     */
    public static function furnitureFitsRoom(PDO $pdo, array $room): void
    {
        foreach (self::siblings($pdo, $room['id'], null, null) as $unit) {
            $corners = Geometry::footprint($unit['pos_x_mm'], $unit['pos_z_mm'], $unit['width_mm'], $unit['depth_mm'], $unit['rotation_deg']);
            if (!Geometry::containsPolygon($room['outline'], $corners) || $unit['pos_y_mm'] + $unit['height_mm'] > $room['height_mm']) {
                Input::fail('outline', "Le meuble « {$unit['name']} » ne tiendrait plus dans la salle. Déplacez-le d'abord.");
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function siblings(PDO $pdo, int $roomId, ?int $parentId, ?int $exceptId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, name, width_mm, depth_mm, height_mm, pos_x_mm, pos_y_mm, pos_z_mm, rotation_deg
             FROM storage_units WHERE room_id = ? AND parent_id <=> ? AND id <> ?'
        );
        $stmt->execute([$roomId, $parentId, $exceptId ?? 0]);
        return $stmt->fetchAll();
    }

    /** Pavés alignés sur les axes du parent. */
    private static function boxesOverlap(array $a, array $b): bool
    {
        return Geometry::rangesOverlap($a['pos_x_mm'], $a['pos_x_mm'] + $a['width_mm'], $b['pos_x_mm'], $b['pos_x_mm'] + $b['width_mm'])
            && Geometry::rangesOverlap($a['pos_y_mm'], $a['pos_y_mm'] + $a['height_mm'], $b['pos_y_mm'], $b['pos_y_mm'] + $b['height_mm'])
            && Geometry::rangesOverlap($a['pos_z_mm'], $a['pos_z_mm'] + $a['depth_mm'], $b['pos_z_mm'], $b['pos_z_mm'] + $b['depth_mm']);
    }
}
