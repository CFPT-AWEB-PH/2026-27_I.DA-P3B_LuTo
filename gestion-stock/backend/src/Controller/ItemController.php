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
use App\Stock\ItemSearch;
use App\Stock\Quantity;
use App\Stock\StockService;
use PDO;

/** Catalogue des objets : recherche, fiche, création, modification, archivage, photo. */
final class ItemController
{
    private const PHOTO_DIR = '/app/storage/uploads/items';
    private const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
    private const PHOTO_MAX_SIDE = 1200;

    public static function index(Request $request): Response
    {
        $request->requirePermission('stock.view');
        $query = $request->query;
        $int = static fn (string $key): ?int => isset($query[$key]) && ctype_digit((string) $query[$key]) ? (int) $query[$key] : null;
        $pdo = Database::connection();

        $unitIds = null;
        if (($unitId = $int('unit_id')) !== null) {
            $unitIds = [$unitId, ...self::descendants($pdo, $unitId)];
        }
        $sort = in_array($query['sort'] ?? '', ItemSearch::SORTS, true) ? $query['sort'] : 'relevance';
        $page = max(1, $int('page') ?? 1);
        $perPage = min(100, max(1, $int('per_page') ?? 25));

        $result = ItemSearch::search($pdo, [
            'q' => mb_substr((string) ($query['q'] ?? ''), 0, 200),
            'category_id' => $int('category_id'),
            'room_id' => $int('room_id'),
            'unit_ids' => $unitIds,
            'low' => ($query['low'] ?? '') === '1',
            'archived' => ($query['archived'] ?? '') === '1',
        ], $sort, $page, $perPage);

        return Response::json($result + ['page' => $page, 'per_page' => $perPage]);
    }

    public static function suggest(Request $request): Response
    {
        $request->requirePermission('stock.view');
        return Response::json(['suggestions' => ItemSearch::suggest(Database::connection(), mb_substr((string) ($request->query['q'] ?? ''), 0, 100))]);
    }

    public static function show(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('stock.view');
        $pdo = Database::connection();
        $id = (int) $params['id'];
        $item = self::item($pdo, $id);

        $fields = $pdo->prepare(
            'SELECT f.id, f.name, f.field_type, f.options, v.value FROM custom_fields f
             LEFT JOIN item_field_values v ON v.field_id = f.id AND v.item_id = ?
             WHERE f.category_id IS NULL OR f.category_id = ? ORDER BY f.sort_order, f.name'
        );
        $fields->execute([$id, $item['category']['id'] ?? 0]);

        $loanSql = 'SELECT l.id, l.quantity, l.quantity_returned, l.status, l.taken_at, l.closed_at, l.origin_path, l.origin_unit_id, l.comment,
                           u.id AS user_id, u.email, u.display_name
                    FROM loans l JOIN users u ON u.id = l.user_id WHERE l.item_id = ? AND l.status = \'open\'';
        $loanParams = [$id];
        if (!$auth->can('history.view')) {
            $loanSql .= ' AND l.user_id = ?';
            $loanParams[] = $auth->userId;
        }
        $loans = $pdo->prepare($loanSql . ' ORDER BY l.taken_at');
        $loans->execute($loanParams);

        return Response::json([
            'item' => $item,
            'fields' => array_map(static fn ($f) => [
                'id' => (int) $f['id'], 'name' => $f['name'], 'type' => $f['field_type'],
                'options' => $f['options'] === null ? null : json_decode($f['options'], true), 'value' => $f['value'],
            ], $fields->fetchAll()),
            'open_loans' => array_map([StockController::class, 'formatLoan'], $loans->fetchAll()),
        ]);
    }

