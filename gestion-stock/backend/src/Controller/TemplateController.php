<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\AuthContext;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use PDO;

/**
 * Modèles de rangement : un meuble ou un tiroir enregistré avec ses tiroirs et ses bacs, pour le reposer
 * ailleurs (POST /storage-units/tree avec template_id). Le stock n'en fait jamais partie.
 */
final class TemplateController
{
    public static function index(Request $request): Response
    {
        $request->requirePermission('rooms.view');
        $rows = Database::connection()->query('SELECT * FROM storage_templates ORDER BY name')->fetchAll();
        return Response::json(['templates' => array_map([self::class, 'format'], $rows)]);
    }

    public static function create(Request $request): Response
    {
        $auth = $request->user();
        self::requireEdit($auth);
        $input = $request->input();
        $name = $input->string('name', 120);
        $description = $input->optionalString('description', 1000);
        $unitId = $input->int('unit_id', 1);

        $id = Database::transaction(static function (PDO $pdo) use ($name, $description, $unitId, $auth): int {
            self::assertNameFree($pdo, $name, null);
            $tree = StorageUnitController::treeOf($pdo, $unitId);
            $pdo->prepare('INSERT INTO storage_templates (name, description, tree, node_count, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $description, json_encode($tree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), StorageUnitController::countNodes($tree), $auth->userId]);
            return (int) $pdo->lastInsertId();
        });

        Audit::log($auth->userId, 'template.created', 'storage_template', $id, ['name' => $name, 'unit_id' => $unitId], $request->ip);
        return Response::json(['template' => self::find(Database::connection(), $id)], 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->user();
        self::requireEdit($auth);
        $id = (int) $params['id'];
        $input = $request->input();

        Database::transaction(static function (PDO $pdo) use ($id, $input): void {
            $current = self::find($pdo, $id);
            $name = $input->has('name') ? $input->string('name', 120) : $current['name'];
            $description = $input->has('description') ? $input->optionalString('description', 1000) : $current['description'];
            self::assertNameFree($pdo, $name, $id);
            $pdo->prepare('UPDATE storage_templates SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
        });

        Audit::log($auth->userId, 'template.updated', 'storage_template', $id, [], $request->ip);
        return Response::json(['template' => self::find(Database::connection(), $id)]);
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->user();
        self::requireEdit($auth);
        $id = (int) $params['id'];
        $pdo = Database::connection();
        self::find($pdo, $id);
        $pdo->prepare('DELETE FROM storage_templates WHERE id = ?')->execute([$id]);
        Audit::log($auth->userId, 'template.deleted', 'storage_template', $id, [], $request->ip);
        return Response::noContent();
    }

    /** Arbre d'un modèle, pour l'instancier. @return array<string, mixed> */
    public static function tree(PDO $pdo, int $id): array
    {
        return self::find($pdo, $id)['tree'];
    }

    private static function requireEdit(AuthContext $auth): void
    {
        if (!$auth->can('furniture.edit') && !$auth->can('storage.edit')) {
            $auth->require('furniture.edit');
        }
    }

    /** @return array<string, mixed> */
    private static function find(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM storage_templates WHERE id = ?');
        $stmt->execute([$id]);
        return self::format($stmt->fetch() ?: throw new HttpException(404, 'Modèle introuvable.'));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'node_count' => (int) $row['node_count'],
            'tree' => json_decode($row['tree'], true, 64, JSON_THROW_ON_ERROR),
            'created_at' => $row['created_at'],
        ];
    }

    private static function assertNameFree(PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM storage_templates WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('name', 'Un modèle porte déjà ce nom.');
        }
    }
}
