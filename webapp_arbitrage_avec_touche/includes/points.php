<?php
/**
 * includes/points.php : comptage des points en direct.
 *
 * Règles :
 *  - Chaque arbitre donne des points pendant le combat avec les boutons
 *    +1, +2, +3, pour Aka (joueur 1, rouge, à gauche) ou Ao (joueur 2, bleu).
 *  - Le score officiel d'un joueur = le total LE PLUS BAS parmi les 3 arbitres
 *    (un arbitre qui n'a rien donné compte pour 0).
 *  - Points de victoire selon l'écart entre les deux scores :
 *      écart 0 = 0 (égalité), 1 à 5 = 1 pt, 6 à 15 = 2 pts, plus de 15 = 3 pts.
 */

const VALEURS_POINTS = [1, 2, 3];

function pointsVictoire(float $ecart): int
{
    $ecart = abs($ecart);
    if ($ecart <= 0) return 0;
    if ($ecart <= 5) return 1;
    if ($ecart <= 15) return 2;
    return 3;
}

/**
 * État complet du score d'un combat.
 * Retourne j1, j2 (scores officiels), ecart, leader (1, 2 ou 0), pv (points de
 * victoire du leader) et par_arbitre : [user_id => [username, role, j1, j2, nb]].
 */
function etatScore(PDO $pdo, array $combat): array
{
    $combatId = (int)$combat['id'];
    $arbitres = getArbitresDuCombat($pdo, $combatId);
    $par = [];
    foreach ($arbitres as $a) {
        $par[(int)$a['user_id']] = ['username' => $a['username'], 'role' => $a['role'], 'j1' => 0, 'j2' => 0, 'nb' => 0];
    }
    try {
        $st = $pdo->prepare(
            'SELECT arbitre_user_id, joueur_num, SUM(valeur) AS total, COUNT(*) AS nb
             FROM points_live WHERE combat_id = ? AND annule = 0
             GROUP BY arbitre_user_id, joueur_num'
        );
        $st->execute([$combatId]);
        foreach ($st->fetchAll() as $r) {
            $uid = (int)$r['arbitre_user_id'];
            if (!isset($par[$uid])) continue; // arbitre retiré du combat : ignoré
            $par[$uid]['j' . (int)$r['joueur_num']] = (int)$r['total'];
            $par[$uid]['nb'] += (int)$r['nb'];
        }
    } catch (PDOException $e) { /* table absente */ }

    if ($par && count($par) >= 1) {
        $j1 = min(array_column($par, 'j1'));
        $j2 = min(array_column($par, 'j2'));
    } else {
        $j1 = 0; $j2 = 0;
    }
    // Combat terminé avant la mise en place des boutons : on garde le score enregistré
    $aucunPoint = array_sum(array_column($par, 'nb')) === 0;
    if ($aucunPoint && $combat['statut'] === 'termine') {
        $j1 = (float)$combat['score_joueur1'];
        $j2 = (float)$combat['score_joueur2'];
    }

    $ecart = abs($j1 - $j2);
    $leader = $j1 > $j2 ? 1 : ($j2 > $j1 ? 2 : 0);
    return [
        'j1' => $j1, 'j2' => $j2,
        'ecart' => $ecart,
        'leader' => $leader,
        'pv' => pointsVictoire($ecart),
        'par_arbitre' => $par,
        'nb_arbitres' => count($par),
    ];
}

/** Recalcule le score officiel et l'enregistre dans la table combats. */
function majScoreCombat(PDO $pdo, int $combatId): array
{
    $combat = getCombat($pdo, $combatId);
    $etat = etatScore($pdo, $combat);
    $pdo->prepare('UPDATE combats SET score_joueur1 = ?, score_joueur2 = ? WHERE id = ?')
        ->execute([$etat['j1'], $etat['j2'], $combatId]);
    return $etat;
}

/** Un arbitre ajoute 1, 2 ou 3 points à un joueur. */
function ajouterPointLive(PDO $pdo, int $combatId, int $userId, int $joueurNum, int $valeur): array
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat) return ['ok' => false, 'message' => 'Combat introuvable.'];
    if ($combat['statut'] !== 'en_cours') return ['ok' => false, 'message' => 'Le combat n\'est pas en cours.'];
    if (!empty($combat['en_pause_depuis'])) return ['ok' => false, 'message' => 'Combat en pause : point non enregistré.'];
    if (!in_array($joueurNum, [1, 2], true) || !in_array($valeur, VALEURS_POINTS, true)) {
        return ['ok' => false, 'message' => 'Valeur invalide.'];
    }
    $st = $pdo->prepare('SELECT 1 FROM combat_arbitres WHERE combat_id = ? AND user_id = ?');
    $st->execute([$combatId, $userId]);
    if (!$st->fetch()) return ['ok' => false, 'message' => 'Vous n\'êtes pas arbitre de ce combat.'];

    $pdo->prepare('INSERT INTO points_live (combat_id, arbitre_user_id, joueur_num, valeur) VALUES (?, ?, ?, ?)')
        ->execute([$combatId, $userId, $joueurNum, $valeur]);
    majScoreCombat($pdo, $combatId);
    return ['ok' => true, 'message' => ''];
}

