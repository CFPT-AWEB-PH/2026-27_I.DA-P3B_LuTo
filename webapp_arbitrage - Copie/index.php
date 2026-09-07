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

<h1><?= icon('bolt') ?> Combats en direct</h1>

<?php /* ── COMBATS EN COURS ── */ ?>
<div class="section-live-header">
    <span class="live-dot" aria-hidden="true"></span>
    <h2 id="heading-en-cours"><?= icon('broadcast', 'text-live') ?> En cours</h2>
    <?php if ($enCours): ?>
        <span class="badge badge-en_cours"><?= count($enCours) ?> combat<?= count($enCours) > 1 ? 's' : '' ?></span>
    <?php endif; ?>
</div>

<?php if (!$enCours): ?>
    <div class="empty-state">
        <?= icon('clock') ?>
        <p style="margin-top:var(--space-3);margin-bottom:var(--space-2);">Aucun combat en cours pour le moment.</p>
        <?php if ($aVenir): ?>
            <a href="#heading-a-venir" class="btn btn-secondary" style="display:inline-flex;margin-top:var(--space-3);"><?= icon('history') ?> Voir les prochains combats</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="grid grid-2" id="combats-en-cours" aria-labelledby="heading-en-cours">
        <?php foreach ($enCours as $c): ?>
            <div class="card card-urgent" data-combat-id="<?= (int)$c['id'] ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3);">
                    <span class="badge badge-en_cours"><?= icon('broadcast') ?> EN COURS</span>
                    <span class="timer js-timer" data-remaining="<?= tempsRestant($c) ?>" role="timer" aria-label="Temps restant" style="margin:0;font-size:var(--fs-md);">--:--</span>
                </div>
                <div class="combat-vs">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($c['score_joueur1'], 1) ?></span></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($c['score_joueur2'], 1) ?></span></div>
                </div>
                <p class="visually-hidden js-live-status" aria-live="polite"></p>
                <div class="card-actions">
                    <a href="combat.php?id=<?= (int)$c['id'] ?>" class="btn"><?= icon('eye') ?> Suivre en direct</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php /* ── PROCHAINS COMBATS ── */ ?>
<div class="section-title" style="margin-top:var(--space-6);">
    <h2 id="heading-a-venir"><?= icon('history') ?> Prochains combats</h2>
    <?php if ($aVenir): ?>
        <a href="arbre.php" class="btn btn-secondary" style="font-size:var(--fs-xs);"><?= icon('bracket') ?> Voir l'arbre</a>
    <?php endif; ?>
</div>

<?php if (!$aVenir): ?>
    <div class="empty-state">Aucun combat prévu pour le moment.</div>
<?php else: ?>
    <div class="table-wrap">
        <table aria-labelledby="heading-a-venir">
            <caption class="visually-hidden">Liste des prochains combats prévus</caption>
            <thead><tr><th scope="col">Combat</th><th scope="col">Heure prévue</th><th scope="col">Durée</th></tr></thead>
            <tbody>
            <?php foreach ($aVenir as $c): ?>
                <tr>
                    <td><strong style="color:var(--text);"><?= e($c['joueur1_pseudo']) ?></strong> <span style="color:var(--text-faint);">vs</span> <strong style="color:var(--text);"><?= e($c['joueur2_pseudo']) ?></strong></td>
                    <td style="font-family:var(--font-ui);font-weight:600;"><?= formatDateFr($c['date_prevue']) ?></td>
                    <td style="color:var(--text-dim);"><?= (int)($c['duree_secondes'] / 60) ?> min</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php /* ── GRILLE CLASSEMENT + RÉSULTATS ── */ ?>
<div class="grid grid-2" style="margin-top: var(--space-6);">
    <div>
        <div class="section-title">
            <h2 id="heading-classement"><?= icon('trophy') ?> Top 5</h2>
            <a href="classement.php" style="font-family:var(--font-ui);font-size:var(--fs-sm);font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim);">Tout voir <?= icon('chevron-right') ?></a>
        </div>
        <?php if (!$classement): ?>
            <div class="empty-state">Aucun joueur classé.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table aria-labelledby="heading-classement">
                <caption class="visually-hidden">Classement général, cinq premiers joueurs</caption>
                <thead><tr><th scope="col">#</th><th scope="col">Joueur</th><th scope="col">V</th><th scope="col">Pts</th></tr></thead>
                <tbody>
                <?php $medals = ['🥇','🥈','🥉']; ?>
                <?php foreach ($classement as $i => $j): ?>
                    <tr class="<?= isset(['podium-1','podium-2','podium-3'][$i]) ? ['podium-1','podium-2','podium-3'][$i] : '' ?>">
                        <td><strong><?= $medals[$i] ?? ($i + 1) ?></strong></td>
                        <td style="color:var(--text);font-weight:600;"><?= e($j['pseudo']) ?></td>
                        <td><?= (int)$j['victoires'] ?></td>
                        <td style="font-family:var(--font-display);font-weight:700;"><?= number_format($j['points_cumules'], 1) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="section-title">
            <h2 id="heading-resultats"><?= icon('check-circle') ?> Résultats récents</h2>
            <a href="scores.php" style="font-family:var(--font-ui);font-size:var(--fs-sm);font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim);">Scores <?= icon('chevron-right') ?></a>
        </div>
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
                            <td style="color:var(--text-dim);font-size:var(--fs-xs);"><?= e($c['joueur1_pseudo']) ?> vs <?= e($c['joueur2_pseudo']) ?></td>
                            <td style="font-family:var(--font-display);font-weight:700;"><?= number_format($c['score_joueur1'],1) ?> – <?= number_format($c['score_joueur2'],1) ?></td>
                            <td style="font-weight:700;color:var(--text);"><?php
                                if ($c['vainqueur_id'] == $c['joueur1_id']) echo e($c['joueur1_pseudo']);
                                elseif ($c['vainqueur_id'] == $c['joueur2_id']) echo e($c['joueur2_pseudo']);
                                else echo '<span style="color:var(--text-dim)">Égalité</span>';
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
