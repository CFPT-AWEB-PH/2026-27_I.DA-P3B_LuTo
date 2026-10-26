<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Données techniques des sabres (adresses MAC, activité) : réservées à l'administration
if (!can('sabres.voir')) {
    http_response_code(isLoggedIn() ? 403 : 401);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}
exigerLimiteDebit($pdo, 'api_sabres', (string)currentUser()['id'], 300, 60);
ensureSabresSchema($pdo);

$rows = $pdo->query(
    "SELECT
        s.id, s.nom, s.mac_address, s.last_seen, s.last_hit_at,
        TIMESTAMPDIFF(SECOND, s.last_seen, NOW())    AS last_seen_s,
        CASE WHEN s.last_hit_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
             THEN 'touche' ELSE NULL END             AS last_touch_type,
        CASE WHEN s.last_hit_at IS NOT NULL
             THEN ROUND(TIMESTAMPDIFF(MICROSECOND, s.last_hit_at, NOW()) / 1000)
             ELSE NULL END                           AS last_touch_ago_ms
     FROM sabres s
     WHERE s.actif = 1
     ORDER BY s.nom"
)->fetchAll(PDO::FETCH_ASSOC);

$sabres = array_map(function ($s) {
    $ls = $s['last_seen_s'];
    return [
        'id'                => (int) $s['id'],
        'nom'               => $s['nom'],
        'mac'               => $s['mac_address'],
        'connected'         => $s['last_seen'] !== null && $ls !== null && (int)$ls < 60,
        'recent'            => $s['last_seen'] !== null && $ls !== null && (int)$ls < 300,
        'last_seen_s'       => $ls !== null ? (int) $ls : null,
        'last_touch_type'   => $s['last_touch_type'],
        'last_touch_ago_ms' => $s['last_touch_ago_ms'] !== null ? (int) $s['last_touch_ago_ms'] : null,
    ];
}, $rows);

echo json_encode(['sabres' => $sabres]);