    public static function create(Request $request): Response
    {
        $auth = $request->requirePermission('items.edit');
        $input = $request->input();
        $item = self::read($input, null);
        $initial = null;
        if ($input->has('initial_stock') && $input->has('unit_id')) {
            $auth->require('stock.add');
            $initial = ['unit_id' => $input->int('unit_id', 1), 'quantity' => $input->quantity('initial_stock')];
        }
        $values = $input->has('fields') ? self::readFieldValues($request->json()['fields'], $item['category_id']) : [];

        $id = Database::transaction(static function (PDO $pdo) use ($item, $values, $auth): int {
            self::assertReferenceFree($pdo, $item['reference'], null);
            self::assertRequiredFields($pdo, $item['category_id'], $values);
            $columns = array_keys($item);
            $pdo->prepare('INSERT INTO items (' . implode(', ', $columns) . ', search_text, created_by) VALUES ('
                . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')')
                ->execute([...array_values($item), '', $auth->userId]);
            $id = (int) $pdo->lastInsertId();
            self::saveFieldValues($pdo, $id, $values);
            ItemIndex::reindex($pdo, [$id]);
            return $id;
        });
        Audit::log($auth->userId, 'item.created', 'item', $id, ['reference' => $item['reference']], $request->ip);

        if ($initial !== null) {
            StockService::add($id, $initial['unit_id'], $initial['quantity'], $auth->userId, 'Stock initial');
        }
        return Response::json(['item' => self::item(Database::connection(), $id)], 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $input = $request->input();

        $changes = Database::transaction(static function (PDO $pdo) use ($id, $input, $request): array {
            $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetch() ?: throw new HttpException(404, 'Objet introuvable.');
            $item = self::read($input, $current);
            self::assertReferenceFree($pdo, $item['reference'], $id);
            $changes = array_filter($item, static fn ($v, $k) => (string) $v !== (string) $current[$k], ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($changes)));
                $pdo->prepare("UPDATE items SET {$set} WHERE id = ?")->execute([...array_values($changes), $id]);
            }
            if ($input->has('fields')) {
                $values = self::readFieldValues($request->json()['fields'], $item['category_id']);
                self::assertRequiredFields($pdo, $item['category_id'], $values);
                $pdo->prepare('DELETE FROM item_field_values WHERE item_id = ?')->execute([$id]);
                self::saveFieldValues($pdo, $id, $values);
                $changes['fields'] = $values;
            }
            ItemIndex::reindex($pdo, [$id]);
            return $changes;
        });

