<?php
/**
 * includes/points.php : comptage des points en direct.
 *
 * Chaque arbitre donne des points pendant le combat avec les boutons
 * +1, +2, +3, pour Aka (joueur 1, rouge) ou Ao (joueur 2, bleu).
 *
 * Le calcul du score officiel se choisit dans Admin › Paramètres › Score
 * (paramètre « mode_score ») :
 *  - moyenne_bareme (par défaut) : score = moyenne des totaux des arbitres ;
 *      à la fin, l'écart entre les deux moyennes donne les points de victoire
 *      selon le barème : 0 = égalité, jusqu'à seuil 1 (5) = 1 pt,
 *      jusqu'à seuil 2 (10) = 2 pts, au-delà = 3 pts ;
 *  - moyenne : score = moyenne des totaux, le plus haut gagne (1 pt de victoire) ;
 *  - minimum : score = le total LE PLUS BAS des arbitres, points de victoire
 *      selon le même barème.
 * Un arbitre qui n'a rien donné compte pour 0.
 */

const VALEURS_POINTS = [1, 2, 3];

/** Modes de calcul proposés dans les paramètres : clé => [libellé, explication]. */
function modesScore(): array
{
    return [
        'moyenne_bareme' => ['Moyenne des arbitres + barème', 'Score = moyenne des points des arbitres. L\'écart entre les deux moyennes donne 1, 2 ou 3 points de victoire selon le barème.'],
        'moyenne'        => ['Moyenne des arbitres', 'Score = moyenne des points des arbitres. Le plus haut gagne (1 point de victoire).'],
        'minimum'        => ['Total le plus bas des arbitres', 'Score = le total le plus bas des arbitres : un point ne compte que si tous l\'ont donné. Points de victoire selon le barème.'],
    ];
}

/** Sens d'affichage proposés : clé => libellé. */
function sensDisponibles(): array
{
    return [
        'aka_gauche' => 'Aka (rouge) à gauche, Ao (bleu) à droite',
        'ao_gauche'  => 'Ao (bleu) à gauche, Aka (rouge) à droite',
    ];
}

/**
 * Ao est-il affiché à gauche ? Réglage « sens_aka_ao » des paramètres.
 * L'écran de salle est vu depuis l'autre côté de l'aire : il est inversé
 * par rapport aux autres pages quand « ecran_inverse » est activé.
 */
function aoAGauche(PDO $pdo, bool $ecranSalle = false): bool
{
    $ao = getParametre($pdo, 'sens_aka_ao', 'aka_gauche') === 'ao_gauche';
    if ($ecranSalle && getParametreInt($pdo, 'ecran_inverse', 1) === 1) $ao = !$ao;
    return $ao;
}

/** Crée les réglages du score et du sens s'ils n'existent pas encore (mise à jour sans migration). */
function assurerParametresScore(PDO $pdo): void
{
    $defauts = [
        'mode_score'     => ['moyenne_bareme', 'Calcul du score officiel'],
        'bareme_seuil_1' => ['5', 'Écart maximum pour 1 point de victoire'],
        'bareme_seuil_2' => ['10', 'Écart maximum pour 2 points de victoire'],
        'sens_aka_ao'    => ['aka_gauche', 'Côté d\'affichage de Aka et Ao'],
        'ecran_inverse'  => ['1', 'Écran de salle inversé'],
    ];
    $connues = getTousLesParametres($pdo);
    $manque = array_diff_key($defauts, $connues);
    if (!$manque) return;
    $st = $pdo->prepare('INSERT IGNORE INTO parametres (cle, valeur, description) VALUES (?, ?, ?)');
    foreach ($manque as $cle => [$val, $desc]) $st->execute([$cle, $val, $desc]);
    getTousLesParametres($pdo, true);
}

