<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\AdminGuard;
use App\Auth\Passwords;
use App\Auth\PrivilegeGuard;
use App\Auth\RateLimiter;
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
            $where[] = '(u.email LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (isset($request->query['role_id']) && ctype_digit((string) $request->query['role_id'])) {
            $where[] = 'u.role_id = ?';
            $params[] = (int) $request->query['role_id'];
        }
        if (($request->query['group'] ?? '') !== '') {
            $where[] = 'u.user_group = ?';
            $params[] = (string) $request->query['group'];
        }
        if (in_array($request->query['active'] ?? '', ['0', '1'], true)) {
            $where[] = 'u.is_active = ?';
            $params[] = (int) $request->query['active'];
        }

        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.email, u.username, u.user_group, u.display_name, u.is_active, u.last_login_at, u.created_at, u.password_set_at,
                    r.id AS role_id, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY u.display_name, u.email LIMIT 500'
        );
        $stmt->execute($params);

        $groups = Database::connection()->query("SELECT DISTINCT user_group FROM users WHERE user_group <> '' ORDER BY user_group")->fetchAll(PDO::FETCH_COLUMN);
        return Response::json(['users' => array_map(self::format(...), $stmt->fetchAll()), 'groups' => $groups]);
    }

    /**
     * Crée des comptes depuis une liste de personnes (lue dans un fichier Excel par le navigateur).
     * Chaque compte reçoit un identifiant unique et un mot de passe aléatoire, renvoyés ici et nulle part
     * ailleurs : seule leur empreinte est conservée. Une personne déjà importée est ignorée, sans être modifiée.
     * Les dates de naissance et autres colonnes du fichier ne sont ni envoyées ni conservées.
     */
    public static function import(Request $request): Response
    {
        $auth = $request->requirePermission('users.manage');
        $input = $request->input();
        RateLimiter::consume("costly-bcrypt:{$auth->userId}", self::COSTLY_LIMIT, self::COSTLY_WINDOW);
        $roleId = $input->int('role_id', 1);
        $raw = $request->json()['people'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::IMPORT_BATCH) {
            Input::fail('people', 'Liste de 1 à ' . self::IMPORT_BATCH . ' personnes attendue.');
        }
        $people = [];
        foreach ($raw as $entry) {
            $in = new Input(is_array($entry) ? $entry : []);
            $people[] = ['first_name' => $in->string('first_name', 70), 'last_name' => $in->string('last_name', 70), 'group' => $in->optionalString('group', 60)];
        }

        $result = Database::transaction(static function (PDO $pdo) use ($people, $roleId, $auth): array {
            $role = $pdo->prepare('SELECT is_protected FROM roles WHERE id = ?');
            $role->execute([$roleId]);
            $protected = $role->fetchColumn();
            if ($protected === false) {
                Input::fail('role_id', 'Rôle introuvable.');
            }
            if ((int) $protected) {
                Input::fail('role_id', 'Par sécurité, on ne crée pas de comptes administrateurs par import.');
            }
            PrivilegeGuard::assertCanHandleRole($auth, $pdo, $roleId);
            $known = $pdo->prepare('SELECT username FROM users WHERE import_key = ?');
            $taken = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $insert = $pdo->prepare(
                'INSERT INTO users (username, password_hash, password_set_at, user_group, import_key, display_name, role_id)
                 VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)'
            );
            $created = [];
            $skipped = [];
            foreach ($people as $p) {
                $name = self::displayName($p['first_name'], $p['last_name']);
                $key = hash('sha256', Passwords::slug($p['group']) . '|' . Passwords::slug($p['last_name']) . '|' . Passwords::slug($p['first_name']));
                $known->execute([$key]);
                if (($existing = $known->fetchColumn()) !== false) {
                    $skipped[] = ['display_name' => $name, 'username' => $existing, 'group' => $p['group']];
                    continue;
                }
                $base = Passwords::usernameBase($p['first_name'], $p['last_name']);
                $username = $base;
                for ($i = 2; ; $i++) {
                    $taken->execute([$username]);
                    if ($taken->fetchColumn() === false) {
                        break;
                    }
                    $username = "{$base}{$i}";
                }
                $password = Passwords::generate();
                $insert->execute([$username, Passwords::hash($password), $p['group'], $key, $name, $roleId]);
                $created[] = ['id' => (int) $pdo->lastInsertId(), 'display_name' => $name, 'username' => $username, 'password' => $password, 'group' => $p['group']];
            }
            return ['created' => $created, 'skipped' => $skipped];
        });

        Audit::log($auth->userId, 'user.imported', 'user', null, ['created' => count($result['created']), 'skipped' => count($result['skipped']), 'role_id' => $roleId], $request->ip);
        return Response::json($result, 201);
    }

    /**
     * Change le mot de passe d'un compte : réservé aux gestionnaires, une personne ne peut pas changer
     * le sien elle-même, ni celui d'un compte plus puissant que le sien (ce serait prendre sa place).
     * Sans mot de passe fourni, il est généré. Ses sessions ouvertes sont fermées.
     */
    public static function setPassword(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('users.manage');
        $id = (int) $params['id'];
        RateLimiter::consume("costly-bcrypt:{$auth->userId}", self::COSTLY_LIMIT, self::COSTLY_WINDOW);
        $input = $request->input();
        $password = $input->has('password') ? $input->string('password', 200, 8) : Passwords::generate();
        // bcrypt ne tient compte que des 72 premiers octets : la suite donnerait une fausse impression de solidité
        if (strlen($password) > 72) {
            Input::fail('password', '72 octets maximum.');
        }

        $user = Database::transaction(static function (PDO $pdo) use ($id, $password, $auth): array {
            $stmt = $pdo->prepare(
                'SELECT id, username, display_name, user_group, role_id FROM users WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $user = $stmt->fetch() ?: throw new HttpException(404, 'Utilisateur introuvable.');
            PrivilegeGuard::assertCanHandleRole($auth, $pdo, (int) $user['role_id']);
            if ($user['username'] === null) {
                throw new HttpException(409, 'Ce compte se connecte par son adresse e-mail : il n\'a pas de mot de passe.');
            }
            if (mb_strtolower($password) === $user['username']) {
                Input::fail('password', 'Le mot de passe doit être différent de l\'identifiant.');
            }
            $pdo->prepare('UPDATE users SET password_hash = ?, password_set_at = UTC_TIMESTAMP() WHERE id = ?')->execute([Passwords::hash($password), $id]);
            return $user;
        });

        SessionManager::destroyAllForUser($id);
        Audit::log($auth->userId, 'user.password_reset', 'user', $id, [], $request->ip);
        return Response::json(['id' => $id, 'display_name' => $user['display_name'], 'username' => $user['username'], 'password' => $password, 'group' => $user['user_group']]);
    }

    private const IMPORT_BATCH = 25;
    // Calcul d'empreinte coûteux : largement de quoi traiter plusieurs classes en 10 minutes
    private const COSTLY_LIMIT = 120;
    private const COSTLY_WINDOW = 600;

    /** « TOM » + « CUNNINGHAM » → « Tom Cunningham ». */
    private static function displayName(string $firstName, string $lastName): string
    {
        return mb_substr(mb_convert_case(trim($firstName . ' ' . $lastName), MB_CASE_TITLE), 0, 150);
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
            // Ni agir sur un compte plus puissant que soi, ni attribuer un rôle plus puissant que le sien
            PrivilegeGuard::assertCanHandleRole($auth, $pdo, (int) $user['role_id']);

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
                PrivilegeGuard::assertCanHandleRole($auth, $pdo, $roleId);
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
            'SELECT u.id, u.email, u.username, u.user_group, u.display_name, u.is_active, u.last_login_at, u.created_at, u.password_set_at,
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
            'username' => $row['username'],
            'group' => $row['user_group'],
            'display_name' => $row['display_name'],
            'is_active' => (bool) $row['is_active'],
            'role' => ['id' => (int) $row['role_id'], 'name' => $row['role_name']],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
