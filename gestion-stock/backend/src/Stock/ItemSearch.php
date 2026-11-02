<?php

declare(strict_types=1);

namespace App\Stock;

use PDO;

/**
 * Recherche d'objets en base : index FULLTEXT (mode booléen, préfixes) sur le texte agrégé,
 * complété par des LIKE sur le nom et la référence pour les termes d'une lettre et les
 * références saisies telles quelles. Pertinence : référence exacte, début du nom, score plein texte.
 */
final class ItemSearch
{
    public const SORTS = ['relevance', 'name', 'reference', 'quantity', 'updated'];

    /**
     * @param array{q?: string, category_id?: ?int, room_id?: ?int, unit_ids?: ?list<int>, low?: bool, archived?: bool} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function search(PDO $pdo, array $filters, string $sort, int $page, int $perPage): array
    {
        $where = [($filters['archived'] ?? false) ? 'i.is_active = 0' : 'i.is_active = 1'];
        $params = [];
        $scoreSql = '0';
        $scoreParams = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $tokens = array_slice(array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], static fn ($t) => $t !== '')), 0, 10);
            $long = array_values(array_filter($tokens, static fn ($t) => mb_strlen($t) >= 2));
            $short = array_values(array_filter($tokens, static fn ($t) => mb_strlen($t) < 2));

            $conditions = [];
            $conditionParams = [];
            if ($long !== []) {
                $boolean = implode(' ', array_map(static fn ($t) => "+{$t}*", $long));
                $conditions[] = 'MATCH(i.search_text) AGAINST (? IN BOOLEAN MODE)';
                $conditionParams[] = $boolean;
                $scoreSql = 'MATCH(i.search_text) AGAINST (? IN BOOLEAN MODE)';
                $scoreParams[] = $boolean;
            }
            foreach ($short as $t) {
                $conditions[] = 'i.search_text LIKE ?';
                $conditionParams[] = '%' . self::like($t) . '%';
            }
            $prefix = self::like($q) . '%';
            $where[] = '((' . ($conditions === [] ? '1 = 0' : implode(' AND ', $conditions)) . ') OR i.reference LIKE ? OR i.name LIKE ?)';
            array_push($params, ...$conditionParams);
            array_push($params, $prefix, $prefix);

            $scoreSql = "(i.reference = ?) * 1000 + (i.reference LIKE ?) * 100 + (i.name LIKE ?) * 50 + {$scoreSql}";
            $scoreParams = [$q, $prefix, $prefix, ...$scoreParams];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['room_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id WHERE s.item_id = i.id AND s.quantity > 0 AND u.room_id = ?)';
            $params[] = $filters['room_id'];
        }
        if (!empty($filters['unit_ids'])) {
            $where[] = 'EXISTS (SELECT 1 FROM stock_levels s WHERE s.item_id = i.id AND s.quantity > 0 AND s.unit_id IN (' . implode(',', array_fill(0, count($filters['unit_ids']), '?')) . '))';
            array_push($params, ...$filters['unit_ids']);
        }
        $totalSql = '(SELECT COALESCE(SUM(s.quantity), 0) FROM stock_levels s WHERE s.item_id = i.id)';
        if (!empty($filters['low'])) {
            $where[] = "i.min_quantity IS NOT NULL AND {$totalSql} < i.min_quantity";
        }
        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare("SELECT COUNT(*) FROM items i WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = match ($sort) {
            'name' => 'i.name, i.reference',
            'reference' => 'i.reference',
            'quantity' => 'total_quantity DESC, i.name',
            'updated' => 'i.updated_at DESC',
            default => $q === '' ? 'i.name, i.reference' : 'score DESC, i.name',
        };
        $stmt = $pdo->prepare(
            "SELECT i.id, i.reference, i.name, i.description, i.unit, i.min_quantity, i.is_consumable, i.photo_path, i.updated_at,
                    c.id AS category_id, c.name AS category_name, c.color AS category_color,
                    {$totalSql} AS total_quantity, {$scoreSql} AS score
             FROM items i LEFT JOIN categories c ON c.id = i.category_id
             WHERE {$whereSql} ORDER BY {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute([...$scoreParams, ...$params]);
        $rows = $stmt->fetchAll();

        $locations = self::locations($pdo, array_map(static fn ($r) => (int) $r['id'], $rows));
        return [
            'items' => array_map(static fn (array $r) => self::format($r, $locations[(int) $r['id']] ?? []), $rows),
            'total' => $total,
        ];
    }

    /**
     * Emplacements avec stock, pour chaque objet.
     *
     * @param list<int> $itemIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function locations(PDO $pdo, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT s.item_id, s.unit_id, s.quantity, u.path_text, u.room_id, u.label, u.reference
             FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id
             WHERE s.quantity > 0 AND s.item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')
             ORDER BY s.quantity DESC'
        );
        $stmt->execute($itemIds);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['item_id']][] = [
                'unit_id' => (int) $row['unit_id'],
                'room_id' => (int) $row['room_id'],
                'path' => $row['path_text'],
                'label' => $row['label'],
                'unit_reference' => $row['reference'],
                'quantity' => Quantity::out($row['quantity']),
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $r @param list<array<string, mixed>> $locations @return array<string, mixed> */
    public static function format(array $r, array $locations): array
    {
        return [
            'id' => (int) $r['id'],
            'reference' => $r['reference'],
            'name' => $r['name'],
            'description' => (string) $r['description'],
            'unit' => $r['unit'],
            'category' => $r['category_id'] === null ? null : ['id' => (int) $r['category_id'], 'name' => $r['category_name'], 'color' => $r['category_color']],
            'min_quantity' => Quantity::out($r['min_quantity']),
            'is_consumable' => (bool) $r['is_consumable'],
            'total_quantity' => Quantity::out($r['total_quantity']),
            'is_low' => $r['min_quantity'] !== null && Quantity::toMilli($r['total_quantity']) < Quantity::toMilli($r['min_quantity']),
            'photo_url' => $r['photo_path'] ? "/api/items/{$r['id']}/photo?v=" . substr(md5($r['photo_path']), 0, 8) : null,
            'locations' => $locations,
            'updated_at' => $r['updated_at'],
        ];
    }

    /** Suggestions rapides pendant la saisie. @return list<array<string, mixed>> */
    public static function suggest(PDO $pdo, string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $result = self::search($pdo, ['q' => $q], 'relevance', 1, 8);
        return array_map(static fn ($i) => [
            'id' => $i['id'], 'name' => $i['name'], 'reference' => $i['reference'],
            'total_quantity' => $i['total_quantity'], 'unit' => $i['unit'],
            'location' => $i['locations'][0]['path'] ?? null,
        ], $result['items']);
    }

    private static function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
