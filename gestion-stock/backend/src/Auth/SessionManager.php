<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Database;
use App\Http\Request;

/**
 * Sessions stockées en base : le cookie contient un jeton aléatoire, la base son empreinte.
 * La désactivation d'un utilisateur invalide immédiatement toutes ses sessions.
 */
final class SessionManager
{
    public const COOKIE = 'gs_session';
    private const TOUCH_INTERVAL = 300;

    public static function create(int $userId, Request $request): void
    {
        // Une session déjà ouverte dans ce navigateur est remplacée, jamais conservée à côté
        $previous = $request->cookies[self::COOKIE] ?? '';
        if (preg_match('/^[a-f0-9]{64}$/D', $previous)) {
            Database::connection()->prepare('DELETE FROM sessions WHERE id = ?')->execute([hash('sha256', $previous)]);
        }
        $token = bin2hex(random_bytes(32));
        $lifetime = self::lifetimeDays();
        $stmt = Database::connection()->prepare(
            'INSERT INTO sessions (id, user_id, csrf_token, ip_address, user_agent, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? DAY)'
        );
        $stmt->execute([
            hash('sha256', $token),
            $userId,
            bin2hex(random_bytes(32)),
            $request->ip,
            mb_substr((string) $request->header('user-agent'), 0, 255),
            $lifetime,
        ]);
        self::setCookie($token, time() + $lifetime * 86400, $request);
    }

    public static function resolve(Request $request): ?AuthContext
    {
        $token = $request->cookies[self::COOKIE] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.csrf_token, TIMESTAMPDIFF(SECOND, s.last_seen_at, UTC_TIMESTAMP()) AS idle,
                    u.id AS user_id, COALESCE(u.email, u.username) AS email, u.display_name, r.id AS role_id, r.name AS role_name
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP() AND u.is_active = 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['idle'] > self::idleDays() * 86400) {
            self::setCookie('', 1, $request);
            return null;
        }
        if ((int) $row['idle'] > self::TOUCH_INTERVAL) {
            $pdo->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['id']]);
        }
        if (random_int(1, 200) === 1) {
            $pdo->exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');
        }

        $perms = $pdo->prepare('SELECT permission_code FROM role_permissions WHERE role_id = ?');
        $perms->execute([$row['role_id']]);

        return new AuthContext(
            (int) $row['user_id'],
            $row['email'],
            $row['display_name'],
            (int) $row['role_id'],
            $row['role_name'],
            array_fill_keys($perms->fetchAll(\PDO::FETCH_COLUMN), true),
            $row['id'],
            $row['csrf_token'],
        );
    }

    public static function destroy(AuthContext $auth, Request $request): void
    {
        Database::connection()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$auth->sessionId]);
        self::setCookie('', 1, $request);
    }

    public static function destroyAllForUser(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
    }

    private static function setCookie(string $value, int $expires, Request $request): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            // Secure dès que le site est servi en HTTPS ; en HTTP local le navigateur refuserait le cookie
            'secure' => $request->https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function lifetimeDays(): int
    {
        return max(1, (int) Config::get('SESSION_LIFETIME_DAYS', '30'));
    }

    private static function idleDays(): int
    {
        return max(1, (int) Config::get('SESSION_IDLE_DAYS', '7'));
    }
}
