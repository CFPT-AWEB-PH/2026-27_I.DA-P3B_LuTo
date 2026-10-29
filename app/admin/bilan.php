<?php
/**
 * admin/bilan.php : bilan de fin de journée, prêt à imprimer (ou enregistrer en PDF).
 *
 * Tout en une page : résumé, podium, classement, arbre (tour par tour),
 * historique complet des combats (scores, points de chaque arbitre, touches)
 * et fiches des joueurs (champs personnalisés compris).
 * ?jour=AAAA-MM-JJ limite le bilan à une journée ; ?export=csv télécharge
 * l'historique des combats.
 */
require_once __DIR__ . '/../config/config.php';
requirePermission('bilan.voir');

purgerCombatsExpires($pdo);

// ---- Journées disponibles (date de début, sinon date prévue, sinon création) ----
$jours = $pdo->query(
    "SELECT DISTINCT DATE(COALESCE(temps_debut, date_prevue, created_at)) AS jour FROM combats ORDER BY jour DESC"
)->fetchAll(PDO::FETCH_COLUMN);
$jour = is_string($_GET['jour'] ?? null) && in_array($_GET['jour'], $jours, true) ? $_GET['jour'] : '';

$sql = "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo, jv.pseudo AS vainqueur_pseudo
        FROM combats c
        JOIN joueurs j1 ON j1.id = c.joueur1_id
        JOIN joueurs j2 ON j2.id = c.joueur2_id
        LEFT JOIN joueurs jv ON jv.id = c.vainqueur_id";
$params = [];
if ($jour !== '') {
    $sql .= ' WHERE DATE(COALESCE(c.temps_debut, c.date_prevue, c.created_at)) = ?';
    $params[] = $jour;
}
$sql .= ' ORDER BY COALESCE(c.temps_debut, c.date_prevue, c.created_at) ASC, c.id ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$combats = $st->fetchAll();

// Détails de chaque combat : points par arbitre et touches des sabres
$roles = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];
foreach ($combats as &$c) {
    $c['etat'] = etatScore($pdo, $c);
    $c['touches'] = $c['statut'] !== 'a_venir' ? compterTouches(getTouchesCombat($pdo, $c)) : ['j1' => 0, 'j2' => 0, 'doubles' => 0];
    $c['duree_reelle'] = ($c['temps_debut'] && $c['temps_fin']) ? max(0, strtotime($c['temps_fin']) - strtotime($c['temps_debut'])) : null;
}
unset($c);

/** Arbitres d'un combat, lisibles : « Central X (5 – 3) · Coin 1 Y (4 – 3) ». */
function bilanArbitres(array $c, array $roles, bool $avecPoints = true): string
{
    $out = [];
    foreach ($c['etat']['par_arbitre'] as $a) {
        $out[] = ($roles[$a['role']] ?? $a['role']) . ' ' . $a['username']
            . ($avecPoints && $c['statut'] !== 'a_venir' ? ' (' . fmtScore($a['j1']) . ' – ' . fmtScore($a['j2']) . ')' : '');
    }
    return implode(' · ', $out);
}

