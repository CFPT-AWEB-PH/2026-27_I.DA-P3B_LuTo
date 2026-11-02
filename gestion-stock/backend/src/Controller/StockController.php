<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Stock\Quantity;
use App\Stock\StockService;
use PDO;

/** Opérations de stock, emprunts et journal des mouvements. */
final class StockController
{
    public const TYPES = ['take', 'consume', 'return', 'add', 'adjust', 'move'];

    public static function take(Request $request): Response
    {
        $auth = $request->requirePermission('stock.take');
        $in = $request->input();
        $result = StockService::take(
            $in->int('item_id', 1), $in->int('unit_id', 1), $in->quantity('quantity'), $auth->userId,
            $in->optionalString('comment', 500), $in->has('returnable') ? $in->bool('returnable') : null,
        );
        return Response::json(['remaining' => Quantity::out($result['remaining'])] + $result, 201);
    }

    public static function giveBack(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('stock.take');
        $in = $request->input();
        $result = StockService::giveBack(
            (int) $params['id'], $in->quantity('quantity'), $in->optionalInt('unit_id', null, 1), $auth->userId,
            $auth->can('stock.adjust'), $in->optionalString('comment', 500),
        );
        return Response::json(['remaining_to_return' => Quantity::out($result['remaining_to_return'])] + $result, 201);
    }

    public static function add(Request $request): Response
    {
        $auth = $request->requirePermission('stock.add');
        $in = $request->input();
        $result = StockService::add($in->int('item_id', 1), $in->int('unit_id', 1), $in->quantity('quantity'), $auth->userId, $in->optionalString('comment', 500));
        return Response::json(['quantity' => Quantity::out($result['quantity'])] + $result, 201);
    }

    public static function adjust(Request $request): Response
    {
        $auth = $request->requirePermission('stock.adjust');
        $in = $request->input();
        $result = StockService::adjust($in->int('item_id', 1), $in->int('unit_id', 1), $in->quantity('quantity', true), $auth->userId, $in->string('comment', 500, 3));
        return Response::json(['quantity' => Quantity::out($result['quantity'])] + $result, 201);
    }

    public static function move(Request $request): Response
    {
        $auth = $request->requirePermission('stock.move');
        $in = $request->input();
        return Response::json(StockService::move(
            $in->int('item_id', 1), $in->int('from_unit_id', 1), $in->int('to_unit_id', 1), $in->quantity('quantity'), $auth->userId, $in->optionalString('comment', 500),
        ), 201);
    }

    /** Emprunts : chacun voit les siens ; history.view permet de voir ceux de tous. */
    public static function loans(Request $request): Response
    {
        $auth = $request->requirePermission('stock.view');
        $q = $request->query;
        $where = ['1 = 1'];
        $params = [];
        if (($q['scope'] ?? 'mine') !== 'all' || !$auth->can('history.view')) {
            $where[] = 'l.user_id = ?';
            $params[] = $auth->userId;
        } elseif (isset($q['user_id']) && ctype_digit((string) $q['user_id'])) {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $q['user_id'];
        }
        if (in_array($q['status'] ?? 'open', ['open', 'closed'], true)) {
            $where[] = 'l.status = ?';
            $params[] = $q['status'] ?? 'open';
        }
        if (isset($q['item_id']) && ctype_digit((string) $q['item_id'])) {
            $where[] = 'l.item_id = ?';
            $params[] = (int) $q['item_id'];
        }
        $stmt = Database::connection()->prepare(
            'SELECT l.*, u.id AS user_id, u.email, u.display_name, i.name AS item_name, i.reference AS item_reference, i.unit AS item_unit
             FROM loans l JOIN users u ON u.id = l.user_id JOIN items i ON i.id = l.item_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY l.taken_at DESC LIMIT 500'
        );
        $stmt->execute($params);
        return Response::json(['loans' => array_map(self::formatLoan(...), $stmt->fetchAll())]);
    }

