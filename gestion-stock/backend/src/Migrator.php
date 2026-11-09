<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Applique les fichiers database/migrations/NNNN_nom.sql dans l'ordre.
 * Un fichier déjà appliqué ne doit plus être modifié : son empreinte est vérifiée.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    /** @return array<string, string> version => chemin */
    public function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, string> version => sha256 */
    public function applied(): array
    {
        $this->ensureTable();
        return $this->pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string, string> */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];
        foreach ($this->files() as $version => $path) {
            if (!isset($applied[$version])) {
                $pending[$version] = $path;
            } elseif (!hash_equals($applied[$version], hash_file('sha256', $path))) {
                throw new \RuntimeException("La migration {$version} a été modifiée après son application.");
            }
        }
        return $pending;
    }

    /** @param callable(string): void $log */
    public function migrate(callable $log): int
    {
        if ((int) $this->pdo->query("SELECT GET_LOCK('schema_migrations', 30)")->fetchColumn() !== 1) {
            throw new \RuntimeException('Une autre migration est déjà en cours.');
        }
        try {
            $count = 0;
            foreach ($this->pending() as $version => $path) {
                $log("Application de {$version}…");
                foreach (self::splitStatements((string) file_get_contents($path)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
                $insert->execute([$version, hash_file('sha256', $path)]);
                $count++;
            }
            return $count;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('schema_migrations')");
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> Les migrations n'utilisent ni procédures ni « ; » en fin de ligne dans les chaînes. */
    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
