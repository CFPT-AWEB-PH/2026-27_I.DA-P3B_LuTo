<?php
/**
 * api/points.php : score en direct d'un combat (boutons +1 / +2 / +3).
 *
 * GET  ?combat_id=X
 *      → { statut, j1, j2, ecart, leader, pv, avantage, temps_restant, mes:{j1,j2}, arbitres:[...] }
 * POST combat_id, csrf, action=ajouter, joueur=1|2, valeur=1|2|3
 * POST combat_id, csrf, action=annuler            (annule mon dernier point)
 */
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$combatId = idPositif($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['combat_id'] ?? '') : ($_GET['combat_id'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
exigerLimiteDebit($pdo, 'api_public', ipClient(), 600, 60);
purgerCombatsExpires($pdo);
$combat = $combatId ? getCombat($pdo, $combatId) : null;
if (!$combat) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Combat introuvable.']);
    exit;
}

$user = currentUser();
$message = '';
$ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user || !can('arbitrage.voter')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Votre rôle ne permet pas de donner des points.']);
        exit;
    }
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Session expirée, rechargez la page.']);
        exit;
    }
    // 4 appuis par seconde en moyenne au maximum pour un même arbitre
    exigerLimiteDebit($pdo, 'points_saisie', (string)$user['id'], 240, 60);
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['ajouter', 'annuler'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Action inconnue.']);
        exit;
    }
    $r = $action === 'annuler'
        ? annulerDernierPoint($pdo, $combatId, (int)$user['id'])
        : ajouterPointLive($pdo, $combatId, (int)$user['id'], idPositif($_POST['joueur'] ?? ''), idPositif($_POST['valeur'] ?? ''));
    $ok = $r['ok'];
    $message = $r['message'];
    $combat = getCombat($pdo, $combatId);
}

$etat = etatScore($pdo, $combat);
$mes = ($user && isset($etat['par_arbitre'][(int)$user['id']])) ? $etat['par_arbitre'][(int)$user['id']] : null;

$reponse = [
    'ok'            => $ok,
    'message'       => $message,
    'statut'        => $combat['statut'],
    'j1'            => $etat['j1'],
    'j2'            => $etat['j2'],
    'ecart'         => $etat['ecart'],
    'leader'        => $etat['leader'],
    'pv'            => $etat['pv'],
    'avantage'      => libelleAvantage($etat, $combat['joueur1_pseudo'], $combat['joueur2_pseudo'], $combat['statut'] === 'termine'),
    'temps_restant' => tempsRestant($combat),
    'en_pause'      => !empty($combat['en_pause_depuis']),
    'mes'           => $mes ? ['j1' => $mes['j1'], 'j2' => $mes['j2']] : null,
];
if ($user && can('arbitrage.acces', 'arbitrage.tous_combats', 'combats.voir')) {
    $reponse['arbitres'] = array_values(array_map(fn($a) => ['nom' => $a['username'], 'role' => $a['role'], 'j1' => $a['j1'], 'j2' => $a['j2']], $etat['par_arbitre']));
    if ($mes) {
        $reponse['historique'] = array_map(fn($p) => [
            'id' => (int)$p['id'], 'joueur' => (int)$p['joueur_num'], 'valeur' => (int)$p['valeur'],
            'annule' => (bool)$p['annule'], 'heure' => date('H:i:s', strtotime($p['created_at'])),
        ], derniersPointsArbitre($pdo, $combatId, (int)$user['id']));
    }
}
echo json_encode($reponse);
