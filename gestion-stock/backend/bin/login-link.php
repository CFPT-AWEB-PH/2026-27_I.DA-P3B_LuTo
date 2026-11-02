<?php

declare(strict_types=1);

// Génère un lien de connexion à usage unique (accès SSH au serveur requis).
// Usage : php bin/login-link.php <email> [minutes]
// L'adresse doit figurer dans la liste d'autorisation ; elle est vérifiée à l'ouverture du lien.

use App\Auth\Allowlist;
use App\Auth\LoginLinks;
use App\Config;

require __DIR__ . '/../bootstrap.php';

$email = Allowlist::normalizeEmail($argv[1] ?? '');
$minutes = (int) ($argv[2] ?? 15);
if (!Allowlist::isValidEmail($email) || $minutes < 1 || $minutes > 1440) {
    fwrite(STDERR, "Usage : php bin/login-link.php <email> [minutes, 1 à 1440]\n");
    exit(2);
}
if (!LoginLinks::enabled()) {
    fwrite(STDERR, "La connexion par lien est désactivée (AUTH_LOGIN_LINKS=0).\n");
    exit(1);
}
if (!Allowlist::isAllowed($email)) {
    fwrite(STDERR, "Attention : {$email} ne figure pas dans la liste d'autorisation, le lien sera refusé.\n");
}

$token = LoginLinks::create($email, $minutes);
$base = rtrim(Config::get('APP_URL', 'http://localhost') ?? '', '/');
echo "{$base}/api/auth/link?token={$token}\n";
echo "Valable {$minutes} minute(s), utilisable une seule fois.\n";
