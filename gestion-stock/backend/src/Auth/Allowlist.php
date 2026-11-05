<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Database;
use App\Http\Input;

/**
 * Liste des adresses et domaines autorisés à se connecter.
 * Aucun domaine n'est autorisé par défaut ; un domaine n'autorise pas ses sous-domaines.
 */
final class Allowlist
{
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isAllowed(string $email): bool
    {
        $email = self::normalizeEmail($email);
        if (!self::isValidEmail($email)) {
            return false;
        }
        $bootstrap = Config::get('BOOTSTRAP_ADMIN_EMAIL');
        if ($bootstrap !== null && self::normalizeEmail($bootstrap) === $email) {
            return true;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM allowlist WHERE (kind = 'email' AND value = ?) OR (kind = 'domain' AND value = ?) LIMIT 1"
        );
        $stmt->execute([$email, $domain]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Interprète une saisie : « nom@domaine.ch » = adresse, « domaine.ch » ou « @domaine.ch » = domaine.
     *
     * @return array{kind: 'email'|'domain', value: string}
     */
    public static function parseEntry(string $raw): array
    {
        $value = self::normalizeEmail($raw);
        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }
        if (str_contains($value, '@')) {
            if (!self::isValidEmail($value)) {
                Input::fail('value', 'Adresse e-mail invalide.');
            }
            return ['kind' => 'email', 'value' => $value];
        }
        if (!preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $value)) {
            Input::fail('value', 'Domaine invalide (exemple : eduge.ch).');
        }
        return ['kind' => 'domain', 'value' => $value];
    }
}
