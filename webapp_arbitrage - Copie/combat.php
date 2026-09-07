<?php
require_once __DIR__ . '/config/config.php';

$id = (int)($_GET['id'] ?? 0);
purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $id);
if (!$combat) {
    renderErrorPage(404, 'Combat introuvable', 'Ce combat n\'existe pas ou a été supprimé.');
}

$arbitres = getArbitresDuCombat($pdo, $id);

$manchesStmt = $pdo->prepare('SELECT * FROM manches_resultats WHERE combat_id = ? ORDER BY manche ASC');
$manchesStmt->execute([$id]);
$manches = $manchesStmt->fetchAll();

$pageTitle = e($combat['joueur1_pseudo']) . ' vs ' . e($combat['joueur2_pseudo']);
require __DIR__ . '/includes/header.php';
?>

<a href="<?= isset($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], BASE_URL) ? e($_SERVER['HTTP_REFERER']) : BASE_URL . 'index.php' ?>" class="back-nav"><?= icon('chevron-left') ?> Retour</a>

<?php /* ── Contexte du combat ── */ ?>
<div class="combat-context-bar">
    <div class="player-names"><?= e($combat['joueur1_pseudo']) ?> <span style="color:var(--text-faint);font-weight:400;">vs</span> <?= e($combat['joueur2_pseudo']) ?></div>
    <span class="badge badge-<?= e($combat['statut']) ?>"><?= strtoupper(str_replace('_',' ', $combat['statut'])) ?></span>
</div>

<?php /* ── Carte principale ── */ ?>
<div class="card <?= $combat['statut'] === 'en_cours' ? 'card-urgent' : '' ?>" data-combat-id="<?= (int)$combat['id'] ?>" id="combat-detail">
    <div class="combat-vs">
        <div class="player">
            <?= e($combat['joueur1_pseudo']) ?>
            <br><span class="js-score1 score-big"><?= number_format($combat['score_joueur1'],1) ?></span>
        </div>
        <div class="vs-label" aria-hidden="true">VS</div>
        <div class="player">
            <?= e($combat['joueur2_pseudo']) ?>
            <br><span class="js-score2 score-big"><?= number_format($combat['score_joueur2'],1) ?></span>
        </div>
    </div>

    <?php if ($combat['statut'] === 'en_cours'): ?>
        <div class="timer js-timer" data-remaining="<?= tempsRestant($combat) ?>" role="timer" aria-label="Temps restant">--:--</div>
        <p class="visually-hidden js-live-status" aria-live="polite"></p>

    <?php elseif ($combat['statut'] === 'termine'): ?>
        <p style="text-align:center;font-size:var(--fs-lg);font-family:var(--font-ui);font-weight:700;margin-top:var(--space-4);">
            <?php if ($combat['vainqueur_id']): ?>
                🏆 Vainqueur : <strong><?= e($combat['vainqueur_pseudo']) ?></strong>
            <?php else: ?>
                ⚖️ Match nul
            <?php endif; ?>
        </p>

    <?php else: ?>
        <p style="text-align:center;margin-top:var(--space-4);color:var(--text-dim);">
            <?= icon('clock') ?> Prévu le <?= formatDateFr($combat['date_prevue']) ?>
        </p>
    <?php endif; ?>
</div>

<?php /* ── Infos supplémentaires ── */ ?>
<div class="grid grid-2" style="margin-top:var(--space-4);">
    <?php if ($arbitres): ?>
    <div class="card">
        <h3 id="heading-arbitres" style="margin-bottom:var(--space-3);"><?= icon('target') ?> Arbitres</h3>
        <div class="arbitre-list" aria-labelledby="heading-arbitres">
            <?php foreach ($arbitres as $a): ?>
                <span class="arbitre-chip">
                    <?= icon($a['role'] === 'central' ? 'target' : 'corner') ?>
                    <?= e($a['username']) ?>
                    <small style="color:var(--text-faint);"><?= $a['role'] === 'central' ? 'central' : 'coin' ?></small>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($manches): ?>
    <div class="card">
        <h3 id="heading-manches" style="margin-bottom:var(--space-3);"><?= icon('history') ?> Manches</h3>
        <div class="table-wrap">
            <table aria-labelledby="heading-manches" style="font-size:var(--fs-sm);">
                <thead>
                    <tr>
                        <th scope="col" style="text-align:left;">M.</th>
                        <th scope="col"><?= e($combat['joueur1_pseudo']) ?></th>
                        <th scope="col"><?= e($combat['joueur2_pseudo']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($manches as $m): ?>
                    <tr>
                        <td><strong><?= (int)$m['manche'] ?></strong></td>
                        <td><?= number_format($m['moyenne_joueur1'],2) ?></td>
                        <td><?= number_format($m['moyenne_joueur2'],2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($combat['statut'] !== 'termine'): ?>
    <div class="card" style="display:flex;align-items:center;justify-content:center;min-height:8rem;opacity:.6;">
        <p style="text-align:center;font-family:var(--font-ui);"><?= icon('history') ?><br><small>Aucune manche jouée</small></p>
    </div>
    <?php endif; ?>
</div>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<?php if ($combat['statut'] === 'en_cours'): ?>
<script>startLivePolling(<?= (int)$id ?>);</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
