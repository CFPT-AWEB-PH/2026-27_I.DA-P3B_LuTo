<?php
/**
 * logo.php : sert une déclinaison d'un logo personnalisé (stocké en base).
 *   logo.php?id=3&f=marque|f32|f180|f192|f512|maskable
 *
 * Volontairement minimal (pas de session, pas de redirection « changer le mot
 * de passe ») : c'est une image publique, affichée à tous les visiteurs.
 * Le contenu d'un id ne change jamais : mise en cache longue durée.
 */
require_once __DIR__ . '/includes/securite.php';
initialiserErreurs();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apparence.php';

$id = idPositif($_GET['id'] ?? '');
$format = (string)($_GET['f'] ?? 'marque');
if (!$id || !in_array($format, LOGO_FORMATS, true)) {
    http_response_code(404);
    exit;
}

try {
    // $format vient de la liste blanche LOGO_FORMATS : sûr comme nom de colonne
    $st = $pdo->prepare("SELECT `$format` AS img FROM logos WHERE id = ?");
    $st->execute([$id]);
    $img = $st->fetchColumn();
} catch (PDOException $e) {
    $img = false;
}
if ($img === false || $img === null) {
    http_response_code(404);
    exit;
}

header_remove('X-Powered-By');
header('Content-Type: image/png');
header('Content-Length: ' . strlen($img));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
header('Cross-Origin-Resource-Policy: same-origin');
echo $img;
