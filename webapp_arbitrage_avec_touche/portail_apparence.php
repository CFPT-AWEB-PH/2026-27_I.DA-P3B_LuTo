<?php
/**
 * portail_apparence.php : apparence de l'application pour le portail Wi-Fi du
 * Raspberry (raspberry/sabre-portail.py), qui la relit toutes les 15 s :
 * nom de l'application, couleurs du thème actif et logo (PNG 192 px en base64).
 *
 * Même principe que manifest.php / logo.php : pas de session, uniquement des
 * informations déjà publiques (affichées à tous les visiteurs du site).
 */
require_once __DIR__ . '/includes/securite.php';
initialiserErreurs();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apparence.php';

$logo = null;
$id = logoActifId($pdo);
if ($id) {
    $st = $pdo->prepare('SELECT f192 FROM logos WHERE id = ?');
    $st->execute([$id]);
    $png = $st->fetchColumn();
    if (is_string($png) && $png !== '') $logo = base64_encode($png);
}

$reponse = [
    'nom' => getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage'),
    'theme' => themeActif($pdo),
    'couleurs' => paletteThemeActif($pdo),
    'logo' => $logo,          // null = logo d'origine (la lame)
];

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($reponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
