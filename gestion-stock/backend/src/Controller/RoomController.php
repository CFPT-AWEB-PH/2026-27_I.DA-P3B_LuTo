<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use App\Space\SpaceRepository as Repo;
use App\Space\SpaceValidator;
use App\Stock\Quantity;
use PDO;

final class RoomController
{
    public static function index(Request $request): Response
    {
        $request->requirePermission('rooms.view');
        $rows = Database::connection()->query(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM storage_units u WHERE u.room_id = r.id AND u.parent_id IS NULL) AS furniture_count,
                    (SELECT COUNT(*) FROM storage_units u WHERE u.room_id = r.id) AS unit_count
             FROM rooms r ORDER BY r.name'
        )->fetchAll();
        $rooms = array_map(static function (array $row): array {
            $row['outline'] = json_decode($row['outline'], true);
            return Repo::formatRoom($row) + [
                'furniture_count' => (int) $row['furniture_count'],
                'unit_count' => (int) $row['unit_count'],
            ];
        }, $rows);
        return Response::json(['rooms' => $rooms]);
    }

    public static function show(Request $request, array $params): Response
    {
        $request->requirePermission('rooms.view');
        return Response::json(self::payload(Database::connection(), (int) $params['id'], $request->auth?->can('stock.view') ?? false));
    }

    public static function create(Request $request): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $room = self::read($request->input(), null);
        SpaceValidator::room($room);

        $id = Database::transaction(static function (PDO $pdo) use ($room, $auth): int {
            self::assertNameFree($pdo, $room['name'], null);
            self::assertFloorExists($pdo, $room['floor_id']);
            $room['floor_id'] ??= (int) $pdo->query('SELECT id FROM floors ORDER BY level_no = 0 DESC, level_no LIMIT 1')->fetchColumn() ?: null;
            $columns = array_keys($room);
            $pdo->prepare('INSERT INTO rooms (' . implode(', ', $columns) . ', created_by) VALUES ('
                . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')')
                ->execute([...array_values(self::toDb($room)), $auth->userId]);
            return (int) $pdo->lastInsertId();
        });

        Audit::log($auth->userId, 'room.created', 'room', $id, ['name' => $room['name']], $request->ip);
        return Response::json(self::payload(Database::connection(), $id, $request->auth?->can('stock.view') ?? false), 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];
        $input = $request->input();

        $changes = Database::transaction(static function (PDO $pdo) use ($id, $input): array {
            $current = Repo::room($pdo, $id, true);
            $room = self::read($input, $current);
            SpaceValidator::room($room);
            SpaceValidator::openingsStillValid($room + ['id' => $id], Repo::openings($pdo, $id));
            SpaceValidator::furnitureFitsRoom($pdo, $room + ['id' => $id]);
            self::assertNameFree($pdo, $room['name'], $id);
            self::assertFloorExists($pdo, $room['floor_id']);

            $changes = array_filter($room, static fn ($value, $key) => $value !== $current[$key], ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($changes)));
                $pdo->prepare("UPDATE rooms SET {$set} WHERE id = ?")->execute([...array_values(self::toDb($changes)), $id]);
                if (isset($changes['name']) || isset($changes['label'])) {
                    Repo::rebuildPaths($pdo, $id);
                }
            }
            return $changes;
        });

        if ($changes !== []) {
            Audit::log($auth->userId, 'room.updated', 'room', $id, $changes, $request->ip);
        }
        return Response::json(self::payload(Database::connection(), $id, $request->auth?->can('stock.view') ?? false));
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];
        $summary = Database::transaction(static function (PDO $pdo) use ($id): array {
            $room = Repo::room($pdo, $id, true);
            $ids = $pdo->prepare('SELECT id FROM storage_units WHERE room_id = ?');
            $ids->execute([$id]);
            $unitIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
            if (($items = StorageUnitController::stockCount($pdo, $unitIds)) > 0) {
                throw new HttpException(409, "La salle contient encore {$items} objet(s) en stock. Déplacez-les ou corrigez le stock avant de la supprimer.");
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM storage_units WHERE room_id = ?');
            $count->execute([$id]);
            $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$id]);
            return ['name' => $room['name'], 'units_deleted' => (int) $count->fetchColumn()];
        });
        Audit::log($auth->userId, 'room.deleted', 'room', $id, $summary, $request->ip);
        return Response::noContent();
    }

    /** Copie la salle, ses ouvertures et tous ses rangements (sans les références, qui sont uniques). */
    public static function duplicate(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        StorageUnitController::consumeBulkQuota($auth);
        $sourceId = (int) $params['id'];

        $newId = Database::transaction(static function (PDO $pdo) use ($sourceId, $auth): int {
            $source = Repo::room($pdo, $sourceId);
            $name = self::copyName($pdo, $source['name']);
            $room = array_intersect_key($source, array_flip(self::COLUMNS));
            $room['name'] = $name;
            // Même étage et même orientation ; le plan la range à la suite tant qu'elle n'est pas déplacée
            $room['floor_id'] = $source['floor_id'];
            $room['plan_rotation_deg'] = (int) $source['plan_rotation_deg'];
            $columns = array_keys($room);
            $pdo->prepare('INSERT INTO rooms (' . implode(', ', $columns) . ', created_by) VALUES ('
                . implode(', ', array_fill(0, count($columns) + 1, '?')) . ')')
                ->execute([...array_values(self::toDb($room)), $auth->userId]);
            $newId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO room_openings (room_id, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm)
                 SELECT ?, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm FROM room_openings WHERE room_id = ?'
            )->execute([$newId, $sourceId]);

            // Parents avant enfants : parcours en largeur depuis les meubles
            $units = Repo::units($pdo, $sourceId);
            $idMap = [];
            $insert = $pdo->prepare(
                'INSERT INTO storage_units (room_id, parent_id, type_id, name, label, reference, description, width_mm, depth_mm, height_mm,
                    pos_x_mm, pos_y_mm, pos_z_mm, rotation_deg, panel_mm, color, sort_order)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $queue = array_values(array_filter($units, static fn ($u) => $u['parent_id'] === null));
            while ($queue !== []) {
                $u = array_shift($queue);
                $insert->execute([
                    $newId, $u['parent_id'] === null ? null : $idMap[(int) $u['parent_id']], $u['type_id'], $u['name'], $u['label'],
                    $u['description'], $u['width_mm'], $u['depth_mm'], $u['height_mm'], $u['pos_x_mm'], $u['pos_y_mm'], $u['pos_z_mm'],
                    $u['rotation_deg'], $u['panel_mm'], $u['color'], $u['sort_order'],
                ]);
                $idMap[(int) $u['id']] = (int) $pdo->lastInsertId();
                foreach ($units as $child) {
                    if ((int) $child['parent_id'] === (int) $u['id']) {
                        $queue[] = $child;
                    }
                }
            }
            Repo::rebuildPaths($pdo, $newId);
            return $newId;
        });

        Audit::log($auth->userId, 'room.duplicated', 'room', $newId, ['source_id' => $sourceId], $request->ip);
        return Response::json(self::payload(Database::connection(), $newId, $request->auth?->can('stock.view') ?? false), 201);
    }

    public static function createOpening(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $roomId = (int) $params['id'];
        $input = $request->input();

        $id = Database::transaction(static function (PDO $pdo) use ($roomId, $input): int {
            $room = Repo::room($pdo, $roomId, true);
            $opening = self::readOpening($input, null);
            SpaceValidator::opening($room, $opening, Repo::openings($pdo, $roomId));
            $pdo->prepare('INSERT INTO room_openings (room_id, kind, name, wall_index, offset_mm, width_mm, height_mm, sill_mm) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$roomId, ...array_values($opening)]);
            return (int) $pdo->lastInsertId();
        });

        Audit::log($auth->userId, 'opening.created', 'room', $roomId, ['opening_id' => $id], $request->ip);
        return Response::json(self::payload(Database::connection(), $roomId, $request->auth?->can('stock.view') ?? false), 201);
    }

    public static function updateOpening(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];
        $input = $request->input();

        $roomId = Database::transaction(static function (PDO $pdo) use ($id, $input): int {
            $current = self::findOpening($pdo, $id);
            $room = Repo::room($pdo, (int) $current['room_id'], true);
            $opening = self::readOpening($input, $current);
            SpaceValidator::opening($room, $opening + ['id' => $id], Repo::openings($pdo, (int) $room['id']));
            $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($opening)));
            $pdo->prepare("UPDATE room_openings SET {$set} WHERE id = ?")->execute([...array_values($opening), $id]);
            return (int) $room['id'];
        });

        Audit::log($auth->userId, 'opening.updated', 'room', $roomId, ['opening_id' => $id], $request->ip);
        return Response::json(self::payload(Database::connection(), $roomId, $request->auth?->can('stock.view') ?? false));
    }

    public static function deleteOpening(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $opening = self::findOpening($pdo, $id);
        $pdo->prepare('DELETE FROM room_openings WHERE id = ?')->execute([$id]);
        Audit::log($auth->userId, 'opening.deleted', 'room', (int) $opening['room_id'], ['opening_id' => $id], $request->ip);
        return Response::json(self::payload($pdo, (int) $opening['room_id'], $request->auth?->can('stock.view') ?? false));
    }

    private const COLUMNS = [
        'name', 'label', 'description', 'display_unit', 'outline', 'height_mm', 'wall_thickness_mm',
        'door_offset_mm', 'door_width_mm', 'door_height_mm', 'floor_color', 'floor_material', 'wall_color',
    ];

    /**
     * Champs d'une salle : valeur envoyée, sinon valeur actuelle, sinon valeur par défaut
     * (un champ sans défaut est obligatoire à la création).
     *
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private static function read(Input $in, ?array $current): array
    {
        $fields = [
            'name' => [static fn () => $in->string('name', 120), null],
            'label' => [static fn () => $in->optionalString('label', 50), ''],
            'description' => [static fn () => $in->optionalString('description', 1000), ''],
            'display_unit' => [static fn () => $in->enum('display_unit', ['mm', 'cm', 'm']), 'cm'],
            'outline' => [static fn () => $in->points('outline', 3, 64, -200_000, 200_000), null],
            'height_mm' => [static fn () => $in->int('height_mm', 1000, 20_000), null],
            'wall_thickness_mm' => [static fn () => $in->int('wall_thickness_mm', 0, 1000), 100],
            'door_offset_mm' => [static fn () => $in->int('door_offset_mm', 0, 200_000), null],
            'door_width_mm' => [static fn () => $in->int('door_width_mm', 300, 10_000), null],
            'door_height_mm' => [static fn () => $in->int('door_height_mm', 500, 20_000), null],
            'floor_color' => [static fn () => $in->color('floor_color'), '#c8c2b8'],
            'floor_material' => [static fn () => $in->enum('floor_material', ['plain', 'wood', 'tiles', 'concrete']), 'plain'],
            'wall_color' => [static fn () => $in->color('wall_color'), '#eceae4'],
        ];
        $room = [];
        foreach ($fields as $key => [$read, $default]) {
            $room[$key] = match (true) {
                $in->has($key) => $read(),
                $current !== null => $current[$key],
                $default !== null => $default,
                default => $read(),
            };
        }
        // Place sur le plan : facultative. Une nouvelle salle arrive sur le premier étage, le plan la range lui-même
        $room['floor_id'] = $in->has('floor_id') ? $in->optionalInt('floor_id', null, 1) : ($current['floor_id'] ?? null);
        $room['plan_x_mm'] = $in->has('plan_x_mm') ? $in->optionalInt('plan_x_mm', null, -2_000_000, 2_000_000) : ($current['plan_x_mm'] ?? null);
        $room['plan_z_mm'] = $in->has('plan_z_mm') ? $in->optionalInt('plan_z_mm', null, -2_000_000, 2_000_000) : ($current['plan_z_mm'] ?? null);
        $room['plan_rotation_deg'] = $in->has('plan_rotation_deg') ? $in->int('plan_rotation_deg', 0, 359) : ($current['plan_rotation_deg'] ?? 0);
        return $room;
    }

    private static function assertFloorExists(PDO $pdo, ?int $floorId): void
    {
        if ($floorId === null) {
            return;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM floors WHERE id = ?');
        $stmt->execute([$floorId]);
        if ($stmt->fetchColumn() === false) {
            Input::fail('floor_id', 'Étage introuvable.');
        }
    }

    /** @param array<string, mixed>|null $current @return array<string, mixed> */
    private static function readOpening(Input $in, ?array $current): array
    {
        $fields = [
            'kind' => [static fn () => $in->enum('kind', ['door', 'window', 'opening']), null],
            'name' => [static fn () => $in->optionalString('name', 80), ''],
            'wall_index' => [static fn () => $in->int('wall_index', 0, 63), null],
            'offset_mm' => [static fn () => $in->int('offset_mm', 0, 200_000), null],
            'width_mm' => [static fn () => $in->int('width_mm', 50, 50_000), null],
            'height_mm' => [static fn () => $in->int('height_mm', 50, 20_000), null],
            'sill_mm' => [static fn () => $in->int('sill_mm', 0, 20_000), 0],
        ];
        $opening = [];
        foreach ($fields as $key => [$read, $default]) {
            $opening[$key] = match (true) {
                $in->has($key) => $read(),
                $current !== null => is_numeric($current[$key]) ? (int) $current[$key] : $current[$key],
                $default !== null => $default,
                default => $read(),
            };
        }
        return $opening;
    }

    /** @return array<string, mixed> */
    private static function findOpening(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM room_openings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new HttpException(404, 'Ouverture introuvable.');
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function toDb(array $values): array
    {
        if (isset($values['outline'])) {
            $values['outline'] = json_encode($values['outline'], JSON_THROW_ON_ERROR);
        }
        return $values;
    }

    private static function assertNameFree(PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM rooms WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('name', 'Une salle porte déjà ce nom.');
        }
    }

    private static function copyName(PDO $pdo, string $name): string
    {
        $stmt = $pdo->prepare('SELECT 1 FROM rooms WHERE name = ?');
        for ($i = 1; ; $i++) {
            $candidate = mb_substr($name, 0, 100) . ($i === 1 ? ' (copie)' : " (copie {$i})");
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }
    }

    /** @return array<string, mixed> */
    public static function payload(PDO $pdo, int $id, bool $withStock): array
    {
        $payload = [
            'room' => Repo::formatRoom(Repo::room($pdo, $id)),
            'openings' => array_map([Repo::class, 'formatOpening'], Repo::openings($pdo, $id)),
            'units' => array_map([Repo::class, 'formatUnit'], Repo::units($pdo, $id)),
        ];
        // Stock direct de chaque rangement : quantité totale et nombre d'objets (état de remplissage)
        if ($withStock) {
            $stmt = $pdo->prepare(
                'SELECT s.unit_id, SUM(s.quantity) AS quantity, COUNT(*) AS items
                 FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id
                 WHERE u.room_id = ? AND s.quantity > 0 GROUP BY s.unit_id'
            );
            $stmt->execute([$id]);
            $payload['stock'] = [];
            foreach ($stmt->fetchAll() as $row) {
                $payload['stock'][(string) $row['unit_id']] = ['quantity' => Quantity::out($row['quantity']), 'items' => (int) $row['items']];
            }
            // Objets de chaque rangement, pour les afficher dans la vue 3D
            $stmt = $pdo->prepare(
                'SELECT s.unit_id, s.item_id, s.quantity, i.name, i.unit, i.visual, i.visual_color, c.name AS category
                 FROM stock_levels s
                 JOIN storage_units u ON u.id = s.unit_id
                 JOIN items i ON i.id = s.item_id
                 LEFT JOIN categories c ON c.id = i.category_id
                 WHERE u.room_id = ? AND s.quantity > 0'
            );
            $stmt->execute([$id]);
            $payload['contents'] = array_map(static fn (array $r): array => [
                'unit_id' => (int) $r['unit_id'],
                'item_id' => (int) $r['item_id'],
                'quantity' => Quantity::out($r['quantity']),
                'name' => $r['name'],
                'unit' => $r['unit'],
                'visual' => $r['visual'],
                'visual_color' => $r['visual_color'],
                'category' => $r['category'],
            ], $stmt->fetchAll());
        }
        return $payload;
    }
}
