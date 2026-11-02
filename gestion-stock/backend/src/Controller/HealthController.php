<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Migrator;

/** État du service, utilisé par le script de déploiement et la supervision. */
final class HealthController
{
    public static function show(Request $request): Response
    {
        $body = ['status' => 'ok', 'version' => Config::version(), 'database' => 'ok'];
        try {
            $pdo = Database::connection();
            $pdo->query('SELECT 1');
            $pending = count((new Migrator($pdo, MIGRATIONS_DIR))->pending());
            $body['migrations_pending'] = $pending;
            if ($pending > 0) {
                $body['status'] = 'degraded';
            }
        } catch (\Throwable $e) {
            error_log('Health check: ' . $e->getMessage());
            return Response::json(['status' => 'error', 'version' => Config::version(), 'database' => 'unavailable'], 503);
        }
        return Response::json($body, $body['status'] === 'ok' ? 200 : 503);
    }
}
