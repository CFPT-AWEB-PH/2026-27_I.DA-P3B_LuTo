<?php

declare(strict_types=1);

namespace App\Stock;

use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use PDO;

/**
 * Opérations de stock. Chacune s'exécute dans une transaction qui verrouille d'abord la ligne
 * de l'objet (sérialisation de toutes les opérations sur cet objet, sans interblocage), puis
 * modifie les quantités par des mises à jour conditionnelles : le stock ne peut jamais devenir
 * négatif, même avec des requêtes simultanées. Chaque opération écrit une ligne dans le journal.
 */
final class StockService
{
    /** @return array{movement_id: int, loan_id: ?int, remaining: string} */
    public static function take(int $itemId, int $unitId, string $quantity, int $userId, string $comment, ?bool $returnable): array
    {
        return Database::transaction(static function (PDO $pdo) use ($itemId, $unitId, $quantity, $userId, $comment, $returnable): array {
            $item = self::lockItem($pdo, $itemId);
            $unit = self::unit($pdo, $unitId);
            $before = self::level($pdo, $itemId, $unitId);
            $take = $pdo->prepare('UPDATE stock_levels SET quantity = quantity - ? WHERE item_id = ? AND unit_id = ? AND quantity >= ?');
            $take->execute([$quantity, $itemId, $unitId, $quantity]);
            if ($take->rowCount() !== 1) {
                throw new HttpException(409, 'Stock insuffisant dans ce rangement : ' . Quantity::format($before) . " {$item['unit']} disponible(s).", ['available' => (float) $before]);
            }
            $after = self::level($pdo, $itemId, $unitId);

            $loanId = null;
            if ($returnable ?? !(int) $item['is_consumable']) {
                $pdo->prepare('INSERT INTO loans (item_id, user_id, origin_unit_id, origin_path, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$itemId, $userId, $unitId, $unit['path_text'], $quantity, $comment]);
                $loanId = (int) $pdo->lastInsertId();
            }
            $movementId = self::record($pdo, $itemId, $loanId === null ? 'consume' : 'take', $quantity, $before, $after, $unit, null, $userId, $loanId, $comment);
            ItemIndex::reindex($pdo, [$itemId]);
            return ['movement_id' => $movementId, 'loan_id' => $loanId, 'remaining' => $after];
        });
    }

    /**
     * Retour (partiel ou total) d'un emprunt, toujours dans son rangement d'origine : le stock reste là
     * où on s'attend à le trouver. Un autre emplacement n'est choisi que si ce rangement n'existe plus.
     *
     * @return array{movement_id: int, remaining_to_return: string, status: string}
     */
    public static function giveBack(int $loanId, string $quantity, ?int $unitId, int $userId, bool $canReturnForOthers, string $comment): array
    {
        return Database::transaction(static function (PDO $pdo) use ($loanId, $quantity, $unitId, $userId, $canReturnForOthers, $comment): array {
            $loanStmt = $pdo->prepare('SELECT * FROM loans WHERE id = ?');
            $loanStmt->execute([$loanId]);
            $loan = $loanStmt->fetch() ?: throw new HttpException(404, 'Emprunt introuvable.');
            self::lockItem($pdo, (int) $loan['item_id']);
            $loanStmt->execute([$loanId]);
            $loan = $loanStmt->fetch();

            if ((int) $loan['user_id'] !== $userId && !$canReturnForOthers) {
                throw new HttpException(403, "Seul l'emprunteur ou un gestionnaire peut enregistrer ce retour.");
            }
            if ($loan['status'] !== 'open') {
                throw new HttpException(409, 'Cet emprunt est déjà clôturé.');
            }
            $originId = $loan['origin_unit_id'] === null ? null : (int) $loan['origin_unit_id'];
            if ($originId !== null && $unitId !== null && $unitId !== $originId) {
                Input::fail('unit_id', "Cet objet se rend à l'emplacement d'où il a été pris : {$loan['origin_path']}.");
            }
            $targetId = $originId ?? $unitId;
            if ($targetId === null) {
                Input::fail('unit_id', "Le rangement d'origine n'existe plus : choisissez un emplacement de retour.");
            }
            $unit = self::unit($pdo, $targetId);

            $update = $pdo->prepare(
                "UPDATE loans SET quantity_returned = quantity_returned + ?,
                        status = IF(quantity_returned >= quantity, 'closed', 'open'),
                        closed_at = IF(quantity_returned >= quantity, CURRENT_TIMESTAMP(3), NULL)
                 WHERE id = ? AND status = 'open' AND quantity_returned + ? <= quantity"
            );
            $update->execute([$quantity, $loanId, $quantity]);
            if ($update->rowCount() !== 1) {
                $remaining = Quantity::sub($loan['quantity'], $loan['quantity_returned']);
                Input::fail('quantity', 'Quantité supérieure à ce qui reste à rendre (' . Quantity::format($remaining) . ').');
            }
            $before = self::level($pdo, (int) $loan['item_id'], $targetId);
            self::addTo($pdo, (int) $loan['item_id'], $targetId, $quantity);
            $after = self::level($pdo, (int) $loan['item_id'], $targetId);

            $movementId = self::record($pdo, (int) $loan['item_id'], 'return', $quantity, $before, $after, null, $unit, $userId, $loanId, $comment);
            ItemIndex::reindex($pdo, [(int) $loan['item_id']]);

            $loanStmt->execute([$loanId]);
            $loan = $loanStmt->fetch();
            return [
                'movement_id' => $movementId,
                'remaining_to_return' => Quantity::sub($loan['quantity'], $loan['quantity_returned']),
                'status' => $loan['status'],
            ];
        });
    }

    /** Réapprovisionnement. @return array{movement_id: int, quantity: string} */
    public static function add(int $itemId, int $unitId, string $quantity, int $userId, string $comment): array
    {
        return Database::transaction(static function (PDO $pdo) use ($itemId, $unitId, $quantity, $userId, $comment): array {
            self::lockItem($pdo, $itemId);
            $unit = self::unit($pdo, $unitId);
            $before = self::level($pdo, $itemId, $unitId);
            self::addTo($pdo, $itemId, $unitId, $quantity);
            $after = self::level($pdo, $itemId, $unitId);
            $movementId = self::record($pdo, $itemId, 'add', $quantity, $before, $after, null, $unit, $userId, null, $comment);
            ItemIndex::reindex($pdo, [$itemId]);
            return ['movement_id' => $movementId, 'quantity' => $after];
        });
    }

    /** Correction : fixe la quantité réelle constatée dans un rangement. */
    public static function adjust(int $itemId, int $unitId, string $newQuantity, int $userId, string $comment): array
    {
        return Database::transaction(static function (PDO $pdo) use ($itemId, $unitId, $newQuantity, $userId, $comment): array {
            self::lockItem($pdo, $itemId);
            $unit = self::unit($pdo, $unitId);
            $before = self::level($pdo, $itemId, $unitId);
            if (Quantity::equals($before, $newQuantity)) {
                Input::fail('quantity', 'La quantité indiquée est identique au stock enregistré.');
            }
            $pdo->prepare(
                'INSERT INTO stock_levels (item_id, unit_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            )->execute([$itemId, $unitId, $newQuantity]);
            $delta = Quantity::abs(Quantity::sub($newQuantity, $before));
            $movementId = self::record($pdo, $itemId, 'adjust', $delta, $before, $newQuantity, $unit, $unit, $userId, null, $comment);
            ItemIndex::reindex($pdo, [$itemId]);
            return ['movement_id' => $movementId, 'quantity' => $newQuantity];
        });
    }

    /** Déplacement entre deux rangements. */
    public static function move(int $itemId, int $fromId, int $toId, string $quantity, int $userId, string $comment): array
    {
        if ($fromId === $toId) {
            Input::fail('to_unit_id', 'Le rangement de destination doit être différent du rangement de départ.');
        }
        return Database::transaction(static function (PDO $pdo) use ($itemId, $fromId, $toId, $quantity, $userId, $comment): array {
            $item = self::lockItem($pdo, $itemId);
            $from = self::unit($pdo, $fromId);
            $to = self::unit($pdo, $toId);
            $before = self::level($pdo, $itemId, $fromId);
            $take = $pdo->prepare('UPDATE stock_levels SET quantity = quantity - ? WHERE item_id = ? AND unit_id = ? AND quantity >= ?');
            $take->execute([$quantity, $itemId, $fromId, $quantity]);
            if ($take->rowCount() !== 1) {
                throw new HttpException(409, 'Stock insuffisant dans le rangement de départ : ' . Quantity::format($before) . " {$item['unit']} disponible(s).");
            }
            self::addTo($pdo, $itemId, $toId, $quantity);
            $movementId = self::record($pdo, $itemId, 'move', $quantity, $before, self::level($pdo, $itemId, $fromId), $from, $to, $userId, null, $comment);
            ItemIndex::reindex($pdo, [$itemId]);
            return ['movement_id' => $movementId];
        });
    }

    /** @return array<string, mixed> */
    private static function lockItem(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare('SELECT id, unit, is_consumable, is_active FROM items WHERE id = ? FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch() ?: throw new HttpException(404, 'Objet introuvable.');
        if (!(int) $item['is_active']) {
            throw new HttpException(409, 'Cet objet est archivé.');
        }
        return $item;
    }

    /** Rangement pouvant recevoir des objets. @return array<string, mixed> */
    private static function unit(PDO $pdo, int $unitId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.room_id, u.path_text, t.can_hold_items FROM storage_units u JOIN storage_types t ON t.id = u.type_id WHERE u.id = ?'
        );
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch() ?: Input::fail('unit_id', 'Rangement introuvable.');
        if (!(int) $unit['can_hold_items']) {
            Input::fail('unit_id', 'Ce rangement ne peut pas contenir d\'objets.');
        }
        return $unit;
    }

    private static function level(PDO $pdo, int $itemId, int $unitId): string
    {
        $stmt = $pdo->prepare('SELECT quantity FROM stock_levels WHERE item_id = ? AND unit_id = ?');
        $stmt->execute([$itemId, $unitId]);
        $value = $stmt->fetchColumn();
        return $value === false ? '0.000' : (string) $value;
    }

    private static function addTo(PDO $pdo, int $itemId, int $unitId, string $quantity): void
    {
        $pdo->prepare(
            'INSERT INTO stock_levels (item_id, unit_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        )->execute([$itemId, $unitId, $quantity]);
    }

    /** @param array<string, mixed>|null $from @param array<string, mixed>|null $to */
    private static function record(
        PDO $pdo, int $itemId, string $type, string $quantity, ?string $before, ?string $after,
        ?array $from, ?array $to, int $userId, ?int $loanId, string $comment,
    ): int {
        $pdo->prepare(
            'INSERT INTO stock_movements (item_id, movement_type, quantity, quantity_before, quantity_after, from_unit_id, from_path,
                to_unit_id, to_path, room_id, loan_id, user_id, comment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $itemId, $type, $quantity, $before, $after,
            $from['id'] ?? null, $from['path_text'] ?? '', $to['id'] ?? null, $to['path_text'] ?? '',
            $from['room_id'] ?? $to['room_id'] ?? null, $loanId, $userId, $comment,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
