<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use App\Http\Request;

/**
 * Protection CSRF des requêtes qui modifient des données :
 * jeton de session dans l'en-tête X-CSRF-Token, et origine identique si le navigateur l'indique.
 */
final class Csrf
{
    public static function check(Request $request): void
    {
        if ($request->isSafeMethod()) {
            return;
        }
        $origin = $request->header('origin');
        if ($origin !== null && self::authority($origin) !== strtolower((string) $request->header('host'))) {
            throw new HttpException(403, 'Origine de la requête refusée.');
        }
        if ($request->auth === null) {
            return;
        }
        if (!hash_equals($request->auth->csrfToken, (string) $request->header('x-csrf-token'))) {
            throw new HttpException(403, 'Jeton de sécurité invalide. Rechargez la page.');
        }
    }

    /** « http://hote:8080 » -> « hote:8080 » (même format que l'en-tête Host) */
    private static function authority(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        return $port === null ? $host : "{$host}:{$port}";
    }
}
