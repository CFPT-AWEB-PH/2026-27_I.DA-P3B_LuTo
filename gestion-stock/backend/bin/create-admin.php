<?php

declare(strict_types=1);

// Initialisation sécurisée du premier administrateur (accès SSH au serveur requis).
// Usage : php bin/create-admin.php <email> [--force]
//   Ajoute l'adresse à la liste d'autorisation et lui attribue le rôle administrateur.
//   Refusé si un administrateur actif existe déjà, sauf avec --force (récupération d'accès).

use App\Audit;
use App\Auth\AdminGuard;
use App\Auth\Allowlist;
use App\Database;

require __DIR__ . '/../bootstrap.php';

$email = Allowlist::normalizeEmail($argv[1] ?? '');
$force = in_array('--force', $argv, true);
if (!Allowlist::isValidEmail($email)) {
    fwrite(STDERR, "Usage : php bin/create-admin.php <email> [--force]\n");
    exit(2);
}

try {
    $userId = Database::transaction(static function (PDO $pdo) use ($email, $force): int {
        AdminGuard::lock($pdo);
        if (!$force && AdminGuard::activeAdminCount($pdo) > 0) {
            throw new RuntimeException('Un administrateur actif existe déjà. Utilisez l\'interface, ou --force pour une récupération d\'accès.');
        }
        $adminRole = (int) $pdo->query('SELECT id FROM roles WHERE is_protected = 1 LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT IGNORE INTO allowlist (kind, value, note) VALUES ('email', ?, 'Administrateur initial')")->execute([$email]);
        $pdo->prepare(
            'INSERT INTO users (email, display_name, role_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), is_active = 1'
        )->execute([$email, ucwords(str_replace('.', ' ', strstr($email, '@', true))), $adminRole]);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    });
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusé : ' . $e->getMessage() . "\n");
    exit(1);
}

Audit::log(null, 'auth.bootstrap_admin', 'user', $userId, ['email' => $email, 'via' => 'cli', 'force' => $force]);
echo "{$email} est administrateur (utilisateur n° {$userId}).\n";
echo "Pour vous connecter : php bin/login-link.php {$email}\n";
