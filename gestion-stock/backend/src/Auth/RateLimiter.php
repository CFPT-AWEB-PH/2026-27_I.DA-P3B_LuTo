<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use App\Http\HttpException;

/** Limitation simple par fenêtre fixe, stockée en base (partagée entre processus PHP). */
final class RateLimiter
{
    public static function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $pdo = Database::connection();
        $window = gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([$bucket, $window]);
        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$bucket, $window]);
        if ((int) $stmt->fetchColumn() > $limit) {
            throw new HttpException(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
        }
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
    }
}
