<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\AdminGuard;
use App\Auth\SessionManager;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class UserController
{
    public static function index(Request $request): Response
    {
        $request->requirePermission('users.manage');

        $where = [];
        $params = [];
        $q = trim((string) ($request->query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.email LIKE ? OR u.display_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (isset($request->query['role_id']) && ctype_digit((string) $request->query['role_id'])) {
            $where[] = 'u.role_id = ?';
            $params[] = (int) $request->query['role_id'];
        }
        if (in_array($request->query['active'] ?? '', ['0', '1'], true)) {
            $where[] = 'u.is_active = ?';
            $params[] = (int) $request->query['active'];
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.display_name, u.is_active, u.last_login_at, u.created_at,
                    r.id AS role_id, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY u.display_name, u.email LIMIT 500'
        );
        $stmt->execute($params);

        return Response::json(['users' => array_map(self::format(...), $stmt->fetchAll())]);
    }

    /** Liste réduite (id, nom) pour les filtres de l'historique. */
    public static function options(Request $request): Response
    {
        $auth = $request->user();
        if (!$auth->can('history.view') && !$auth->can('users.manage')) {
            $auth->require('history.view');
        }
        $rows = Database::connection()->query('SELECT id, email, display_name FROM users ORDER BY display_name, email')->fetchAll();
        return Response::json(['users' => array_map(static fn ($u) => ['id' => (int) $u['id'], 'email' => $u['email'], 'display_name' => $u['display_name']], $rows)]);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('users.manage');
        $id = (int) $params['id'];
        $input = $request->input();

        $changes = Database::transaction(static function (PDO $pdo) use ($id, $input, $auth): array {
            AdminGuard::lock($pdo);
            $stmt = $pdo->prepare('SELECT id, role_id, is_active, display_name FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $user = $stmt->fetch() ?: throw new HttpException(404, 'Utilisateur introuvable.');

            $changes = [];
            if ($input->has('display_name')) {
                $changes['display_name'] = $input->string('display_name', 150);
            }
            if ($input->has('role_id')) {
                $roleId = $input->int('role_id', 1);
                $exists = $pdo->prepare('SELECT 1 FROM roles WHERE id = ?');
                $exists->execute([$roleId]);
                if ($exists->fetchColumn() === false) {
                    Input::fail('role_id', 'Rôle introuvable.');
                }
                $changes['role_id'] = $roleId;
            }
            if ($input->has('is_active')) {
                $active = $input->bool('is_active');
                if (!$active && $id === $auth->userId) {
                    throw new HttpException(409, 'Vous ne pouvez pas désactiver votre propre compte.');
                }
                $changes['is_active'] = (int) $active;
            }
            $changes = array_filter($changes, static fn ($value, $key) => (string) $value !== (string) $user[$key], ARRAY_FILTER_USE_BOTH);
            if ($changes === []) {
                return [];
            }

            $set = implode(', ', array_map(static fn (string $col) => "{$col} = ?", array_keys($changes)));
            $pdo->prepare("UPDATE users SET {$set} WHERE id = ?")->execute([...array_values($changes), $id]);
            AdminGuard::assertAdminRemains($pdo);
            return $changes;
        });

        if ($changes !== []) {
            if (($changes['is_active'] ?? 1) === 0) {
                SessionManager::destroyAllForUser($id);
            }
            Audit::log($auth->userId, 'user.updated', 'user', $id, $changes, $request->ip);
        }
        return Response::json(['user' => self::find($id)]);
    }

    /** @return array<string, mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.display_name, u.is_active, u.last_login_at, u.created_at,
                    r.id AS role_id, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        return self::format($stmt->fetch());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'is_active' => (bool) $row['is_active'],
            'role' => ['id' => (int) $row['role_id'], 'name' => $row['role_name']],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
