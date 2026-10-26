<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

exigerLimiteDebit($pdo, 'api_public', ipClient(), 600, 60);

$id = idPositif($_GET['id'] ?? '');
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id manquant ou invalide']);
    exit;
}

purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $id);
if (!$combat) {
    http_response_code(404);
    echo json_encode(['error' => 'combat introuvable']);
    exit;
}

$etat = etatScore($pdo, $combat);

echo json_encode([
    'id'              => (int)$combat['id'],
    'statut'          => $combat['statut'],
    'score_joueur1'   => round((float)$combat['score_joueur1'], 2),
    'score_joueur2'   => round((float)$combat['score_joueur2'], 2),
    'temps_restant'   => tempsRestant($combat),
    'en_pause'        => !empty($combat['en_pause_depuis']),
    'vainqueur_id'    => $combat['vainqueur_id'],
    'vainqueur_pseudo'=> $combat['vainqueur_pseudo'],
    'manche_actuelle' => (int)$combat['manche_actuelle'],
    'ecart'           => $etat['ecart'],
    'leader'          => $etat['leader'],
    'pv'              => $etat['pv'],
    'avantage'        => libelleAvantage($etat, $combat['joueur1_pseudo'], $combat['joueur2_pseudo'], $combat['statut'] === 'termine'),
    'date_prevue_ms'  => dateVersMs($combat['date_prevue']),
    'server_now_ms'   => (int)round(microtime(true) * 1000),
]);
