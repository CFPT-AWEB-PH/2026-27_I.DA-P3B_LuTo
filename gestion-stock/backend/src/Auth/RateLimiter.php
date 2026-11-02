<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use App\Http\HttpException;

/**
 * Limitation par fenêtre fixe, stockée en base (partagée entre processus PHP).
 * Usage typique : assertAllowed() avant une tentative, hit() seulement en cas d'échec,
 * pour freiner les essais au hasard sans gêner les utilisateurs légitimes.
 */
final class RateLimiter
{
    public static function assertAllowed(string $bucket, int $limit, int $windowSeconds): void
    {
        $stmt = Database::connection()->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([$bucket, self::window($windowSeconds)]);
        if ((int) $stmt->fetchColumn() >= $limit) {
            throw new HttpException(429, 'Trop de tentatives. Réessayez dans quelques minutes.');
        }
    }

    public static function hit(string $bucket, int $windowSeconds): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute([$bucket, self::window($windowSeconds)]);
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
    }

    private static function window(int $windowSeconds): string
    {
        return gmdate('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    }
}
