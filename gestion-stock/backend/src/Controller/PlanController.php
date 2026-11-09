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
use PDO;

/** Plan du bâtiment : étages, salles placées sur leur étage, portes et meubles de chaque salle. */
final class PlanController
{
    public static function show(Request $request): Response
    {
        $request->requirePermission('rooms.view');
        $pdo = Database::connection();

        $rooms = array_map(static function (array $row): array {
            $row['outline'] = json_decode($row['outline'], true);
            return Repo::formatRoom($row);
        }, $pdo->query('SELECT * FROM rooms ORDER BY name')->fetchAll());

        $openings = array_map([Repo::class, 'formatOpening'], $pdo->query('SELECT * FROM room_openings ORDER BY room_id, wall_index, offset_mm')->fetchAll());

        $units = array_map([Repo::class, 'formatUnit'], $pdo->query(
            'SELECT u.*, t.shape, t.can_contain_units, t.can_hold_items, t.name AS type_name
             FROM storage_units u JOIN storage_types t ON t.id = u.type_id
             WHERE u.parent_id IS NULL ORDER BY u.room_id, u.sort_order, u.id'
        )->fetchAll());

        return Response::json(['floors' => self::floors($pdo), 'rooms' => $rooms, 'openings' => $openings, 'units' => $units]);
    }

    public static function createFloor(Request $request): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $input = $request->input();
        $name = $input->string('name', 80);
        $height = $input->optionalInt('height_mm', 3000, 1000, 20_000);

        $id = Database::transaction(static function (PDO $pdo) use ($input, $name, $height): int {
            self::assertFloorFree($pdo, $name, null);
            $level = $input->has('level')
                ? $input->int('level', -20, 200)
                : (int) $pdo->query('SELECT COALESCE(MAX(level_no), -1) + 1 FROM floors')->fetchColumn();
            self::assertLevelFree($pdo, $level, null);
            $pdo->prepare('INSERT INTO floors (name, level_no, height_mm) VALUES (?, ?, ?)')->execute([$name, $level, $height]);
            return (int) $pdo->lastInsertId();
        });

        Audit::log($auth->userId, 'floor.created', 'floor', $id, ['name' => $name], $request->ip);
        return Response::json(['floor' => self::floor(Database::connection(), $id), 'floors' => self::floors(Database::connection())], 201);
    }

    public static function updateFloor(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];
        $input = $request->input();

        Database::transaction(static function (PDO $pdo) use ($id, $input): void {
            $current = self::floor($pdo, $id);
            $name = $input->has('name') ? $input->string('name', 80) : $current['name'];
            $level = $input->has('level') ? $input->int('level', -20, 200) : $current['level'];
            $height = $input->has('height_mm') ? $input->int('height_mm', 1000, 20_000) : $current['height_mm'];
            self::assertFloorFree($pdo, $name, $id);
            self::assertLevelFree($pdo, $level, $id);
            $pdo->prepare('UPDATE floors SET name = ?, level_no = ?, height_mm = ? WHERE id = ?')->execute([$name, $level, $height, $id]);
        });

        Audit::log($auth->userId, 'floor.updated', 'floor', $id, [], $request->ip);
        return Response::json(['floor' => self::floor(Database::connection(), $id), 'floors' => self::floors(Database::connection())]);
    }

    public static function deleteFloor(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('rooms.edit');
        $id = (int) $params['id'];

        Database::transaction(static function (PDO $pdo) use ($id): void {
            self::floor($pdo, $id);
            if ((int) $pdo->query('SELECT COUNT(*) FROM floors')->fetchColumn() <= 1) {
                throw new HttpException(409, 'Le bâtiment doit garder au moins un étage.');
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE floor_id = ?');
            $count->execute([$id]);
            if (($rooms = (int) $count->fetchColumn()) > 0) {
                throw new HttpException(409, "Cet étage contient encore {$rooms} salle(s). Déplacez-les vers un autre étage avant de le supprimer.");
            }
            $pdo->prepare('DELETE FROM floors WHERE id = ?')->execute([$id]);
        });

        Audit::log($auth->userId, 'floor.deleted', 'floor', $id, [], $request->ip);
        return Response::json(['floors' => self::floors(Database::connection())]);
    }

    /** @return list<array<string, mixed>> */
    private static function floors(PDO $pdo): array
    {
        return array_map([self::class, 'format'], $pdo->query('SELECT * FROM floors ORDER BY level_no')->fetchAll());
    }

    /** @return array<string, mixed> */
    private static function floor(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM floors WHERE id = ?');
        $stmt->execute([$id]);
        return self::format($stmt->fetch() ?: throw new HttpException(404, 'Étage introuvable.'));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function format(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'level' => (int) $row['level_no'], 'height_mm' => (int) $row['height_mm']];
    }

    private static function assertFloorFree(PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM floors WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('name', 'Un étage porte déjà ce nom.');
        }
    }

    private static function assertLevelFree(PDO $pdo, int $level, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM floors WHERE level_no = ? AND id <> ?');
        $stmt->execute([$level, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('level', 'Un autre étage occupe déjà ce niveau.');
        }
    }
}