// ---- EXPORT CSV de l'historique ----
if (($_GET['export'] ?? '') === 'csv') {
    $cellule = static function ($v): string {
        $v = (string)$v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    };
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['id', 'statut', 'heure_prevue', 'debut', 'fin', 'duree_reelle_s', 'tour', 'position', 'aka', 'ao',
        'score_aka', 'score_ao', 'vainqueur', 'points_victoire', 'arbitres_et_points', 'touches_aka', 'touches_ao', 'doubles'], ';');
    foreach ($combats as $c) {
        fputcsv($fh, array_map($cellule, [
            $c['id'], libelleStatut($c['statut']), $c['date_prevue'] ?? '', $c['temps_debut'] ?? '', $c['temps_fin'] ?? '',
            $c['duree_reelle'] ?? '', $c['tour'] ?? '', $c['position'] ?? '', $c['joueur1_pseudo'], $c['joueur2_pseudo'],
            $c['statut'] === 'a_venir' ? '' : fmtScore($c['etat']['j1']), $c['statut'] === 'a_venir' ? '' : fmtScore($c['etat']['j2']),
            $c['statut'] === 'termine' ? ($c['vainqueur_pseudo'] ?? 'Match nul') : '', $c['statut'] === 'termine' ? (int)$c['points_victoire'] : '',
            bilanArbitres($c, $roles), $c['touches']['j1'], $c['touches']['j2'], $c['touches']['doubles'],
        ]), ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bilan_combats_' . ($jour ?: 'complet') . '_' . date('Y-m-d_His') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// ---- Classement calculé à partir des combats terminés du bilan ----
$joueurs = $pdo->query(
    'SELECT j.*, g.nom AS grade_nom FROM joueurs j LEFT JOIN grades g ON g.id = j.grade_id'
)->fetchAll();
$stats = [];
foreach ($joueurs as $j) {
    $stats[(int)$j['id']] = ['joueur' => $j, 'combats' => 0, 'v' => 0, 'd' => 0, 'n' => 0, 'pv' => 0, 'points' => 0.0, 'present' => $jour === ''];
}
$compte = ['termine' => 0, 'en_cours' => 0, 'a_venir' => 0, 'annule' => 0];
$dureeTotale = 0;
$touchesTotal = 0;
foreach ($combats as $c) {
    $compte[$c['statut']] = ($compte[$c['statut']] ?? 0) + 1;
    $touchesTotal += $c['touches']['j1'] + $c['touches']['j2'] + $c['touches']['doubles'];
    foreach ([1, 2] as $n) {
        $id = (int)$c['joueur' . $n . '_id'];
        if (isset($stats[$id])) $stats[$id]['present'] = true;
    }
    if ($c['statut'] !== 'termine') continue;
    $dureeTotale += (int)$c['duree_reelle'];
    foreach ([1, 2] as $n) {
        $id = (int)$c['joueur' . $n . '_id'];
        if (!isset($stats[$id])) continue;
        $s = &$stats[$id];
        $s['combats']++;
        $s['points'] += (float)$c['score_joueur' . $n];
        if (!$c['vainqueur_id']) $s['n']++;
        elseif ((int)$c['vainqueur_id'] === $id) { $s['v']++; $s['pv'] += (int)$c['points_victoire']; }
        else $s['d']++;
        unset($s);
    }
}
$classement = array_values(array_filter($stats, fn($s) => $s['present']));
usort($classement, fn($a, $b) => [$b['pv'], $b['v'], $b['points'], $a['joueur']['pseudo']] <=> [$a['pv'], $a['v'], $a['points'], $b['joueur']['pseudo']]);

// ---- Arbre (combats placés dans un tour) ----
$parTour = [];
foreach ($combats as $c) {
    if ($c['tour'] !== null) $parTour[(int)$c['tour']][] = $c;
}
ksort($parTour);
foreach ($parTour as &$liste) usort($liste, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
unset($liste);
$nbTours = count($parTour);
$tours = array_keys($parTour);
$finale = $nbTours ? $parTour[$tours[$nbTours - 1]] : [];
$champion = (count($finale) === 1 && $finale[0]['statut'] === 'termine' && $finale[0]['vainqueur_pseudo']) ? $finale[0]['vainqueur_pseudo'] : null;
$nomTour = function (int $i) use ($nbTours, $parTour, $tours): string {
    $n = count($parTour[$tours[$i]]);
    if ($i === $nbTours - 1 && $n === 1 && $nbTours > 1) return 'Finale';
    if ($i === $nbTours - 2 && $n === 2) return 'Demi-finales';
    if ($i === $nbTours - 3 && $n === 4) return 'Quarts de finale';
    return 'Tour ' . $tours[$i];
};

$champsJoueur = getChampsJoueur($pdo);
$valeursChamps = valeursChampsJoueurs($pdo);
$nomTournoi = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');
$libelleJour = $jour !== '' ? date('d/m/Y', strtotime($jour)) : 'toutes les journées';

$pageTitle = 'Bilan · ' . $libelleJour;
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head no-print">
    <div>
        <h1>Bilan</h1>
        <p class="sub">Classement, arbre, historique complet des combats et joueurs, prêt à imprimer ou à enregistrer en PDF.</p>
    </div>
    <div class="btn-row">
        <a href="bilan.php?export=csv<?= $jour !== '' ? '&jour=' . e($jour) : '' ?>" class="btn btn-secondary"><?= icon('download') ?>Combats (CSV)</a>
        <button type="button" class="btn js-print"><?= icon('printer') ?>Imprimer / PDF</button>
    </div>
</div>

<form method="get" action="bilan.php" class="toolbar no-print">
    <label for="jour" class="hint">Journée</label>
    <select id="jour" name="jour" class="js-autosubmit">
        <option value="">Toutes les journées</option>
        <?php foreach ($jours as $jj): ?>
            <option value="<?= e($jj) ?>" <?= $jj === $jour ? 'selected' : '' ?>><?= e(date('d/m/Y', strtotime($jj))) ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-secondary btn-sm">Afficher</button></noscript>
</form>

<article class="bilan">
    <header class="bilan-head">
        <h2><?= e($nomTournoi) ?></h2>
        <p>Bilan du tournoi · <?= e($libelleJour) ?> · édité le <?= date('d/m/Y à H:i') ?></p>
    </header>

    <?php if ($compte['en_cours'] || $compte['a_venir']): ?>
        <div class="flash flash-warning" role="note"><?= icon('alert-triangle') ?><span class="flash-body">
            <?= $compte['en_cours'] ?> combat(s) encore en cours et <?= $compte['a_venir'] ?> à venir : le bilan n'est pas définitif.
        </span></div>
    <?php endif; ?>

    <section class="bilan-section">
        <h3>Résumé</h3>
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-card-value"><?= $compte['termine'] ?></div><div class="stat-card-label">Combats terminés</div></div>
            <div class="stat-card"><div class="stat-card-value"><?= count($classement) ?></div><div class="stat-card-label">Joueurs</div></div>
            <div class="stat-card"><div class="stat-card-value"><?= $dureeTotale ? e(formatDuree($dureeTotale)) : '–' ?></div><div class="stat-card-label">Temps de combat</div></div>
            <div class="stat-card"><div class="stat-card-value"><?= $touchesTotal ?></div><div class="stat-card-label">Touches des sabres</div></div>
            <?php if ($champion): ?>
                <div class="stat-card"><div class="stat-card-value is-text is-gold"><?= e($champion) ?></div><div class="stat-card-label">Champion</div></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="bilan-section">
        <h3>Classement</h3>
        <?php if (!$classement): ?>
            <p class="hint">Aucun joueur.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="ranking">
                <thead><tr>
                    <th scope="col">Rang</th><th scope="col">Joueur</th><th scope="col">Équipe</th>
                    <th scope="col" class="num">PV</th><th scope="col" class="num">Combats</th>
                    <th scope="col" class="num">V</th><th scope="col" class="num">N</th><th scope="col" class="num">D</th>
                    <th scope="col" class="num">Points</th>
                </tr></thead>
                <tbody>
                <?php foreach ($classement as $i => $s): ?>
                    <tr>
                        <td><span class="rank<?= $i < 3 && $s['pv'] > 0 ? ' rank-' . ($i + 1) : '' ?>"><?= $i + 1 ?></span></td>
                        <td><strong><?= e($s['joueur']['pseudo']) ?></strong></td>
                        <td><?= e($s['joueur']['equipe'] ?? '') ?></td>
                        <td class="num score-cell"><?= $s['pv'] ?></td>
                        <td class="num"><?= $s['combats'] ?></td>
                        <td class="num"><?= $s['v'] ?></td>
                        <td class="num"><?= $s['n'] ?></td>
                        <td class="num"><?= $s['d'] ?></td>
                        <td class="num"><?= fmtScore($s['points']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">PV = points de victoire (écart 1 à 5 = 1, 6 à 15 = 2, plus de 15 = 3). V / N / D = victoires, nuls, défaites. Calculé sur les combats terminés du bilan.</p>
        <?php endif; ?>
    </section>

    <?php if ($parTour): ?>
    <section class="bilan-section bilan-saut">
        <h3>Arbre du tournoi</h3>
        <div class="bilan-arbre">
            <?php foreach ($tours as $i => $t): ?>
                <div class="bilan-tour">
                    <h4><?= e($nomTour($i)) ?></h4>
                    <?php foreach ($parTour[$t] as $c):
                        $v1 = $c['vainqueur_id'] && (int)$c['vainqueur_id'] === (int)$c['joueur1_id'];
                        $v2 = $c['vainqueur_id'] && (int)$c['vainqueur_id'] === (int)$c['joueur2_id'];
                        $joue = $c['statut'] !== 'a_venir';
                    ?>
                        <div class="bilan-match">
                            <div class="<?= $v1 ? 'gagnant' : '' ?>"><span><?= e($c['joueur1_pseudo']) ?></span><span><?= $joue ? fmtScore($c['etat']['j1']) : '' ?></span></div>
                            <div class="<?= $v2 ? 'gagnant' : '' ?>"><span><?= e($c['joueur2_pseudo']) ?></span><span><?= $joue ? fmtScore($c['etat']['j2']) : '' ?></span></div>
                            <?php if ($c['statut'] !== 'termine'): ?><small><?= e(libelleStatut($c['statut'])) ?></small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($champion): ?>
                <div class="bilan-tour"><h4>Champion</h4><div class="bilan-match bilan-champion"><?= icon('trophy') ?><strong><?= e($champion) ?></strong></div></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="bilan-section bilan-saut">
        <h3>Historique des combats</h3>
        <?php if (!$combats): ?>
            <p class="hint">Aucun combat.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="bilan-table">
                <thead><tr>
                    <th scope="col">Heure</th><th scope="col">Combat</th><th scope="col" class="num">Score</th>
                    <th scope="col">Résultat</th><th scope="col">Arbitres (points Aka – Ao)</th><th scope="col" class="num">Touches</th>
                </tr></thead>
                <tbody>
                <?php foreach ($combats as $c):
                    $heure = $c['temps_debut'] ?? $c['date_prevue'];
                ?>
                    <tr>
                        <td class="tabular"><?= $heure ? date('H:i', strtotime($heure)) : '–' ?><?php if ($c['temps_fin']): ?><span class="sub-line">fin <?= date('H:i', strtotime($c['temps_fin'])) ?></span><?php endif; ?></td>
                        <td><strong class="side-1"><?= e($c['joueur1_pseudo']) ?></strong> <span class="muted">contre</span> <strong class="side-2"><?= e($c['joueur2_pseudo']) ?></strong>
                            <span class="sub-line">#<?= (int)$c['id'] ?><?= $c['tour'] !== null ? ' · tour ' . (int)$c['tour'] : '' ?><?= $c['duree_reelle'] !== null ? ' · ' . e(formatDuree($c['duree_reelle'])) : '' ?></span></td>
                        <td class="num tabular"><?= $c['statut'] === 'a_venir' ? '–' : fmtScore($c['etat']['j1']) . ' – ' . fmtScore($c['etat']['j2']) ?></td>
                        <td><?php if ($c['statut'] === 'termine'): ?>
                                <?= $c['vainqueur_pseudo'] ? 'Victoire ' . e($c['vainqueur_pseudo']) . ' · ' . (int)$c['points_victoire'] . ' PV' : 'Match nul' ?>
                            <?php else: ?><?= e(libelleStatut($c['statut'])) ?><?php endif; ?></td>
                        <td class="bilan-arbitres"><?= e(bilanArbitres($c, $roles)) ?: '<span class="muted">–</span>' ?></td>
                        <td class="num tabular"><?= $c['statut'] === 'a_venir' ? '–' : $c['touches']['j1'] . ' / ' . $c['touches']['j2'] . ($c['touches']['doubles'] ? '<span class="sub-line">' . $c['touches']['doubles'] . ' double(s)</span>' : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">Score officiel = le total le plus bas des trois arbitres. Touches = Aka / Ao (sabres connectés).</p>
        <?php endif; ?>
    </section>

    <section class="bilan-section bilan-saut">
        <h3>Joueurs</h3>
        <div class="table-wrap">
            <table class="bilan-table">
                <thead><tr>
                    <th scope="col">Joueur</th><th scope="col">Équipe</th><th scope="col">Profil</th><th scope="col">Grade</th>
                    <?php foreach ($champsJoueur as $ch): ?><th scope="col"><?= e($ch['nom']) ?></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($classement as $s): $j = $s['joueur'];
                    $age = calculerAge($j['date_naissance']);
                    $profil = array_filter([$j['sexe'] ? libelleSexe($j['sexe']) : null, $age !== null ? $age . ' ans' : null, $j['poids'] !== null ? fmtScore($j['poids']) . ' kg' : null]);
                ?>
                    <tr>
                        <td><strong><?= e($j['pseudo']) ?></strong><?php $nc = trim(($j['prenom'] ?? '') . ' ' . ($j['nom'] ?? '')); if ($nc !== ''): ?><span class="sub-line"><?= e($nc) ?></span><?php endif; ?></td>
                        <td><?= e($j['equipe'] ?? '') ?></td>
                        <td><?= e(implode(' · ', $profil)) ?></td>
                        <td><?= e($j['grade_nom'] ?? '') ?></td>
                        <?php foreach ($champsJoueur as $ch): ?><td><?= e(afficherValeurChamp($ch, $valeursChamps[(int)$j['id']][(int)$ch['id']] ?? null)) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</article>

<script nonce="<?= e(cspNonce()) ?>">
document.querySelector('.js-print')?.addEventListener('click', () => window.print());
document.querySelector('.js-autosubmit')?.addEventListener('change', e => e.target.form.submit());
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
