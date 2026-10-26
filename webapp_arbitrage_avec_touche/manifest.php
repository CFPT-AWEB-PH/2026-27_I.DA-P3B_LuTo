<?php
/**
 * manifest.php : manifeste de l'application installable (PWA), généré selon
 * le nom de l'application, le thème et le logo choisis dans Admin › Apparence.
 * Même principe que logo.php : pas de session (lu par le navigateur sans cookie).
 */
require_once __DIR__ . '/includes/securite.php';
initialiserErreurs();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apparence.php';

$base = trim((string)(getenv('BASE_URL') ?: ''), '/');
if (!preg_match('#^[A-Za-z0-9_\-/]*$#', $base)) $base = '';
$base = $base === '' ? '/' : '/' . $base . '/';
if (!defined('BASE_URL')) define('BASE_URL', $base);

$nom = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');
$theme = themeActif($pdo);
$themes = themesDisponibles($pdo);
$fond = paletteThemeActif($pdo)['fond'];
$id = logoActifId($pdo);

if ($id) {
    $icones = [
        ['src' => $base . "logo.php?id=$id&f=f192", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . "logo.php?id=$id&f=f512", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . "logo.php?id=$id&f=maskable", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
} else {
    $icones = [
        ['src' => $base . 'assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'assets/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
}
$petite = $icones[0]['src'];

$manifeste = [
    'id' => $base,
    'name' => $nom,
    'short_name' => nomCourtApplication($nom),
    'description' => 'Combats de sabre laser en direct, arbitrage à trois, résultats et classement.',
    'lang' => 'fr',
    'dir' => 'ltr',
    'start_url' => $base . 'index.php?source=pwa',
    'scope' => $base,
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'orientation' => 'any',
    'background_color' => $fond,
    'theme_color' => $themes[$theme][3],
    'categories' => ['sports', 'utilities'],
    'icons' => $icones,
    'shortcuts' => [
        ['name' => 'Combats en direct', 'short_name' => 'Direct', 'url' => $base . 'index.php', 'icons' => [['src' => $petite, 'sizes' => '192x192']]],
        ['name' => 'Espace arbitrage', 'short_name' => 'Arbitrage', 'url' => $base . 'arbitrage/index.php', 'icons' => [['src' => $petite, 'sizes' => '192x192']]],
        ['name' => 'Classement', 'short_name' => 'Classement', 'url' => $base . 'classement.php', 'icons' => [['src' => $petite, 'sizes' => '192x192']]],
    ],
];

header_remove('X-Powered-By');
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
echo json_encode($manifeste, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
