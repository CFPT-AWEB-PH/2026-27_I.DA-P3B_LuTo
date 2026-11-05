<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;

/** Chiffres de l'accueil : chaque bloc n'est renvoyé qu'avec la permission correspondante. */
final class DashboardController
{
    public static function show(Request $request): Response
    {
        $auth = $request->user();
        $pdo = Database::connection();
        $count = static function (string $sql, array $params = []) use ($pdo): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };

        $body = [];
        if ($auth->can('rooms.view')) {
            $body['spaces'] = [
                'rooms' => $count('SELECT COUNT(*) FROM rooms'),
                'units' => $count('SELECT COUNT(*) FROM storage_units'),
            ];
        }
        if ($auth->can('stock.view')) {
            $body['stock'] = [
                'items' => $count('SELECT COUNT(*) FROM items WHERE is_active = 1'),
                'low' => $count(
                    'SELECT COUNT(*) FROM items i WHERE i.is_active = 1 AND i.min_quantity IS NOT NULL
                     AND (SELECT COALESCE(SUM(s.quantity), 0) FROM stock_levels s WHERE s.item_id = i.id) < i.min_quantity'
                ),
                'my_open_loans' => $count("SELECT COUNT(*) FROM loans WHERE status = 'open' AND user_id = ?", [$auth->userId]),
            ];
        }
        if ($auth->can('history.view')) {
            // Début de la journée locale, converti en UTC comme les dates stockées
            $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Zurich')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $body['activity'] = [
                'open_loans' => $count("SELECT COUNT(*) FROM loans WHERE status = 'open'"),
                'borrowers' => $count("SELECT COUNT(DISTINCT user_id) FROM loans WHERE status = 'open'"),
                'movements_today' => $count('SELECT COUNT(*) FROM stock_movements WHERE created_at >= ?', [$today]),
            ];
        }
        return Response::json($body);
    }
}
