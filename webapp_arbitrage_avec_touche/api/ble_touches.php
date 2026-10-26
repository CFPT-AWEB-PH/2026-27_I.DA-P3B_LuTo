<?php
/**
 * api/ble_touches.php : touches BLE d'un combat.
 *
 * GET ?combat_id=X&historique=1
 *   → historique complet regroupé (doubles touches fusionnées) + totaux.
 *     { statut, joueur1, joueur2, total, touches:[{id, ids, type, joueur_num, joueur, heure, match_s, ts_ms}],
 *       compte:{j1, j2, doubles}, dernier_id, server_now_ms }
 *
 * GET ?combat_id=X&since_id=Y   (format historique utilisé par l'écran de salle)
 *   → { touches:[{id, type, joueur_num, joueur_pseudo, timestamp_ms}], dernier_id }
 */
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

exigerLimiteDebit($pdo, 'api_public', ipClient(), 600, 60);

$combatId = idPositif($_GET['combat_id'] ?? '');
if ($combatId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'combat_id requis']);
    exit;
}

purgerCombatsExpires($pdo);
$combat = getCombat($pdo, $combatId);
if (!$combat) {
    http_response_code(404);
    echo json_encode(['error' => 'combat introuvable']);
    exit;
}

if (!empty($_GET['historique'])) {
    $touches = getTouchesCombat($pdo, $combat);
    $groupes = regrouperTouches($touches);
    echo json_encode([
        'statut'        => $combat['statut'],
        'joueur1'       => $combat['joueur1_pseudo'],
        'joueur2'       => $combat['joueur2_pseudo'],
        'total'         => count($groupes),
        'touches'       => $groupes,
        'compte'        => compterTouches($touches),
        'dernier_id'    => $touches ? max(array_column($touches, 'id')) : 0,
        'server_now_ms' => (int)round(microtime(true) * 1000),
    ]);
    exit;
}

$sinceId = max(0, min(PHP_INT_MAX - 1, (int)($_GET['since_id'] ?? 0)));
$touches = getTouchesCombat($pdo, $combat, max(0, $sinceId));
echo json_encode([
    'touches' => array_map(fn($t) => [
        'id'            => $t['id'],
        'type'          => $t['type'],
        'joueur_num'    => $t['joueur_num'],
        'joueur_pseudo' => $t['joueur'] ?? '?',
        'timestamp_ms'  => $t['ts_ms'],
        'match_s'       => $t['match_s'],
    ], array_slice($touches, -20)),
    'dernier_id' => $touches ? max(array_column($touches, 'id')) : $sinceId,
]);
