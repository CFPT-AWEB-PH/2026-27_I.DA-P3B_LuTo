<?php

declare(strict_types=1);

namespace App;

use PDO;

/** Connexion PDO unique par requête. Toutes les dates sont stockées en UTC. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', 'mariadb'),
                Config::required('DB_NAME'),
            );
            self::$pdo = new PDO($dsn, Config::required('DB_USER'), Config::required('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'",
            ]);
        }
        return self::$pdo;
    }

    /**
     * Exécute $fn dans une transaction ; annule tout en cas d'exception.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
