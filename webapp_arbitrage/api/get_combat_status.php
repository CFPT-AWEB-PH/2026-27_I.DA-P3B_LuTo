<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id manquant']);
    exit;
}

purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $id);
if (!$combat) {
    http_response_code(404);
    echo json_encode(['error' => 'combat introuvable']);
    exit;
}

echo json_encode([
    'id'              => (int)$combat['id'],
    'statut'          => $combat['statut'],
    'score_joueur1'   => round((float)$combat['score_joueur1'], 2),
    'score_joueur2'   => round((float)$combat['score_joueur2'], 2),
    'temps_restant'   => tempsRestant($combat),
    'vainqueur_id'    => $combat['vainqueur_id'],
    'vainqueur_pseudo'=> $combat['vainqueur_pseudo'],
    'manche_actuelle' => (int)$combat['manche_actuelle'],
]);
