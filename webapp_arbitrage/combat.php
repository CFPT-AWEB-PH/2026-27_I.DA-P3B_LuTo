<?php
require_once __DIR__ . '/config/config.php';

$id = (int)($_GET['id'] ?? 0);
purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $id);
if (!$combat) {
    renderErrorPage(404, 'Combat introuvable', 'Ce combat n\'existe pas ou a été supprimé.');
}

$arbitres = getArbitresDuCombat($pdo, $id);

$manches = $pdo->prepare('SELECT * FROM manches_resultats WHERE combat_id = ? ORDER BY manche ASC');
$manches->execute([$id]);
$manches = $manches->fetchAll();

$pageTitle = 'Combat #' . $id;
require __DIR__ . '/includes/header.php';
?>

<h1><?= e($combat['joueur1_pseudo']) ?> vs <?= e($combat['joueur2_pseudo']) ?></h1>
<span class="badge badge-<?= e($combat['statut']) ?>"><?= strtoupper(str_replace('_',' ', $combat['statut'])) ?></span>

<div class="card" data-combat-id="<?= (int)$combat['id'] ?>" id="combat-detail">
    <div class="combat-vs">
        <div class="player"><?= e($combat['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($combat['score_joueur1'],1) ?></span></div>
        <div class="vs-label" aria-hidden="true">VS</div>
        <div class="player"><?= e($combat['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($combat['score_joueur2'],1) ?></span></div>
    </div>

    <?php if ($combat['statut'] === 'en_cours'): ?>
        <div class="timer js-timer" data-remaining="<?= tempsRestant($combat) ?>" role="timer" aria-label="Temps restant">--:--</div>
        <p class="visually-hidden js-live-status" aria-live="polite"></p>
    <?php elseif ($combat['statut'] === 'termine'): ?>
        <p style="text-align:center; font-size: var(--fs-md); margin-top: var(--space-4);">
            <?php if ($combat['vainqueur_id']): ?>
                <?= icon('trophy') ?> Vainqueur : <strong><?= e($combat['vainqueur_pseudo']) ?></strong>
            <?php else: ?>
                Match nul
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p style="text-align:center; margin-top: var(--space-4);">Combat prévu le <?= formatDateFr($combat['date_prevue']) ?></p>
    <?php endif; ?>

    <div class="section-title"><h3 id="heading-arbitres">Arbitres</h3></div>
    <div class="arbitre-list" aria-labelledby="heading-arbitres">
        <?php foreach ($arbitres as $a): ?>
            <span class="arbitre-chip"><?= icon($a['role'] === 'central' ? 'target' : 'corner') ?> <?= $a['role'] === 'central' ? 'Central' : 'Coin' ?> : <?= e($a['username']) ?></span>
        <?php endforeach; ?>
    </div>

    <?php if ($manches): ?>
        <div class="section-title" style="margin-top: var(--space-5);"><h3 id="heading-manches">Détail des manches</h3></div>
        <div class="table-wrap">
            <table aria-labelledby="heading-manches">
                <caption class="visually-hidden">Score moyen par manche pour chaque joueur</caption>
                <thead><tr><th scope="col">Manche</th><th scope="col"><?= e($combat['joueur1_pseudo']) ?></th><th scope="col"><?= e($combat['joueur2_pseudo']) ?></th></tr></thead>
                <tbody>
                <?php foreach ($manches as $m): ?>
                    <tr>
                        <td><?= (int)$m['manche'] ?></td>
                        <td><?= number_format($m['moyenne_joueur1'],2) ?></td>
                        <td><?= number_format($m['moyenne_joueur2'],2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