        if ($changes !== []) {
            Audit::log($auth->userId, 'item.updated', 'item', $id, $changes, $request->ip);
        }
        return Response::json(['item' => self::item(Database::connection(), $id)]);
    }

    /**
     * Supprime un objet sans historique ; sinon l'archive (l'historique doit rester consultable).
     * Refusé tant qu'il reste du stock ou des emprunts ouverts.
     */
    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $archived = Database::transaction(static function (PDO $pdo) use ($id): bool {
            $item = self::item($pdo, $id);
            if (Quantity::toMilli((string) $item['total_quantity']) > 0) {
                throw new HttpException(409, 'Cet objet est encore en stock (' . Quantity::format((string) $item['total_quantity']) . " {$item['unit']}). Corrigez le stock à zéro avant de le supprimer.");
            }
            $loans = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE item_id = ? AND status = 'open'");
            $loans->execute([$id]);
            if ((int) $loans->fetchColumn() > 0) {
                throw new HttpException(409, 'Des emprunts de cet objet sont encore en cours.');
            }
            $history = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE item_id = ?');
            $history->execute([$id]);
            if ((int) $history->fetchColumn() > 0) {
                $pdo->prepare('UPDATE items SET is_active = 0 WHERE id = ?')->execute([$id]);
                return true;
            }
            $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
            return false;
        });
        Audit::log($auth->userId, $archived ? 'item.archived' : 'item.deleted', 'item', $id, [], $request->ip);
        return Response::json(['archived' => $archived]);
    }

    public static function restore(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        Database::connection()->prepare('UPDATE items SET is_active = 1 WHERE id = ?')->execute([$id]);
        Audit::log($auth->userId, 'item.restored', 'item', $id, [], $request->ip);
        return Response::json(['item' => self::item(Database::connection(), $id)]);
    }

    /** Photo : décodée puis réencodée en WebP (supprime métadonnées et contenu malveillant éventuel). */
    public static function uploadPhoto(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        self::item($pdo, $id);

        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $tooBig = in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            Input::fail('photo', $tooBig ? 'Photo trop volumineuse (5 Mo maximum).' : 'Aucune photo reçue.');
        }
        if ($file['size'] > self::PHOTO_MAX_BYTES) {
            Input::fail('photo', 'Photo trop volumineuse (5 Mo maximum).');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            Input::fail('photo', 'Format accepté : JPEG, PNG ou WebP.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            Input::fail('photo', 'Image trop grande (40 mégapixels maximum).');
        }
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            default => @imagecreatefromwebp($file['tmp_name']),
        };
        if ($source === false) {
            Input::fail('photo', 'Image illisible ou corrompue.');
        }
        $scale = min(1, self::PHOTO_MAX_SIDE / max($info[0], $info[1]));
        $image = imagescale($source, max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
        if (!is_dir(self::PHOTO_DIR)) {
            mkdir(self::PHOTO_DIR, 0750, true);
        }
        $name = "{$id}_" . bin2hex(random_bytes(8)) . '.webp';
        if (!imagewebp($image, self::PHOTO_DIR . "/{$name}", 82)) {
            throw new \RuntimeException('Échec de l\'enregistrement de la photo.');
        }

        $old = $pdo->prepare('SELECT photo_path FROM items WHERE id = ?');
        $old->execute([$id]);
        $previous = $old->fetchColumn();
        $pdo->prepare('UPDATE items SET photo_path = ? WHERE id = ?')->execute([$name, $id]);
        if (is_string($previous) && $previous !== '' && preg_match('/^[\w.]+$/', $previous)) {
            @unlink(self::PHOTO_DIR . "/{$previous}");
        }
        Audit::log($auth->userId, 'item.photo_updated', 'item', $id, [], $request->ip);
        return Response::json(['item' => self::item($pdo, $id)]);
    }

    public static function deletePhoto(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('items.edit');
        $id = (int) $params['id'];
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT photo_path FROM items WHERE id = ?');
        $stmt->execute([$id]);
        $path = $stmt->fetchColumn();
        if (is_string($path) && preg_match('/^[\w.]+$/', $path)) {
            @unlink(self::PHOTO_DIR . "/{$path}");
        }
        $pdo->prepare('UPDATE items SET photo_path = NULL WHERE id = ?')->execute([$id]);
        Audit::log($auth->userId, 'item.photo_deleted', 'item', $id, [], $request->ip);
        return Response::noContent();
    }

    public static function photo(Request $request, array $params): Response
    {
        $request->requirePermission('stock.view');
        $stmt = Database::connection()->prepare('SELECT photo_path FROM items WHERE id = ?');
        $stmt->execute([(int) $params['id']]);
        $path = $stmt->fetchColumn();
        if (!is_string($path) || !preg_match('/^[\w.]+$/', $path) || !is_file(self::PHOTO_DIR . "/{$path}")) {
            throw new HttpException(404, 'Aucune photo.');
        }
        return Response::binary((string) file_get_contents(self::PHOTO_DIR . "/{$path}"), 'image/webp');
    }

    /** @return array<string, mixed> */
    public static function item(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.*, c.id AS category_id, c.name AS category_name, c.color AS category_color,
                    (SELECT COALESCE(SUM(s.quantity), 0) FROM stock_levels s WHERE s.item_id = i.id) AS total_quantity
             FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: throw new HttpException(404, 'Objet introuvable.');
        $loans = $pdo->prepare("SELECT COALESCE(SUM(quantity - quantity_returned), 0) FROM loans WHERE item_id = ? AND status = 'open'");
        $loans->execute([$id]);
        return ItemSearch::format($row, ItemSearch::locations($pdo, [$id])[$id] ?? []) + [
            'is_active' => (bool) $row['is_active'],
            'on_loan' => Quantity::out($loans->fetchColumn()),
            'created_at' => $row['created_at'],
        ];
    }

    /** @param array<string, mixed>|null $current @return array<string, mixed> */
    private static function read(Input $in, ?array $current): array
    {
        $fields = [
            'reference' => [static fn () => $in->string('reference', 60), null],
            'name' => [static fn () => $in->string('name', 160), null],
            'description' => [static fn () => $in->optionalString('description', 5000), ''],
            'category_id' => [static fn () => $in->optionalInt('category_id', null, 1), false],
            'unit' => [static fn () => $in->string('unit', 20), 'pièce'],
            'min_quantity' => [static fn () => $in->optionalQuantity('min_quantity', true), false],
            'is_consumable' => [static fn () => (int) $in->bool('is_consumable'), 0],
        ];
        $item = [];
        foreach ($fields as $key => [$read, $default]) {
            $item[$key] = match (true) {
                $in->has($key) => $read(),
                $current !== null => $current[$key],
                $default === false => null,
                $default !== null => $default,
                default => $read(),
            };
        }
        if ($item['category_id'] !== null) {
            $stmt = Database::connection()->prepare('SELECT 1 FROM categories WHERE id = ?');
            $stmt->execute([$item['category_id']]);
            if ($stmt->fetchColumn() === false) {
                Input::fail('category_id', 'Catégorie introuvable.');
            }
        }
        return $item;
    }

    /**
     * Valeurs des champs personnalisés applicables (globaux ou de la catégorie), validées selon leur type.
     *
     * @return array<int, string>
     */
    private static function readFieldValues(mixed $raw, ?int $categoryId): array
    {
        if (!is_array($raw)) {
            Input::fail('fields', 'Objet { id du champ : valeur } attendu.');
        }
        $stmt = Database::connection()->prepare('SELECT * FROM custom_fields WHERE category_id IS NULL OR category_id = ?');
        $stmt->execute([$categoryId ?? 0]);
        $defs = [];
        foreach ($stmt->fetchAll() as $f) {
            $defs[(int) $f['id']] = $f;
        }
        $values = [];
        foreach ($raw as $fieldId => $value) {
            $def = $defs[(int) $fieldId] ?? null;
            if ($def === null) {
                continue; // champ d'une autre catégorie : ignoré
            }
            if ($value === null || $value === '') {
                continue;
            }
            $key = "field_{$fieldId}";
            $text = is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? trim((string) $value) : Input::fail($key, 'Valeur invalide.'));
            $ok = match ($def['field_type']) {
                'number' => is_numeric($text),
                'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) && checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4)),
                'boolean' => in_array($text, ['0', '1'], true),
                'select' => in_array($text, json_decode((string) $def['options'], true) ?: [], true),
                default => mb_strlen($text) <= 500,
            };
            if (!$ok) {
                Input::fail($key, "Valeur invalide pour « {$def['name']} ».");
            }
            $values[(int) $fieldId] = $text;
        }
        return $values;
    }

    /** @param array<int, string> $values */
    private static function assertRequiredFields(PDO $pdo, ?int $categoryId, array $values): void
    {
        $stmt = $pdo->prepare('SELECT id, name FROM custom_fields WHERE is_required = 1 AND (category_id IS NULL OR category_id = ?)');
        $stmt->execute([$categoryId ?? 0]);
        foreach ($stmt->fetchAll() as $f) {
            if (!isset($values[(int) $f['id']])) {
                Input::fail("field_{$f['id']}", "Le champ « {$f['name']} » est obligatoire.");
            }
        }
    }

    /** @param array<int, string> $values */
    private static function saveFieldValues(PDO $pdo, int $itemId, array $values): void
    {
        $insert = $pdo->prepare('INSERT INTO item_field_values (item_id, field_id, value) VALUES (?, ?, ?)');
        foreach ($values as $fieldId => $value) {
            $insert->execute([$itemId, $fieldId, $value]);
        }
    }

    private static function assertReferenceFree(PDO $pdo, string $reference, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT name FROM items WHERE reference = ? AND id <> ?');
        $stmt->execute([$reference, $exceptId ?? 0]);
        if (($name = $stmt->fetchColumn()) !== false) {
            Input::fail('reference', "Référence déjà utilisée par « {$name} ».");
        }
    }

    /** @return list<int> */
    public static function descendants(PDO $pdo, int $unitId): array
    {
        $ids = [];
        $frontier = [$unitId];
        while ($frontier !== []) {
            $stmt = $pdo->prepare('SELECT id FROM storage_units WHERE parent_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')');
            $stmt->execute($frontier);
            $frontier = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            array_push($ids, ...$frontier);
        }
        return $ids;
    }
}
