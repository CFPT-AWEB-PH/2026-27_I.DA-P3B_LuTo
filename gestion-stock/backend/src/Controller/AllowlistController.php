<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\Allowlist;
use App\Auth\AuthContext;
use App\Auth\PrivilegeGuard;
use App\Auth\SessionManager;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class AllowlistController
{
    public static function index(Request $request): Response
    {
        $request->requirePermission('users.manage');
        $rows = Database::connection()->query(
            'SELECT a.id, a.kind, a.value, a.note, a.created_at, u.email AS created_by
             FROM allowlist a LEFT JOIN users u ON u.id = a.created_by
             ORDER BY a.kind DESC, a.value'
        )->fetchAll();
        return Response::json(['entries' => array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $rows)]);
    }

    public static function create(Request $request): Response
    {
        $auth = $request->requirePermission('users.manage');
        $input = $request->input();
        $entry = Allowlist::parseEntry($input->string('value', 254));
        $note = $input->optionalString('note', 255);

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT 1 FROM allowlist WHERE kind = ? AND value = ?');
        $exists->execute([$entry['kind'], $entry['value']]);
        if ($exists->fetchColumn() !== false) {
            Input::fail('value', 'Cette entrée existe déjà.');
        }
        $pdo->prepare('INSERT INTO allowlist (kind, value, note, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$entry['kind'], $entry['value'], $note, $auth->userId]);
        $id = (int) $pdo->lastInsertId();

        Audit::log($auth->userId, 'allowlist.added', 'allowlist', $id, $entry, $request->ip);
        return Response::json(['entry' => ['id' => $id] + $entry + ['note' => $note]], 201);
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('users.manage');
        $id = (int) $params['id'];

        $entry = Database::transaction(static function (PDO $pdo) use ($id, $auth): array {
            $stmt = $pdo->prepare('SELECT kind, value FROM allowlist WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $entry = $stmt->fetch() ?: throw new HttpException(404, 'Entrée introuvable.');
            $pdo->prepare('DELETE FROM allowlist WHERE id = ?')->execute([$id]);
            // Un compte à identifiant et mot de passe n'a pas d'adresse et ne dépend pas de cette liste
            if (str_contains((string) $auth->email, '@') && !Allowlist::isAllowed((string) $auth->email)) {
                throw new HttpException(409, 'Suppression refusée : vous perdriez votre propre accès.');
            }
            self::closeSessionsOfRemoved($pdo, $entry, $auth);
            return $entry;
        });

        Audit::log($auth->userId, 'allowlist.removed', 'allowlist', $id, $entry, $request->ip);
        return Response::noContent();
    }

    /**
     * Retirer une adresse doit couper l'accès tout de suite, pas à l'expiration de ses sessions.
     * Un compte reste connecté s'il est encore autorisé autrement (autre entrée, administrateur initial).
     * Priver d'accès un compte plus puissant que soi est refusé, et toute la suppression annulée.
     *
     * @param array{kind: string, value: string} $entry
     */
    private static function closeSessionsOfRemoved(PDO $pdo, array $entry, AuthContext $auth): void
    {
        $stmt = $pdo->prepare($entry['kind'] === 'email' ? 'SELECT id, email, role_id FROM users WHERE email = ?' : 'SELECT id, email, role_id FROM users WHERE email LIKE ?');
        $stmt->execute([$entry['kind'] === 'email' ? $entry['value'] : '%@' . addcslashes($entry['value'], '%_\\')]);
        foreach ($stmt->fetchAll() as $user) {
            if (!Allowlist::isAllowed($user['email'])) {
                PrivilegeGuard::assertCanHandleRole($auth, $pdo, (int) $user['role_id']);
                SessionManager::destroyAllForUser((int) $user['id']);
            }
        }
    }
}
