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
$termine = $combat['statut'] === 'termine';

echo json_encode([
    'id'              => (int)$combat['id'],
    'statut'          => $combat['statut'],
    // En direct : score recalculé selon le mode actuel ; terminé : résultat enregistré
    'score_joueur1'   => round((float)($termine ? $combat['score_joueur1'] : $etat['j1']), 2),
    'score_joueur2'   => round((float)($termine ? $combat['score_joueur2'] : $etat['j2']), 2),
    'temps_restant'   => tempsRestant($combat),
    'en_pause'        => !empty($combat['en_pause_depuis']),
    'vainqueur_id'    => $combat['vainqueur_id'],
    'vainqueur_pseudo'=> $combat['vainqueur_pseudo'],
    'manche_actuelle' => (int)$combat['manche_actuelle'],
    'ecart'           => $etat['ecart'],
    'leader'          => $etat['leader'],
    'pv'              => $etat['pv'],
    'mode'            => $etat['mode'],
    'officiel_j1'     => $etat['j1'],
    'officiel_j2'     => $etat['j2'],
    'arbitres'        => arbitresPourJson($etat),
    'avantage'        => libelleAvantage($etat, $combat['joueur1_pseudo'], $combat['joueur2_pseudo'], $combat['statut'] === 'termine'),
    'date_prevue_ms'  => dateVersMs($combat['date_prevue']),
    'server_now_ms'   => (int)round(microtime(true) * 1000),
]);