/** Règles de score en vigueur : mode + seuils du barème (lues une fois par requête). */
function reglesScore(?PDO $pdo = null): array
{
    static $regles = null;
    if ($regles !== null) return $regles;
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    $mode = $pdo ? getParametre($pdo, 'mode_score', 'moyenne_bareme') : 'moyenne_bareme';
    if (!isset(modesScore()[$mode])) $mode = 'moyenne_bareme';
    $s1 = $pdo ? getParametreFloat($pdo, 'bareme_seuil_1', 5) : 5.0;
    $s2 = $pdo ? getParametreFloat($pdo, 'bareme_seuil_2', 10) : 10.0;
    if ($s1 < 0) $s1 = 0;
    if ($s2 < $s1) $s2 = $s1;
    return $regles = ['mode' => $mode, 's1' => $s1, 's2' => $s2];
}

/** Nombre lisible : 5 plutôt que 5.0, 5.5 garde sa décimale. */
function fmtNombre(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** Phrase courte qui explique le score officiel (aides des pages). */
function explicationScore(?PDO $pdo = null): string
{
    $r = reglesScore($pdo);
    return match ($r['mode']) {
        'minimum' => 'Score officiel = le total le plus bas des arbitres.',
        'moyenne' => 'Score officiel = la moyenne des points des arbitres. Le plus haut gagne.',
        default   => 'Score officiel = la moyenne des points des arbitres.',
    };
}

/** Barème des points de victoire, en clair (infobulles, aide). */
function explicationBareme(?PDO $pdo = null): string
{
    $r = reglesScore($pdo);
    if ($r['mode'] === 'moyenne') return 'Points de victoire : 1 pour le vainqueur, 0 en cas d\'égalité.';
    return 'Points de victoire selon l\'écart : de 0 à ' . fmtNombre($r['s1']) . ' = 1 pt, jusqu\'à '
        . fmtNombre($r['s2']) . ' = 2 pts, plus de ' . fmtNombre($r['s2']) . ' = 3 pts (écart nul = égalité).';
}

function pointsVictoire(float $ecart, ?PDO $pdo = null): int
{
    $ecart = round(abs($ecart), 2);
    if ($ecart <= 0) return 0;
    $r = reglesScore($pdo);
    if ($r['mode'] === 'moyenne') return 1;
    if ($ecart <= $r['s1']) return 1;
    if ($ecart <= $r['s2']) return 2;
    return 3;
}

/** Score officiel d'un joueur à partir des totaux de chaque arbitre. */
function scoreOfficiel(array $totaux, ?PDO $pdo = null): float
{
    if (!$totaux) return 0.0;
    if (reglesScore($pdo)['mode'] === 'minimum') return (float)min($totaux);
    return round(array_sum($totaux) / count($totaux), 2);
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

    if ($par) {
        $j1 = scoreOfficiel(array_column($par, 'j1'), $pdo);
        $j2 = scoreOfficiel(array_column($par, 'j2'), $pdo);
    } else {
        $j1 = 0; $j2 = 0;
    }
    // Combat terminé : son résultat est figé (score et points de victoire enregistrés
    // à la fin), même si le mode de calcul a changé depuis dans les paramètres.
    $fige = ($combat['statut'] ?? '') === 'termine';
    if ($fige) {
        $j1 = (float)$combat['score_joueur1'];
        $j2 = (float)$combat['score_joueur2'];
    }

    $ecart = abs($j1 - $j2);
    $leader = $j1 > $j2 ? 1 : ($j2 > $j1 ? 2 : 0);
    $pv = $fige && isset($combat['points_victoire']) && $combat['points_victoire'] !== null && $leader !== 0
        ? (int)$combat['points_victoire']
        : pointsVictoire($ecart, $pdo);
    return [
        'j1' => $j1, 'j2' => $j2,
        'ecart' => $ecart,
        'leader' => $leader,
        'pv' => $pv,
        'par_arbitre' => $par,
        'nb_arbitres' => count($par),
        'mode' => reglesScore($pdo)['mode'],
        'fige' => $fige,
    ];
}

/** Points de chaque arbitre, prêts pour le JSON (pages en direct). */
function arbitresPourJson(array $etat): array
{
    $roles = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];
    return array_values(array_map(fn($a) => [
        'nom' => $a['username'], 'role' => $a['role'], 'role_nom' => $roles[$a['role']] ?? $a['role'],
        'j1' => $a['j1'], 'j2' => $a['j2'],
    ], $etat['par_arbitre']));
}

