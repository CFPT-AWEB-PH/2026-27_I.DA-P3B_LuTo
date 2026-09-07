<?php
/**
 * scores.php — Page publique dédiée aux spectateurs : montre les points
 * attribués à chaque combat (en cours et terminés), avec le détail manche
 * par manche. Complète classement.php (qui montre le classement général
 * des joueurs) en se concentrant sur le score de chaque combat.
 */

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

$termines = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'termine'
     ORDER BY c.temps_fin DESC"
)->fetchAll();

// Détail manche par manche de tous les combats affichés, en une seule
// requête (plutôt qu'une requête par combat dans la boucle d'affichage).
$idsCombats = array_merge(array_column($enCours, 'id'), array_column($termines, 'id'));
$manchesParCombat = [];
if ($idsCombats) {
    $ph = implode(',', array_fill(0, count($idsCombats), '?'));
    $stmt = $pdo->prepare("SELECT * FROM manches_resultats WHERE combat_id IN ($ph) ORDER BY manche ASC");
    $stmt->execute(array_values($idsCombats));
    foreach ($stmt->fetchAll() as $m) {
        $manchesParCombat[$m['combat_id']][] = $m;
    }
}

$pageTitle = 'Scores';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('chart') ?> Scores</h1>
<p>Points attribués à chaque combat, manche par manche.</p>

<div class="section-title"><h2 id="heading-scores-en-cours"><?= icon('broadcast', 'text-live') ?> En cours</h2></div>
<?php if (!$enCours): ?>
    <div class="empty-state">Aucun combat en cours pour le moment.</div>
<?php else: ?>
    <div class="grid grid-2" aria-labelledby="heading-scores-en-cours">
        <?php foreach ($enCours as $c): ?>
            <div class="card combat-card score-card" data-combat-id="<?= (int)$c['id'] ?>">
                <span class="badge badge-en_cours">EN COURS</span>
                <div class="combat-vs" style="margin-top: var(--space-3);">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($c['score_joueur1'], 1) ?></span></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($c['score_joueur2'], 1) ?></span></div>
                </div>
                <p class="visually-hidden js-live-status" aria-live="polite"></p>
                <?php if (!empty($manchesParCombat[$c['id']])): ?>
                    <div class="table-wrap" style="margin-top: var(--space-3);">
                        <table>
                            <caption class="visually-hidden">Score par manche pour ce combat</caption>
                            <thead><tr><th scope="col">Manche</th><th scope="col"><?= e($c['joueur1_pseudo']) ?></th><th scope="col"><?= e($c['joueur2_pseudo']) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($manchesParCombat[$c['id']] as $m): ?>
                                <tr><td><?= (int)$m['manche'] ?></td><td><?= number_format($m['moyenne_joueur1'], 2) ?></td><td><?= number_format($m['moyenne_joueur2'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="section-title" style="margin-top: var(--space-6);"><h2 id="heading-scores-termines"><?= icon('check-circle') ?> Combats terminés</h2></div>
<?php if (!$termines): ?>
    <div class="empty-state">Aucun combat terminé pour le moment.</div>
<?php else: ?>
    <div class="grid grid-2" aria-labelledby="heading-scores-termines">
        <?php foreach ($termines as $c): ?>
            <div class="card score-card">
                <span class="badge badge-termine">TERMINÉ</span>
                <div class="combat-vs" style="margin-top: var(--space-3);">
                    <div class="player <?= $c['vainqueur_id'] == $c['joueur1_id'] ? 'is-winner' : '' ?>"><?= e($c['joueur1_pseudo']) ?><br><span class="score-big"><?= number_format($c['score_joueur1'], 1) ?></span></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player <?= $c['vainqueur_id'] == $c['joueur2_id'] ? 'is-winner' : '' ?>"><?= e($c['joueur2_pseudo']) ?><br><span class="score-big"><?= number_format($c['score_joueur2'], 1) ?></span></div>
                </div>
                <p style="text-align:center; margin-top: var(--space-2);">
                    <?php if ($c['vainqueur_id']): ?>
                        <?= icon('trophy') ?> Vainqueur : <strong><?= $c['vainqueur_id'] == $c['joueur1_id'] ? e($c['joueur1_pseudo']) : e($c['joueur2_pseudo']) ?></strong>
                    <?php else: ?>
                        Match nul
                    <?php endif; ?>
                </p>
                <?php if (!empty($manchesParCombat[$c['id']])): ?>
                    <div class="table-wrap" style="margin-top: var(--space-3);">
                        <table>
                            <caption class="visually-hidden">Score par manche pour ce combat</caption>
                            <thead><tr><th scope="col">Manche</th><th scope="col"><?= e($c['joueur1_pseudo']) ?></th><th scope="col"><?= e($c['joueur2_pseudo']) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($manchesParCombat[$c['id']] as $m): ?>
                                <tr><td><?= (int)$m['manche'] ?></td><td><?= number_format($m['moyenne_joueur1'], 2) ?></td><td><?= number_format($m['moyenne_joueur2'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
