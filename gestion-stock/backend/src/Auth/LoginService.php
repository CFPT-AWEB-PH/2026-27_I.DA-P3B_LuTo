<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit;
use App\Config;
use App\Database;
use App\Http\HttpException;
use PDO;

/**
 * Point d'entrée commun à tous les modes de connexion (lien à usage unique, puis Google).
 * Vérifie la liste d'autorisation, crée le compte au premier accès avec le rôle par défaut
 * et désigne le premier administrateur.
 */
final class LoginService
{
    /** @return int identifiant de l'utilisateur connecté */
    public static function login(string $email, string $ip, string $displayName = '', ?string $googleSub = null): int
    {
        $email = Allowlist::normalizeEmail($email);
        if (!Allowlist::isAllowed($email)) {
            Audit::log(null, 'auth.denied', details: ['email' => $email, 'reason' => 'not_allowed'], ip: $ip);
            throw new HttpException(403, "Ce compte n'est pas autorisé à accéder à l'application.", ['code' => 'not_allowed']);
        }

        $result = Database::transaction(static function (PDO $pdo) use ($email, $displayName, $googleSub): array {
            $stmt = $pdo->prepare('SELECT id, is_active FROM users WHERE email = ? FOR UPDATE');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user !== false && !(int) $user['is_active']) {
                return ['denied' => (int) $user['id']];
            }
            $created = false;
            if ($user === false) {
                $roleId = (int) $pdo->query('SELECT id FROM roles WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
                $pdo->prepare('INSERT INTO users (email, display_name, google_sub, role_id) VALUES (?, ?, ?, ?)')
                    ->execute([$email, $displayName !== '' ? $displayName : self::nameFromEmail($email), $googleSub, $roleId]);
                $userId = (int) $pdo->lastInsertId();
                $created = true;
            } else {
                $userId = (int) $user['id'];
            }

            $bootstrapped = self::bootstrapAdmin($pdo, $userId, $email);

            $pdo->prepare(
                'UPDATE users SET last_login_at = UTC_TIMESTAMP(),
                    display_name = IF(? <> \'\', ?, display_name), google_sub = COALESCE(?, google_sub) WHERE id = ?'
            )->execute([$displayName, $displayName, $googleSub, $userId]);

            return ['user_id' => $userId, 'created' => $created, 'bootstrapped' => $bootstrapped];
        });

        if (isset($result['denied'])) {
            Audit::log($result['denied'], 'auth.denied', 'user', $result['denied'], ['reason' => 'disabled'], $ip);
            throw new HttpException(403, 'Votre compte est désactivé. Contactez un administrateur.', ['code' => 'disabled']);
        }
        $userId = $result['user_id'];
        if ($result['created']) {
            Audit::log($userId, 'user.created', 'user', $userId, ['email' => $email, 'via' => 'login'], $ip);
        }
        if ($result['bootstrapped']) {
            Audit::log($userId, 'auth.bootstrap_admin', 'user', $userId, ['email' => $email], $ip);
        }
        Audit::log($userId, 'auth.login', 'user', $userId, [], $ip);
        return $userId;
    }

    /**
     * Connexion par identifiant et mot de passe. Ces comptes sont créés par un gestionnaire :
     * la liste d'autorisation, faite pour les adresses e-mail, ne les concerne pas.
     * Même réponse et même durée de calcul qu'un compte existe ou non.
     *
     * @return int identifiant de l'utilisateur connecté
     */
    public static function loginWithPassword(string $username, string $password, string $ip): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, is_active, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $hash = $user['password_hash'] ?? null;
        // Compte inconnu : un calcul d'empreinte équivalent, pour que le temps de réponse ne le trahisse pas
        $valid = $hash !== null ? password_verify($password, $hash) : password_hash($password, PASSWORD_DEFAULT) === '';
        if ($user === false || $hash === null || !$valid) {
            Audit::log($user === false ? null : (int) $user['id'], 'auth.denied', details: ['username' => $username, 'reason' => 'bad_password'], ip: $ip);
            throw new HttpException(401, 'Identifiant ou mot de passe incorrect.', ['code' => 'bad_credentials']);
        }
        $userId = (int) $user['id'];
        if (!(int) $user['is_active']) {
            Audit::log($userId, 'auth.denied', 'user', $userId, ['reason' => 'disabled'], $ip);
            throw new HttpException(403, 'Ce compte est désactivé. Adressez-vous à la personne qui gère les comptes.', ['code' => 'disabled']);
        }
        $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
        Audit::log($userId, 'auth.login', 'user', $userId, ['via' => 'password'], $ip);
        return $userId;
    }

    /**
     * L'adresse BOOTSTRAP_ADMIN_EMAIL devient administrateur uniquement si aucun administrateur
     * actif n'existe encore : une fois le système initialisé, la variable n'a plus d'effet.
     */
    private static function bootstrapAdmin(PDO $pdo, int $userId, string $email): bool
    {
        $bootstrap = Config::get('BOOTSTRAP_ADMIN_EMAIL');
        if ($bootstrap === null || Allowlist::normalizeEmail($bootstrap) !== $email) {
            return false;
        }
        AdminGuard::lock($pdo);
        if (AdminGuard::activeAdminCount($pdo) > 0) {
            return false;
        }
        $pdo->prepare('UPDATE users SET role_id = (SELECT id FROM roles WHERE is_protected = 1 LIMIT 1) WHERE id = ?')
            ->execute([$userId]);
        return true;
    }

    private static function nameFromEmail(string $email): string
    {
        $local = substr($email, 0, (int) strpos($email, '@'));
        return mb_convert_case(str_replace(['.', '_', '-'], ' ', $local), MB_CASE_TITLE);
    }
}