/** En-tête du tableau « Points par arbitre » (colonnes Aka / Ao dans le sens choisi). */
function enTeteTableArbitres(PDO $pdo): string
{
    $aka = '<th scope="col" class="num side-1">Aka</th>';
    $ao = '<th scope="col" class="num side-2">Ao</th>';
    return '<th scope="col">Arbitre</th>' . (aoAGauche($pdo) ? $ao . $aka : $aka . $ao);
}

/** Lignes du tableau « Points par arbitre » + ligne du score officiel. */
function lignesTableArbitres(array $etat, array $roles): string
{
    if (!$etat['par_arbitre']) return '<tr><td colspan="3" class="hint">Aucun arbitre affecté.</td></tr>';
    $min = ($etat['mode'] ?? '') === 'minimum' && empty($etat['fige']);
    $aoGauche = isset($GLOBALS['pdo']) && aoAGauche($GLOBALS['pdo']);
    $paire = fn(string $c1, string $c2) => $aoGauche ? $c2 . $c1 : $c1 . $c2;
    $html = '';
    foreach ($etat['par_arbitre'] as $a) {
        $html .= '<tr><td><strong>' . e($a['username']) . '</strong> <span class="hint">' . e($roles[$a['role']] ?? $a['role']) . '</span></td>'
            . $paire('<td class="num ' . ($min && $a['j1'] == $etat['j1'] ? 'min side-1' : '') . '">' . fmtScore($a['j1']) . '</td>',
                     '<td class="num ' . ($min && $a['j2'] == $etat['j2'] ? 'min side-2' : '') . '">' . fmtScore($a['j2']) . '</td>') . '</tr>';
    }
    $libelle = !empty($etat['fige']) ? 'Score officiel (résultat final)' : ($min ? 'Score officiel (le plus bas)' : 'Score officiel (moyenne)');
    $html .= '<tr class="ref-official"><td><strong>' . $libelle . '</strong></td>'
        . $paire('<td class="num side-1"><strong>' . fmtScore($etat['j1']) . '</strong></td>',
                 '<td class="num side-2"><strong>' . fmtScore($etat['j2']) . '</strong></td>') . '</tr>';
    return $html;
}

/**
 * Tous les points, toujours visibles : une ligne par arbitre (Aka / Ao), puis
 * le score officiel (moyenne ou minimum). Mis à jour en direct par live.js.
 */
function detailPointsArbitres(array $etat): string
{
    if (!$etat['par_arbitre']) return '';
    $libOfficiel = !empty($etat['fige']) ? 'Résultat' : (($etat['mode'] ?? '') === 'minimum' ? 'Officiel (le plus bas)' : 'Moyenne');
    ob_start(); ?>
    <div class="ref-points js-ref-points" data-mode="<?= e($etat['mode'] ?? '') ?>">
        <?php foreach ($etat['par_arbitre'] as $a): ?>
            <span class="rp"><span class="rp-n"><?= e(['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'][$a['role']] ?? $a['role']) ?></span><span class="rp-s"><b class="s1"><?= fmtScore($a['j1']) ?></b><i>–</i><b class="s2"><?= fmtScore($a['j2']) ?></b></span></span>
        <?php endforeach; ?>
        <span class="rp rp-off"><span class="rp-n"><?= e($libOfficiel) ?></span><span class="rp-s"><b class="s1"><?= fmtScore($etat['j1']) ?></b><i>–</i><b class="s2"><?= fmtScore($etat['j2']) ?></b></span></span>
    </div>
    <?php return ob_get_clean();
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
function barreAvantage(array $etat, string $j1, string $j2, string $classe = '', bool $termine = false, bool $detail = true): string
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
            <span class="lead-pv js-lead-pv" data-pv="<?= $pv ?>" title="<?= e(explicationBareme()) ?>">
                <i class="<?= $pv >= 1 ? 'on' : '' ?>"></i><?php if (($etat['mode'] ?? '') !== 'moyenne'): ?><i class="<?= $pv >= 2 ? 'on' : '' ?>"></i><i class="<?= $pv >= 3 ? 'on' : '' ?>"></i><?php endif; ?>
            </span>
        </div>
        <?= $detail ? detailPointsArbitres($etat) : '' ?>
    </div>
    <?php return ob_get_clean();
}
