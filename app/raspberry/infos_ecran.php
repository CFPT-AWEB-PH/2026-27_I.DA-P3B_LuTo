<?php
/**
 * raspberry/infos_ecran.php : infos d'écran du Raspberry principal, en JSON (--fond : l'image).
 * Lancé par raspberry/sabre-fond-ecran.py avec « docker exec sabre_web php … » :
 * le dossier raspberry/ n'est pas servi par Apache, et ce script refuse tout sauf la ligne de commande.
 */
if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../includes/securite.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/identifiants.php';
require_once __DIR__ . '/../includes/wifi.php';
require_once __DIR__ . '/../includes/raspberry.php';
ensureRaspberrySchema($pdo);

$r = $pdo->query('SELECT id, nom, user_id, fond_version FROM raspberrys WHERE principal = 1 LIMIT 1')->fetch();
if (!$r) exit(1);

if (in_array('--fond', $argv, true)) {
    $st = $pdo->prepare('SELECT fond FROM raspberrys WHERE id = ?');
    $st->execute([$r['id']]);
    echo (string)$st->fetchColumn();
    exit;
}
echo json_encode(raspberryInfos($pdo, $r), JSON_UNESCAPED_UNICODE);
