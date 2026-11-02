<?php

declare(strict_types=1);

namespace App\Stock;

use PDO;

/**
 * Tient à jour items.search_text : nom, référence, description, catégorie, champs personnalisés
 * recherchables, et pour chaque emplacement le chemin complet, les étiquettes et références
 * des rangements traversés et l'étiquette de la salle.
 */
final class ItemIndex
{
    /** @param list<int> $itemIds */
    public static function reindex(PDO $pdo, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return;
        }
        foreach (array_chunk($itemIds, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));

            $items = $pdo->prepare(
                "SELECT i.id, i.name, i.reference, i.description, c.name AS category
                 FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id IN ({$in})"
            );
            $items->execute($chunk);
            $parts = [];
            foreach ($items->fetchAll() as $row) {
                $parts[(int) $row['id']] = [
                    $row['name'], $row['reference'], str_replace(['-', '_', '.', '/'], ' ', $row['reference']),
                    (string) $row['category'], mb_substr((string) $row['description'], 0, 2000),
                ];
            }

            $values = $pdo->prepare(
                "SELECT v.item_id, v.value, f.field_type FROM item_field_values v
                 JOIN custom_fields f ON f.id = v.field_id AND f.is_searchable = 1 WHERE v.item_id IN ({$in})"
            );
            $values->execute($chunk);
            foreach ($values->fetchAll() as $row) {
                if ($row['field_type'] !== 'boolean') {
                    $parts[(int) $row['item_id']][] = $row['value'];
                }
            }

            $locations = $pdo->prepare(
                "SELECT s.item_id, u.id AS unit_id, u.path_text, r.label AS room_label
                 FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id JOIN rooms r ON r.id = u.room_id
                 WHERE s.quantity > 0 AND s.item_id IN ({$in})"
            );
            $locations->execute($chunk);
            $rows = $locations->fetchAll();
            $labels = self::ancestorLabels($pdo, array_map(static fn ($r) => (int) $r['unit_id'], $rows));
            foreach ($rows as $row) {
                $parts[(int) $row['item_id']][] = $row['path_text'];
                $parts[(int) $row['item_id']][] = $row['room_label'];
                $parts[(int) $row['item_id']][] = $labels[(int) $row['unit_id']] ?? '';
            }

            $update = $pdo->prepare('UPDATE items SET search_text = ? WHERE id = ?');
            foreach ($parts as $id => $words) {
                $text = implode(' ', array_filter(array_unique($words), static fn ($w) => $w !== null && $w !== ''));
                $update->execute([mb_substr($text, 0, 60000), $id]);
            }
        }
    }

    /** Réindexe les objets stockés dans une salle (après renommage d'un rangement ou de la salle). */
    public static function reindexRoom(PDO $pdo, int $roomId): void
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT s.item_id FROM stock_levels s JOIN storage_units u ON u.id = s.unit_id WHERE u.room_id = ?'
        );
        $stmt->execute([$roomId]);
        self::reindex($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Étiquettes et références de chaque rangement et de ses parents.
     *
     * @param list<int> $unitIds
     * @return array<int, string>
     */
    private static function ancestorLabels(PDO $pdo, array $unitIds): array
    {
        $units = [];
        $pending = array_values(array_unique($unitIds));
        while ($pending !== []) {
            $stmt = $pdo->prepare('SELECT id, parent_id, label, reference FROM storage_units WHERE id IN (' . implode(',', array_fill(0, count($pending), '?')) . ')');
            $stmt->execute($pending);
            $pending = [];
            foreach ($stmt->fetchAll() as $row) {
                $units[(int) $row['id']] = $row;
                if ($row['parent_id'] !== null && !isset($units[(int) $row['parent_id']])) {
                    $pending[] = (int) $row['parent_id'];
                }
            }
            $pending = array_values(array_unique($pending));
        }
        $result = [];
        foreach (array_unique($unitIds) as $id) {
            $words = [];
            for ($u = $units[$id] ?? null; $u !== null; $u = $u['parent_id'] === null ? null : ($units[(int) $u['parent_id']] ?? null)) {
                $words[] = $u['label'];
                $words[] = (string) $u['reference'];
            }
            $result[$id] = implode(' ', array_filter($words));
        }
        return $result;
    }
}
