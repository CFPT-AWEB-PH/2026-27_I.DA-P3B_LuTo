<?php
/**
 * Bootstrap de l'application : session, DB, helpers globaux
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

define('BASE_URL', '/webapp_arbitrage/'); // adapter si l'app est dans un sous-dossier

// IMPORTANT : on garde PHP et MySQL sur le même fuseau (UTC) pour que les calculs
// de temps écoulé (combat en cours, chrono) soient toujours exacts. L'affichage
// des dates aux utilisateurs peut rester en heure locale via formatDateFr().
date_default_timezone_set('UTC');
