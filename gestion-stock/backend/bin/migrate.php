<?php

declare(strict_types=1);

// Usage : php bin/migrate.php [--status]

use App\Database;
use App\Migrator;

require __DIR__ . '/../bootstrap.php';

$migrator = new Migrator(Database::connection(), MIGRATIONS_DIR);

if (in_array('--status', $argv, true)) {
    $applied = $migrator->applied();
    foreach (array_keys($migrator->files()) as $version) {
        printf("%-10s %s\n", isset($applied[$version]) ? 'appliquée' : 'EN ATTENTE', $version);
    }
    exit(0);
}

$count = $migrator->migrate(static function (string $line): void {
    echo $line, PHP_EOL;
});
echo $count === 0 ? "Base à jour, aucune migration en attente.\n" : "{$count} migration(s) appliquée(s).\n";