    /** Journal des mouvements avec filtres (utilisateur, objet, salle, type, période). */
    public static function movements(Request $request): Response
    {
        $request->requirePermission('history.view');
        $q = $request->query;
        $where = ['1 = 1'];
        $params = [];
        foreach (['item_id' => 'm.item_id', 'user_id' => 'm.user_id', 'room_id' => 'm.room_id', 'loan_id' => 'm.loan_id'] as $key => $column) {
            if (isset($q[$key]) && ctype_digit((string) $q[$key])) {
                $where[] = "{$column} = ?";
                $params[] = (int) $q[$key];
            }
        }
        if (in_array($q['type'] ?? '', self::TYPES, true)) {
            $where[] = 'm.movement_type = ?';
            $params[] = $q['type'];
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if (isset($q[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q[$key])) {
                // bornes en dates locales (Europe/Zurich) converties en UTC
                $date = new \DateTimeImmutable($q[$key] . ' 00:00:00', new \DateTimeZone('Europe/Zurich'));
                if ($key === 'to') {
                    $date = $date->modify('+1 day');
                }
                $where[] = "m.created_at {$op} ?";
                $params[] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($q['per_page'] ?? 50)));
        $pdo = Database::connection();
        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM stock_movements m WHERE {$whereSql}");
        $count->execute($params);
        $stmt = $pdo->prepare(
            "SELECT m.*, i.name AS item_name, i.reference AS item_reference, i.unit AS item_unit, u.email, u.display_name
             FROM stock_movements m JOIN items i ON i.id = m.item_id LEFT JOIN users u ON u.id = m.user_id
             WHERE {$whereSql} ORDER BY m.created_at DESC, m.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return Response::json([
            'movements' => array_map(static fn ($m) => [
                'id' => (int) $m['id'],
                'type' => $m['movement_type'],
                'item' => ['id' => (int) $m['item_id'], 'name' => $m['item_name'], 'reference' => $m['item_reference'], 'unit' => $m['item_unit']],
                'quantity' => Quantity::out($m['quantity']),
                'quantity_before' => Quantity::out($m['quantity_before']),
                'quantity_after' => Quantity::out($m['quantity_after']),
                'from' => $m['from_path'] === '' ? null : ['unit_id' => $m['from_unit_id'] === null ? null : (int) $m['from_unit_id'], 'path' => $m['from_path']],
                'to' => $m['to_path'] === '' ? null : ['unit_id' => $m['to_unit_id'] === null ? null : (int) $m['to_unit_id'], 'path' => $m['to_path']],
                'room_id' => $m['room_id'] === null ? null : (int) $m['room_id'],
                'loan_id' => $m['loan_id'] === null ? null : (int) $m['loan_id'],
                'user' => $m['user_id'] === null ? null : ['id' => (int) $m['user_id'], 'email' => $m['email'], 'display_name' => $m['display_name']],
                'comment' => $m['comment'],
                'created_at' => $m['created_at'],
            ], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** @param array<string, mixed> $l @return array<string, mixed> */
    public static function formatLoan(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'item' => isset($l['item_name']) ? ['id' => (int) $l['item_id'], 'name' => $l['item_name'], 'reference' => $l['item_reference'], 'unit' => $l['item_unit']] : null,
            'user' => ['id' => (int) $l['user_id'], 'email' => $l['email'], 'display_name' => $l['display_name']],
            'quantity' => Quantity::out($l['quantity']),
            'quantity_returned' => Quantity::out($l['quantity_returned']),
            'remaining' => Quantity::out(Quantity::sub((string) $l['quantity'], (string) $l['quantity_returned'])),
            'status' => $l['status'],
            'origin' => ['unit_id' => $l['origin_unit_id'] === null ? null : (int) $l['origin_unit_id'], 'path' => $l['origin_path']],
            'comment' => $l['comment'],
            'taken_at' => $l['taken_at'],
            'closed_at' => $l['closed_at'],
        ];
    }

    /** Contenu d'un rangement (et de ses sous-rangements avec deep=1). */
    public static function contents(Request $request, array $params): Response
    {
        $request->requirePermission('stock.view');
        $pdo = Database::connection();
        $id = (int) $params['id'];
        $ids = ($request->query['deep'] ?? '') === '1' ? [$id, ...ItemController::descendants($pdo, $id)] : [$id];
        $stmt = $pdo->prepare(
            'SELECT s.item_id, s.unit_id, s.quantity, i.name, i.reference, i.unit, i.min_quantity, u.path_text
             FROM stock_levels s JOIN items i ON i.id = s.item_id JOIN storage_units u ON u.id = s.unit_id
             WHERE s.quantity > 0 AND s.unit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY i.name'
        );
        $stmt->execute($ids);
        return Response::json(['contents' => array_map(static fn ($r) => [
            'item_id' => (int) $r['item_id'], 'unit_id' => (int) $r['unit_id'], 'name' => $r['name'], 'reference' => $r['reference'],
            'unit' => $r['unit'], 'quantity' => Quantity::out($r['quantity']), 'path' => $r['path_text'],
        ], $stmt->fetchAll())]);
    }
}
