<?php
/**
 * Connexion à la base de données MariaDB (PDO)
 */

// Identifiants lus UNIQUEMENT depuis l'environnement (fichier .env via docker-compose).
// Aucun identifiant de secours dans le code : sans configuration, on refuse de démarrer.
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'sabre_arbitrage';
$DB_USER = getenv('DB_USER');
$DB_PASS = getenv('DB_PASS');

try {
    if ($DB_USER === false || $DB_USER === '' || $DB_PASS === false) {
        throw new PDOException('DB_USER / DB_PASS non définis dans l\'environnement');
    }
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // HEURE DU SERVEUR : toute l'application (PHP + MySQL) travaille dans le
    // fuseau horaire du serveur (variable TZ du docker-compose, Europe/Zurich
    // par défaut). PHP et la connexion MySQL utilisent exactement le même
    // décalage, donc NOW(), time(), les heures prévues saisies dans les
    // formulaires et les heures affichées sont toutes cohérentes.
    $APP_TZ = getenv('APP_TZ') ?: getenv('TZ') ?: 'Europe/Zurich';
    if (!in_array($APP_TZ, timezone_identifiers_list(), true)) {
        $APP_TZ = 'Europe/Zurich';
    }
    date_default_timezone_set($APP_TZ);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    // On ne montre jamais le détail de l'exception PDO au visiteur (hôte,
    // nom de base, etc. pourraient fuiter) : on le journalise côté serveur
    // et on affiche un message générique, sobre mais cohérent avec le reste
    // du site (même si le header.php habituel ne peut pas être chargé ici,
    // faute de connexion à la base pour les paramètres qu'il lit).
    error_log('[Sabre Laser Arbitrage] Échec de connexion à la base de données : ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Service temporairement indisponible : Sabre Laser Arbitrage</title>
        <style>
            body { background:#0a0c1a; color:#eef1fb; font-family: system-ui, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding: 2rem; text-align:center; }
            .box { max-width: 480px; }
            h1 { font-size: 1.4rem; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Service temporairement indisponible</h1>
            <p>La base de données ne répond pas pour le moment. Merci de réessayer dans quelques instants ; si le problème persiste, contactez l'administrateur.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
