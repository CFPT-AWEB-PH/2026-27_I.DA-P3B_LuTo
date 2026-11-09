<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use App\Stock\ItemIndex;
use PDO;

/** Catégories et champs personnalisés du catalogue. */
final class CatalogController
{
    private const FIELD_TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    public static function categories(Request $request): Response
    {
        $request->requirePermission('stock.view');
        $rows = Database::connection()->query(
            'SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id AND i.is_active = 1) AS item_count FROM categories c ORDER BY c.name'
        )->fetchAll();
        return Response::json(['categories' => array_map(static fn ($c) => [
            'id' => (int) $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'color' => $c['color'], 'item_count' => (int) $c['item_count'],
        ], $rows)]);
    }

    public static function saveCategory(Request $request, array $params = []): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $in = $request->input();
        $pdo = Database::connection();
        $current = null;
        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
            $stmt->execute([$id]);
            $current = $stmt->fetch() ?: throw new HttpException(404, 'Catégorie introuvable.');
        }
        $data = [
            'name' => $in->has('name') || $current === null ? $in->string('name', 80) : $current['name'],
            'description' => $in->has('description') ? $in->optionalString('description', 255) : ($current['description'] ?? ''),
            'color' => $in->has('color') ? $in->color('color') : ($current['color'] ?? '#6b7280'),
        ];
        $dup = $pdo->prepare('SELECT 1 FROM categories WHERE name = ? AND id <> ?');
        $dup->execute([$data['name'], $id ?? 0]);
        if ($dup->fetchColumn() !== false) {
            Input::fail('name', 'Une catégorie porte déjà ce nom.');
        }
        if ($id === null) {
            $pdo->prepare('INSERT INTO categories (name, description, color) VALUES (?, ?, ?)')->execute(array_values($data));
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ?')->execute([...array_values($data), $id]);
            if ($current['name'] !== $data['name']) {
                self::reindexCategory($pdo, $id);
            }
        }
        Audit::log($auth->userId, $current === null ? 'category.created' : 'category.updated', 'category', $id, $data, $request->ip);
        return Response::json(['category' => ['id' => $id] + $data], $current === null ? 201 : 200);
    }

    public static function deleteCategory(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $items = $pdo->prepare('SELECT id FROM items WHERE category_id = ?');
        $items->execute([$id]);
        $itemIds = array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        ItemIndex::reindex($pdo, $itemIds);
        Audit::log($auth->userId, 'category.deleted', 'category', $id, ['items_uncategorized' => count($itemIds)], $request->ip);
        return Response::noContent();
    }

    public static function fields(Request $request): Response
    {
        $request->requirePermission('stock.view');
        $rows = Database::connection()->query(
            'SELECT f.*, c.name AS category_name FROM custom_fields f LEFT JOIN categories c ON c.id = f.category_id ORDER BY f.sort_order, f.name'
        )->fetchAll();
        return Response::json(['fields' => array_map(self::formatField(...), $rows)]);
    }

    public static function saveField(Request $request, array $params = []): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $in = $request->input();
        $pdo = Database::connection();
        $current = null;
        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT * FROM custom_fields WHERE id = ?');
            $stmt->execute([$id]);
            $current = $stmt->fetch() ?: throw new HttpException(404, 'Champ introuvable.');
        }
        $type = $in->has('field_type') || $current === null ? $in->enum('field_type', self::FIELD_TYPES) : $current['field_type'];
        if ($current !== null && $type !== $current['field_type']) {
            throw new HttpException(409, "Le type d'un champ existant ne peut pas être modifié.");
        }
        $options = null;
        if ($type === 'select') {
            $options = $in->has('options') ? $in->stringList('options', 50, 100) : json_decode((string) ($current['options'] ?? '[]'), true);
            if ($options === []) {
                Input::fail('options', 'Indiquez au moins une option.');
            }
        }
        $data = [
            'name' => $in->has('name') || $current === null ? $in->string('name', 80) : $current['name'],
            'field_type' => $type,
            'options' => $options === null ? null : json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
            'category_id' => $in->has('category_id') ? $in->optionalInt('category_id', null, 1) : ($current === null ? null : ($current['category_id'] === null ? null : (int) $current['category_id'])),
            'is_required' => $in->has('is_required') ? (int) $in->bool('is_required') : (int) ($current['is_required'] ?? 0),
            'is_searchable' => $in->has('is_searchable') ? (int) $in->bool('is_searchable') : (int) ($current['is_searchable'] ?? 1),
            'sort_order' => $in->has('sort_order') ? $in->int('sort_order', -1000, 1000) : (int) ($current['sort_order'] ?? 0),
        ];
        $dup = $pdo->prepare('SELECT 1 FROM custom_fields WHERE name = ? AND category_id <=> ? AND id <> ?');
        $dup->execute([$data['name'], $data['category_id'], $id ?? 0]);
        if ($dup->fetchColumn() !== false) {
            Input::fail('name', 'Un champ porte déjà ce nom.');
        }
        if ($id === null) {
            $columns = array_keys($data);
            $pdo->prepare('INSERT INTO custom_fields (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($data));
            $id = (int) $pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($data)));
            $pdo->prepare("UPDATE custom_fields SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
            $items = $pdo->prepare('SELECT item_id FROM item_field_values WHERE field_id = ?');
            $items->execute([$id]);
            ItemIndex::reindex($pdo, array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN)));
        }
        Audit::log($auth->userId, $current === null ? 'field.created' : 'field.updated', 'custom_field', $id, $data, $request->ip);
        $stmt = $pdo->prepare('SELECT f.*, c.name AS category_name FROM custom_fields f LEFT JOIN categories c ON c.id = f.category_id WHERE f.id = ?');
        $stmt->execute([$id]);
        return Response::json(['field' => self::formatField($stmt->fetch())], $current === null ? 201 : 200);
    }

    public static function deleteField(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $items = $pdo->prepare('SELECT item_id FROM item_field_values WHERE field_id = ?');
        $items->execute([$id]);
        $itemIds = array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM custom_fields WHERE id = ?')->execute([$id]);
        ItemIndex::reindex($pdo, $itemIds);
        Audit::log($auth->userId, 'field.deleted', 'custom_field', $id, ['values_deleted' => count($itemIds)], $request->ip);
        return Response::noContent();
    }

    private static function reindexCategory(PDO $pdo, int $categoryId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        ItemIndex::reindex($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private static function formatField(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'name' => $f['name'],
            'field_type' => $f['field_type'],
            'options' => $f['options'] === null ? null : json_decode($f['options'], true),
            'category_id' => $f['category_id'] === null ? null : (int) $f['category_id'],
            'category_name' => $f['category_name'],
            'is_required' => (bool) $f['is_required'],
            'is_searchable' => (bool) $f['is_searchable'],
            'sort_order' => (int) $f['sort_order'],
        ];
    }
}
