<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Database;
use App\Http\HttpException;

/**
 * Liens de connexion à usage unique, créés en ligne de commande sur le serveur
 * (bin/login-link.php). Seule une personne disposant d'un accès SSH peut en générer.
 * Mode de connexion provisoire en attendant Google ; désactivable avec AUTH_LOGIN_LINKS=0.
 */
final class LoginLinks
{
    public static function enabled(): bool
    {
        return Config::get('AUTH_LOGIN_LINKS', '1') === '1';
    }

    public static function create(string $email, int $minutes = 15): string
    {
        $token = bin2hex(random_bytes(32));
        Database::connection()->prepare(
            'INSERT INTO login_tokens (token_hash, email, created_at, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? MINUTE)'
        )->execute([hash('sha256', $token), Allowlist::normalizeEmail($email), $minutes]);
        return $token;
    }

    /** Consomme le jeton (une seule fois) et renvoie l'adresse associée. */
    public static function consume(string $token): string
    {
        if (!self::enabled()) {
            throw new HttpException(403, 'La connexion par lien est désactivée.', ['code' => 'disabled_method']);
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpException(400, 'Lien de connexion invalide.', ['code' => 'invalid_link']);
        }
        $pdo = Database::connection();
        $hash = hash('sha256', $token);
        $update = $pdo->prepare(
            'UPDATE login_tokens SET used_at = UTC_TIMESTAMP()
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $update->execute([$hash]);
        if ($update->rowCount() !== 1) {
            throw new HttpException(400, 'Lien de connexion invalide, expiré ou déjà utilisé.', ['code' => 'invalid_link']);
        }
        $stmt = $pdo->prepare('SELECT email FROM login_tokens WHERE token_hash = ?');
        $stmt->execute([$hash]);
        $pdo->exec('DELETE FROM login_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        return (string) $stmt->fetchColumn();
    }
}
