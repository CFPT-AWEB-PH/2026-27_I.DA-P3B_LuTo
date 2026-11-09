<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\AdminGuard;
use App\Auth\PrivilegeGuard;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class RoleController
{
    public static function index(Request $request): Response
    {
        // La liste des rôles sert aussi à la page des utilisateurs
        $auth = $request->user();
        if (!$auth->can('roles.manage') && !$auth->can('users.manage')) {
            $auth->require('roles.manage');
        }
        return Response::json(['roles' => self::all()]);
    }

    public static function permissions(Request $request): Response
    {
        $request->requirePermission('roles.manage');
        $rows = Database::connection()->query('SELECT code, label, category FROM permissions ORDER BY sort_order')->fetchAll();
        return Response::json(['permissions' => $rows]);
    }

    public static function create(Request $request): Response
    {
        $auth = $request->requirePermission('roles.manage');
        $input = $request->input();
        $name = $input->string('name', 80);
        $description = $input->optionalString('description', 255);
        $permissions = $input->has('permissions') ? $input->stringList('permissions', 100, 50) : [];
        $isDefault = $input->has('is_default') && $input->bool('is_default');

        $id = Database::transaction(static function (PDO $pdo) use ($name, $description, $permissions, $isDefault, $auth): int {
            self::assertNameFree($pdo, $name, null);
            self::assertPermissionsExist($pdo, $permissions);
            PrivilegeGuard::assertCanGrant($auth, $permissions);
            $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)')->execute([$name, $description]);
            $id = (int) $pdo->lastInsertId();
            self::replacePermissions($pdo, $id, $permissions);
            if ($isDefault) {
                self::makeDefault($pdo, $id);
            }
            return $id;
        });

        Audit::log($auth->userId, 'role.created', 'role', $id, ['name' => $name, 'permissions' => $permissions], $request->ip);
        return Response::json(['role' => self::find($id)], 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('roles.manage');
        $id = (int) $params['id'];
        $input = $request->input();

        $changes = Database::transaction(static function (PDO $pdo) use ($id, $input, $auth): array {
            AdminGuard::lock($pdo);
            $role = self::lockRole($pdo, $id);
            PrivilegeGuard::assertCanHandleRole($auth, $pdo, $id);
            $changes = [];

            if ($input->has('name')) {
                $name = $input->string('name', 80);
                self::assertNameFree($pdo, $name, $id);
                $changes['name'] = $name;
            }
            if ($input->has('description')) {
                $changes['description'] = $input->optionalString('description', 255);
            }
            if ($changes !== []) {
                $set = implode(', ', array_map(static fn (string $col) => "{$col} = ?", array_keys($changes)));
                $pdo->prepare("UPDATE roles SET {$set} WHERE id = ?")->execute([...array_values($changes), $id]);
            }
            if ($input->has('permissions')) {
                if ((int) $role['is_protected']) {
                    throw new HttpException(409, 'Le rôle administrateur possède toujours toutes les permissions.');
                }
                $permissions = $input->stringList('permissions', 100, 50);
                self::assertPermissionsExist($pdo, $permissions);
                PrivilegeGuard::assertCanGrant($auth, $permissions);
                if ((int) $role['is_default']) {
                    PrivilegeGuard::assertSafeAsDefault($permissions);
                }
                self::replacePermissions($pdo, $id, $permissions);
                $changes['permissions'] = $permissions;
            }
            if ($input->has('is_default')) {
                if (!$input->bool('is_default')) {
                    throw new HttpException(409, 'Désignez un autre rôle par défaut plutôt que de retirer celui-ci.');
                }
                self::makeDefault($pdo, $id);
                $changes['is_default'] = true;
            }
            AdminGuard::assertAdminRemains($pdo);
            return $changes;
        });

        if ($changes !== []) {
            Audit::log($auth->userId, 'role.updated', 'role', $id, $changes, $request->ip);
        }
        return Response::json(['role' => self::find($id)]);
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('roles.manage');
        $id = (int) $params['id'];

        $name = Database::transaction(static function (PDO $pdo) use ($id, $auth): string {
            $role = self::lockRole($pdo, $id);
            PrivilegeGuard::assertCanHandleRole($auth, $pdo, $id);
            if ((int) $role['is_protected']) {
                throw new HttpException(409, 'Le rôle administrateur ne peut pas être supprimé.');
            }
            if ((int) $role['is_default']) {
                throw new HttpException(409, "Le rôle par défaut ne peut pas être supprimé. Désignez d'abord un autre rôle par défaut.");
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
            $count->execute([$id]);
            if (($n = (int) $count->fetchColumn()) > 0) {
                throw new HttpException(409, "Ce rôle est attribué à {$n} utilisateur(s). Attribuez-leur un autre rôle avant de le supprimer.");
            }
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            return $role['name'];
        });

        Audit::log($auth->userId, 'role.deleted', 'role', $id, ['name' => $name], $request->ip);
        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private static function lockRole(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new HttpException(404, 'Rôle introuvable.');
    }

    private static function assertNameFree(PDO $pdo, string $name, ?int $exceptId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            Input::fail('name', 'Un rôle porte déjà ce nom.');
        }
    }

    /** @param list<string> $codes */
    private static function assertPermissionsExist(PDO $pdo, array $codes): void
    {
        if ($codes === []) {
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $stmt->execute($codes);
        if ((int) $stmt->fetchColumn() !== count($codes)) {
            Input::fail('permissions', 'Permission inconnue.');
        }
    }

    /** @param list<string> $codes */
    private static function replacePermissions(PDO $pdo, int $roleId, array $codes): void
    {
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_code) VALUES (?, ?)');
        foreach ($codes as $code) {
            $insert->execute([$roleId, $code]);
        }
    }

    private static function makeDefault(PDO $pdo, int $id): void
    {
        PrivilegeGuard::assertSafeAsDefault(PrivilegeGuard::rolePermissions($pdo, $id));
        $pdo->prepare('UPDATE roles SET is_default = (id = ?)')->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    private static function all(?int $id = null): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT r.id, r.name, r.description, r.is_default, r.is_protected,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
             FROM roles r' . ($id === null ? '' : ' WHERE r.id = ?') . ' ORDER BY r.is_protected DESC, r.name'
        );
        $stmt->execute($id === null ? [] : [$id]);
        $roles = $stmt->fetchAll();

        $perms = [];
        foreach ($pdo->query('SELECT role_id, permission_code FROM role_permissions')->fetchAll() as $row) {
            $perms[(int) $row['role_id']][] = $row['permission_code'];
        }
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'is_default' => (bool) $r['is_default'],
            'is_protected' => (bool) $r['is_protected'],
            'user_count' => (int) $r['user_count'],
            'permissions' => $perms[(int) $r['id']] ?? [],
        ], $roles);
    }

    /** @return array<string, mixed> */
    private static function find(int $id): array
    {
        return self::all($id)[0] ?? throw new HttpException(404, 'Rôle introuvable.');
    }
}
