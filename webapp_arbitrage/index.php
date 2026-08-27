<?php
require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$enCours = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'en_cours'
     ORDER BY c.temps_debut ASC"
)->fetchAll();

$aVenir = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'a_venir'
     ORDER BY c.date_prevue ASC
     LIMIT 10"
)->fetchAll();

$termines = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'termine'
     ORDER BY c.temps_fin DESC
     LIMIT 5"
)->fetchAll();

$classement = array_slice(getClassement($pdo), 0, 5);

$pageTitle = 'Accueil';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('bolt') ?> Combats de Sabre Laser</h1>

<div class="section-title"><h2 id="heading-en-cours"><?= icon('broadcast', 'text-live') ?> En cours</h2></div>
<?php if (!$enCours): ?>
    <div class="empty-state">Aucun combat en cours pour le moment.</div>
<?php else: ?>
    <div class="grid grid-2" id="combats-en-cours" aria-labelledby="heading-en-cours">
        <?php foreach ($enCours as $c): ?>
            <div class="card combat-card" data-combat-id="<?= (int)$c['id'] ?>">
                <span class="badge badge-en_cours">EN COURS</span>
                <div class="combat-vs" style="margin-top: var(--space-3);">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($c['score_joueur1'], 1) ?></span></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($c['score_joueur2'], 1) ?></span></div>
                </div>
                <div class="timer js-timer" data-remaining="<?= tempsRestant($c) ?>" role="timer" aria-label="Temps restant">--:--</div>
                <p class="visually-hidden js-live-status" aria-live="polite"></p>
                <p style="text-align:center;"><a href="combat.php?id=<?= (int)$c['id'] ?>">Voir le détail de <?= e($c['joueur1_pseudo']) ?> contre <?= e($c['joueur2_pseudo']) ?></a></p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="section-title"><h2 id="heading-a-venir"><?= icon('history') ?> Prochains combats</h2></div>
<?php if (!$aVenir): ?>
    <div class="empty-state">Aucun combat prévu pour le moment.</div>
<?php else: ?>
    <div class="table-wrap">
        <table aria-labelledby="heading-a-venir">
            <caption class="visually-hidden">Liste des prochains combats prévus</caption>
            <thead><tr><th scope="col">Combat</th><th scope="col">Date prévue</th><th scope="col">Durée</th></tr></thead>
            <tbody>
            <?php foreach ($aVenir as $c): ?>
                <tr>
                    <td><?= e($c['joueur1_pseudo']) ?> vs <?= e($c['joueur2_pseudo']) ?></td>
                    <td><?= formatDateFr($c['date_prevue']) ?></td>
                    <td><?= (int)($c['duree_secondes'] / 60) ?> min</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="grid grid-2" style="margin-top: var(--space-6);">
    <div>
        <div class="section-title"><h2 id="heading-classement"><?= icon('trophy') ?> Classement (top 5)</h2></div>
        <div class="table-wrap">
            <table aria-labelledby="heading-classement">
                <caption class="visually-hidden">Classement général, cinq premiers joueurs</caption>
                <thead><tr><th scope="col">#</th><th scope="col">Joueur</th><th scope="col">V</th><th scope="col">D</th><th scope="col">Points</th></tr></thead>
                <tbody>
                <?php foreach ($classement as $i => $j): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($j['pseudo']) ?></td>
                        <td><?= (int)$j['victoires'] ?></td>
                        <td><?= (int)$j['defaites'] ?></td>
                        <td><?= number_format($j['points_cumules'], 1) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><a href="classement.php">Voir le classement complet <?= icon('chevron-right') ?></a></p>
    </div>

    <div>
        <div class="section-title"><h2 id="heading-resultats"><?= icon('check-circle') ?> Résultats récents</h2></div>
        <?php if (!$termines): ?>
            <div class="empty-state">Aucun combat terminé pour le moment.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table aria-labelledby="heading-resultats">
                    <caption class="visually-hidden">Résultats des combats terminés récemment</caption>
                    <thead><tr><th scope="col">Combat</th><th scope="col">Score</th><th scope="col">Vainqueur</th></tr></thead>
                    <tbody>
                    <?php foreach ($termines as $c): ?>
                        <tr>
                            <td><?= e($c['joueur1_pseudo']) ?> vs <?= e($c['joueur2_pseudo']) ?></td>
                            <td><?= number_format($c['score_joueur1'],1) ?> - <?= number_format($c['score_joueur2'],1) ?></td>
                            <td><?php
                                if ($c['vainqueur_id'] == $c['joueur1_id']) echo icon('trophy') . ' ' . e($c['joueur1_pseudo']);
                                elseif ($c['vainqueur_id'] == $c['joueur2_id']) echo icon('trophy') . ' ' . e($c['joueur2_pseudo']);
                                else echo 'Égalité';
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