/** Annule le dernier point donné par cet arbitre sur ce combat. */
function annulerDernierPoint(PDO $pdo, int $combatId, int $userId): array
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat || $combat['statut'] !== 'en_cours') return ['ok' => false, 'message' => 'Le combat n\'est pas en cours.'];
    if (!empty($combat['en_pause_depuis'])) return ['ok' => false, 'message' => 'Combat en pause.'];
    $st = $pdo->prepare('SELECT id, joueur_num, valeur FROM points_live WHERE combat_id = ? AND arbitre_user_id = ? AND annule = 0 ORDER BY id DESC LIMIT 1');
    $st->execute([$combatId, $userId]);
    $dernier = $st->fetch();
    if (!$dernier) return ['ok' => false, 'message' => 'Aucun point à annuler.'];
    $pdo->prepare('UPDATE points_live SET annule = 1 WHERE id = ?')->execute([$dernier['id']]);
    majScoreCombat($pdo, $combatId);
    return ['ok' => true, 'message' => '+' . $dernier['valeur'] . ' annulé.'];
}

/** Derniers points donnés par un arbitre (pour son historique personnel). */
function derniersPointsArbitre(PDO $pdo, int $combatId, int $userId, int $limite = 12): array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, joueur_num, valeur, annule, created_at FROM points_live
             WHERE combat_id = ? AND arbitre_user_id = ? ORDER BY id DESC LIMIT ' . (int)$limite
        );
        $st->execute([$combatId, $userId]);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Libellé de la barre « qui gagne » (au présent pendant le combat, au passé après). */
function libelleAvantage(array $etat, string $j1, string $j2, bool $termine = false): string
{
    if ($etat['leader'] === 0) return $termine ? 'Match nul' : 'Égalité';
    $nom = $etat['leader'] === 1 ? $j1 : $j2;
    $pv = $etat['pv'];
    $ecart = rtrim(rtrim(number_format($etat['ecart'], 1, '.', ''), '0'), '.');
    $pvTexte = $pv . ' point' . ($pv > 1 ? 's' : '') . ' de victoire';
    return $termine
        ? $nom . ' gagne de ' . $ecart . ' : ' . $pvTexte
        : $nom . ' mène de ' . $ecart . ' : ' . $pvTexte;
}

/**
 * Barre « qui gagne » : part rouge (Aka) à gauche, part bleue (Ao) à droite,
 * proportionnelles aux scores, et points de victoire du leader.
 * Mise à jour en direct par live.js / scoring.js via les classes js-*.
 */
function barreAvantage(array $etat, string $j1, string $j2, string $classe = '', bool $termine = false): string
{
    $libelle = libelleAvantage($etat, $j1, $j2, $termine);
    $total = $etat['j1'] + $etat['j2'];
    $part = $total > 0 ? round($etat['j1'] / $total * 100, 1) : 50;
    $pv = (int)$etat['pv'];
    ob_start(); ?>
    <div class="lead <?= e($classe) ?> js-lead" data-leader="<?= (int)$etat['leader'] ?>">
        <div class="lead-bar" role="img" aria-label="<?= e($libelle) ?>">
            <span class="lead-aka js-lead-aka" style="width:<?= $part ?>%"></span>
            <span class="lead-ao"></span>
            <span class="lead-mid" aria-hidden="true"></span>
        </div>
        <div class="lead-info">
            <span class="lead-text js-lead-text"><?= e($libelle) ?></span>
            <span class="lead-pv js-lead-pv" data-pv="<?= $pv ?>" title="Points de victoire : écart 1 à 5 = 1, 6 à 15 = 2, plus de 15 = 3">
                <i class="<?= $pv >= 1 ? 'on' : '' ?>"></i><i class="<?= $pv >= 2 ? 'on' : '' ?>"></i><i class="<?= $pv >= 3 ? 'on' : '' ?>"></i>
            </span>
        </div>
    </div>
    <?php return ob_get_clean();
}
