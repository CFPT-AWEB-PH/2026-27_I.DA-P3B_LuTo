<?php
/**
 * guide_image.php : image d'une fiche du guide, choisie par un administrateur.
 * Stockée en base (le code est monté en lecture seule). Seules les fiches que le
 * visiteur peut lire sont servies.
 */
require_once __DIR__ . '/config/config.php';

$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$image = null;
if (isset(guideFichesVisibles($pdo)[$id])) {
    $st = $pdo->prepare('SELECT image FROM guide_images WHERE fiche_id = ?');
    $st->execute([$id]);
    $image = $st->fetchColumn();
}
if (!$image) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=31536000');   // l'URL contient la version de l'image
echo $image;
