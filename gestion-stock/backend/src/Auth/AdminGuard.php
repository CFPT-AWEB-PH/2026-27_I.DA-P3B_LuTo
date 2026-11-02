<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use PDO;

/**
 * Empêche de rendre le système impossible à administrer.
 * Usage dans une transaction : lock(), modification, puis assertAdminRemains().
 * Le verrou sur le rôle protégé sérialise les modifications concurrentes de droits.
 */
final class AdminGuard
{
    public static function lock(PDO $pdo): void
    {
        $pdo->query('SELECT id FROM roles WHERE is_protected = 1 FOR UPDATE')->fetchAll();
    }

    public static function activeAdminCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM users u
             JOIN role_permissions rp ON rp.role_id = u.role_id AND rp.permission_code = 'system.admin'
             WHERE u.is_active = 1
             LOCK IN SHARE MODE"
        )->fetchColumn();
    }

    public static function assertAdminRemains(PDO $pdo): void
    {
        if (self::activeAdminCount($pdo) === 0) {
            throw new HttpException(409, 'Opération refusée : il doit rester au moins un administrateur actif.');
        }
    }
}
