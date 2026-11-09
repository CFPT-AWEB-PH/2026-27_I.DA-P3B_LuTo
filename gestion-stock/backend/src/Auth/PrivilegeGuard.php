<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use PDO;

/**
 * Empêche l'escalade de privilèges : personne ne confère ni ne touche des droits qu'il n'a pas lui-même.
 * Un gestionnaire de comptes ou de rôles agit donc seulement dans la limite de ses propres permissions ;
 * system.admin n'a pas de limite.
 */
final class PrivilegeGuard
{
    /** Permissions d'administration : jamais sur le rôle attribué d'office aux nouveaux comptes. */
    private const ADMIN_PERMISSIONS = ['system.admin', 'users.manage', 'roles.manage', 'settings.manage'];

    /** @return list<string> */
    public static function rolePermissions(PDO $pdo, int $roleId): array
    {
        $stmt = $pdo->prepare('SELECT permission_code FROM role_permissions WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $permissions */
    public static function assertCanGrant(AuthContext $actor, array $permissions): void
    {
        if ($actor->can('system.admin')) {
            return;
        }
        if (array_diff($permissions, array_keys($actor->permissions)) !== []) {
            throw new HttpException(403, 'Vous ne pouvez pas accorder ni modifier des droits que vous ne possédez pas.');
        }
    }

    /** Pour attribuer un rôle, ou agir sur ce rôle ou un compte qui le porte. */
    public static function assertCanHandleRole(AuthContext $actor, PDO $pdo, int $roleId): void
    {
        self::assertCanGrant($actor, self::rolePermissions($pdo, $roleId));
    }

    /**
     * Le rôle par défaut est donné sans intervention à toute adresse autorisée qui se connecte :
     * s'il portait des droits d'administration, chaque nouveau compte les recevrait.
     *
     * @param list<string> $permissions
     */
    public static function assertSafeAsDefault(array $permissions): void
    {
        if (array_intersect($permissions, self::ADMIN_PERMISSIONS) !== []) {
            throw new HttpException(409, "Le rôle par défaut ne peut pas porter de droits d'administration : chaque nouveau compte les recevrait.");
        }
    }
}
