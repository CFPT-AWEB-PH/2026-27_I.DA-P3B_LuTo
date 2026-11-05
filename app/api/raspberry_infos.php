<?php
/**
 * api/raspberry_infos.php : infos d'écran d'un Raspberry connecté au Wi-Fi.
 * Authentifié par son jeton (en-tête X-Sabre-Jeton, donné par Admin › Raspberry) :
 * il ne donne accès qu'au compte affiché sur CE Raspberry. ?fond=1 : l'image de fond.
 * Pas de config.php : appelé toutes les 15 s par chaque Raspberry, sans session.
 */
require_once __DIR__ . '/../includes/securite.php';
initialiserErreurs();
envoyerEntetesSecurite();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/identifiants.php';
require_once __DIR__ . '/../includes/wifi.php';
require_once __DIR__ . '/../includes/raspberry.php';
ensureRaspberrySchema($pdo);

header('Cache-Control: no-store');
$r = raspberryDepuisJeton($pdo);

if (isset($_GET['fond'])) {
    $st = $pdo->prepare('SELECT fond FROM raspberrys WHERE id = ?');
    $st->execute([$r['id']]);
    $image = $st->fetchColumn();
    if (!$image) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    echo $image;
    exit;
}

$pdo->prepare('UPDATE raspberrys SET derniere_vue = NOW() WHERE id = ?')->execute([$r['id']]);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(raspberryInfos($pdo, $r), JSON_UNESCAPED_UNICODE);
