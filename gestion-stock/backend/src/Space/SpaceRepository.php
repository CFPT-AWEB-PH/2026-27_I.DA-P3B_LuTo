<?php

declare(strict_types=1);

namespace App\Space;

use App\Http\HttpException;
use App\Stock\ItemIndex;
use App\Stock\Quantity;
use PDO;

/** Lecture des salles et rangements, et mise à jour des chemins utilisés par la recherche. */
final class SpaceRepository
{
    public const PATH_SEPARATOR = ' › ';

    /** @return array<string, mixed> */
    public static function room(PDO $pdo, int $id, bool $lock = false): array
    {
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $room = $stmt->fetch() ?: throw new HttpException(404, 'Salle introuvable.');
        $room['outline'] = json_decode($room['outline'], true, 4, JSON_THROW_ON_ERROR);
        return $room;
    }

    /** @return list<array<string, mixed>> */
    public static function openings(PDO $pdo, int $roomId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM room_openings WHERE room_id = ? ORDER BY wall_index, offset_mm');
        $stmt->execute([$roomId]);
        return $stmt->fetchAll();
    }

    /** Rangement avec les caractéristiques de son type. @return array<string, mixed> */
    public static function unit(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.*, t.shape, t.can_contain_units, t.can_hold_items, t.name AS type_name
             FROM storage_units u JOIN storage_types t ON t.id = u.type_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new HttpException(404, 'Rangement introuvable.');
    }

    /** @return list<array<string, mixed>> */
    public static function units(PDO $pdo, int $roomId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.*, t.shape, t.can_contain_units, t.can_hold_items, t.name AS type_name
             FROM storage_units u JOIN storage_types t ON t.id = u.type_id
             WHERE u.room_id = ? ORDER BY u.parent_id IS NOT NULL, u.parent_id, u.sort_order, u.pos_y_mm DESC, u.pos_x_mm, u.id'
        );
        $stmt->execute([$roomId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public static function type(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM storage_types WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new HttpException(422, 'Données invalides.', ['fields' => ['type_id' => 'Type de rangement introuvable.']]);
    }

    /** Recalcule « Salle › Armoire › Tiroir » pour tous les rangements d'une salle. */
    public static function rebuildPaths(PDO $pdo, int $roomId): void
    {
        $room = self::room($pdo, $roomId);
        $stmt = $pdo->prepare('SELECT id, parent_id, name, path_text FROM storage_units WHERE room_id = ?');
        $stmt->execute([$roomId]);
        $units = [];
        foreach ($stmt->fetchAll() as $row) {
            $units[(int) $row['id']] = $row;
        }
        $update = $pdo->prepare('UPDATE storage_units SET path_text = ? WHERE id = ?');
        foreach ($units as $id => $unit) {
            $names = [];
            for ($cursor = $unit; $cursor !== null; $cursor = $cursor['parent_id'] === null ? null : ($units[(int) $cursor['parent_id']] ?? null)) {
                array_unshift($names, $cursor['name']);
            }
            $path = mb_substr($room['name'] . self::PATH_SEPARATOR . implode(self::PATH_SEPARATOR, $names), 0, 1000);
            if ($path !== $unit['path_text']) {
                $update->execute([$path, $id]);
            }
        }
        // Les chemins et étiquettes font partie du texte de recherche des objets rangés ici
        ItemIndex::reindexRoom($pdo, $roomId);
    }

    /** @param array<string, mixed> $room @return array<string, mixed> */
    public static function formatRoom(array $room): array
    {
        $ints = ['id', 'height_mm', 'wall_thickness_mm', 'door_offset_mm', 'door_width_mm', 'door_height_mm'];
        $out = [];
        foreach (['id', 'name', 'label', 'description', 'display_unit', 'outline', 'height_mm', 'wall_thickness_mm',
                     'door_offset_mm', 'door_width_mm', 'door_height_mm', 'floor_color', 'floor_material', 'wall_color',
                     'created_at', 'updated_at'] as $key) {
            $out[$key] = in_array($key, $ints, true) ? (int) $room[$key] : $room[$key];
        }
        return $out;
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    public static function formatUnit(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'room_id' => (int) $u['room_id'],
            'parent_id' => $u['parent_id'] === null ? null : (int) $u['parent_id'],
            'type_id' => (int) $u['type_id'],
            'type_name' => $u['type_name'],
            'shape' => $u['shape'],
            'can_contain_units' => (bool) $u['can_contain_units'],
            'can_hold_items' => (bool) $u['can_hold_items'],
            'name' => $u['name'],
            'label' => $u['label'],
            'reference' => $u['reference'],
            'description' => $u['description'],
            'width_mm' => (int) $u['width_mm'],
            'depth_mm' => (int) $u['depth_mm'],
            'height_mm' => (int) $u['height_mm'],
            'pos_x_mm' => (int) $u['pos_x_mm'],
            'pos_y_mm' => (int) $u['pos_y_mm'],
            'pos_z_mm' => (int) $u['pos_z_mm'],
            'rotation_deg' => (int) $u['rotation_deg'],
            'panel_mm' => (int) $u['panel_mm'],
            'capacity' => Quantity::out($u['capacity']),
            'color' => $u['color'],
            'sort_order' => (int) $u['sort_order'],
            'path' => $u['path_text'],
        ];
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    public static function formatOpening(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'room_id' => (int) $o['room_id'],
            'kind' => $o['kind'],
            'name' => $o['name'],
            'wall_index' => (int) $o['wall_index'],
            'offset_mm' => (int) $o['offset_mm'],
            'width_mm' => (int) $o['width_mm'],
            'height_mm' => (int) $o['height_mm'],
            'sill_mm' => (int) $o['sill_mm'],
        ];
    }
}
