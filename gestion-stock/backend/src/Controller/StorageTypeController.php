<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use PDO;

/** Types de rangement : types fournis (forme figée) et types personnalisés. */
final class StorageTypeController
{
    private const SHAPES = ['cabinet', 'compartment', 'cell', 'drawer', 'box', 'bin', 'shelf', 'divider'];

    public static function index(Request $request): Response
    {
        $request->requirePermission('rooms.view');
        $rows = Database::connection()->query(
            'SELECT t.*, (SELECT COUNT(*) FROM storage_units u WHERE u.type_id = t.id) AS usage_count
             FROM storage_types t ORDER BY t.is_builtin DESC, t.name'
        )->fetchAll();
        return Response::json(['types' => array_map(self::format(...), $rows)]);
    }

    public static function create(Request $request): Response
    {
        $auth = $request->requirePermission('storage.edit');
        $type = self::read($request->input(), null);
        $pdo = Database::connection();
        self::assertNameFree($pdo, $type['name'], null);
        $columns = array_keys($type);
        $pdo->prepare('INSERT INTO storage_types (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($type));
        $id = (int) $pdo->lastInsertId();
        Audit::log($auth->userId, 'storage_type.created', 'storage_type', $id, $type, $request->ip);
        return Response::json(['type' => self::find($pdo, $id)], 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('storage.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $current = self::find($pdo, $id);
        $input = $request->input();
        if ($current['is_builtin'] && $input->has('shape') && $input->enum('shape', self::SHAPES) !== $current['shape']) {
            throw new HttpException(409, "La forme d'un type fourni ne peut pas être modifiée.");
        }
        if ($current['usage_count'] > 0 && $input->has('shape') && $input->enum('shape', self::SHAPES) !== $current['shape']) {
            throw new HttpException(409, 'La forme ne peut pas être modifiée : ce type est déjà utilisé.');
        }
        $type = self::read($input, $current);
        self::assertNameFree($pdo, $type['name'], $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($type)));
        $pdo->prepare("UPDATE storage_types SET {$set} WHERE id = ?")->execute([...array_values($type), $id]);
        Audit::log($auth->userId, 'storage_type.updated', 'storage_type', $id, $type, $request->ip);
        return Response::json(['type' => self::find($pdo, $id)]);
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('storage.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $type = self::find($pdo, $id);
        if ($type['is_builtin']) {
            throw new HttpException(409, 'Les types fournis ne peuvent pas être supprimés.');
        }
        if ($type['usage_count'] > 0) {
            throw new HttpException(409, "Ce type est utilisé par {$type['usage_count']} rangement(s).");
        }
        $pdo->prepare('DELETE FROM storage_types WHERE id = ?')->execute([$id]);
        Audit::log($auth->userId, 'storage_type.deleted', 'storage_type', $id, ['name' => $type['name']], $request->ip);
        return Response::noContent();
    }

    /** @param array<string, mixed>|null $current @return array<string, mixed> */
    private static function read(Input $in, ?array $current): array
    {
        $fields = [
            'name' => [static fn () => $in->string('name', 80), null],
            'shape' => [static fn () => $in->enum('shape', self::SHAPES), null],
            'can_contain_units' => [static fn () => (int) $in->bool('can_contain_units'), 1],
            'can_hold_items' => [static fn () => (int) $in->bool('can_hold_items'), 1],
            'default_width_mm' => [static fn () => $in->int('default_width_mm', 1, 50_000), null],
            'default_depth_mm' => [static fn () => $in->int('default_depth_mm', 1, 50_000), null],
            'default_height_mm' => [static fn () => $in->int('default_height_mm', 1, 50_000), null],
            'default_panel_mm' => [static fn () => $in->int('default_panel_mm', 0, 200), 0],
            'default_color' => [static fn () => $in->color('default_color'), '#b08d5c'],
        ];
        $type = [];
        foreach ($fields as $key => [$read, $default]) {
            $type[$key] = match (true) {
                $in->has($key) => $read(),
                $current !== null => is_bool($current[$key]) ? (int) $current[$key] : $current[$key],
                $default !== null => $default,
                default => $read(),
            };
        }
        if ($type['shape'] === 'divider') {
            $type['can_contain_units'] = 0;
            $type['can_hold_items'] = 0;
        }
        return $type;
    }

    private static function assertNameFree(PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM storage_types WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('name', 'Un type porte déjà ce nom.');
        }
    }

    /** @return array<string, mixed> */
    private static function find(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM storage_units u WHERE u.type_id = t.id) AS usage_count FROM storage_types t WHERE t.id = ?');
        $stmt->execute([$id]);
        return self::format($stmt->fetch() ?: throw new HttpException(404, 'Type introuvable.'));
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private static function format(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'name' => $t['name'],
            'shape' => $t['shape'],
            'is_builtin' => (bool) $t['is_builtin'],
            'can_contain_units' => (bool) $t['can_contain_units'],
            'can_hold_items' => (bool) $t['can_hold_items'],
            'default_width_mm' => (int) $t['default_width_mm'],
            'default_depth_mm' => (int) $t['default_depth_mm'],
            'default_height_mm' => (int) $t['default_height_mm'],
            'default_panel_mm' => (int) $t['default_panel_mm'],
            'default_color' => $t['default_color'],
            'usage_count' => (int) $t['usage_count'],
        ];
    }
}
