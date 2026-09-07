<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin', 'arbitre');

purgerCombatsExpires($pdo);

$user = currentUser();

if ($user['role'] === 'admin') {
    $combats = $pdo->query(
        "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
         FROM combats c
         JOIN joueurs j1 ON j1.id = c.joueur1_id
         JOIN joueurs j2 ON j2.id = c.joueur2_id
         WHERE c.statut IN ('en_cours','a_venir')
         ORDER BY FIELD(c.statut,'en_cours','a_venir'), c.date_prevue ASC"
    )->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo, ca.role
         FROM combats c
         JOIN joueurs j1 ON j1.id = c.joueur1_id
         JOIN joueurs j2 ON j2.id = c.joueur2_id
         JOIN combat_arbitres ca ON ca.combat_id = c.id
         WHERE ca.user_id = ? AND c.statut IN ('en_cours','a_venir')
         ORDER BY FIELD(c.statut,'en_cours','a_venir'), c.date_prevue ASC"
    );
    $stmt->execute([$user['id']]);
    $combats = $stmt->fetchAll();
}

$pageTitle = 'Espace Arbitrage';
require __DIR__ . '/../includes/header.php';
?>

<h1><?= icon('target') ?> Espace Arbitrage</h1>

<?php if (!$combats): ?>
    <div class="empty-state">
        <?= icon('target') ?>
        <p style="margin-top:var(--space-3);margin-bottom:var(--space-1);">Aucun combat ne vous est assigné pour le moment.</p>
        <p style="font-size:var(--fs-sm);">Revenez ici lorsqu'un combat vous sera attribué par l'administrateur.</p>
    </div>
<?php else: ?>

    <?php $liveCombats = array_filter($combats, fn($c) => $c['statut'] === 'en_cours'); ?>
    <?php $upcomingCombats = array_filter($combats, fn($c) => $c['statut'] === 'a_venir'); ?>

    <?php if ($liveCombats): ?>
    <div class="section-live-header">
        <span class="live-dot" aria-hidden="true"></span>
        <h2><?= icon('broadcast', 'text-live') ?> À arbitrer maintenant</h2>
    </div>
    <div class="grid grid-2" style="margin-bottom:var(--space-6);">
        <?php foreach ($liveCombats as $c): ?>
            <div class="card card-urgent">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3);">
                    <span class="badge badge-en_cours"><?= icon('broadcast') ?> EN COURS</span>
                    <?php if (isset($c['role'])): ?>
                        <span style="font-family:var(--font-ui);font-size:var(--fs-xs);font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.05em;">
                            <?= $c['role'] === 'central' ? '⚡ Arbitre central' : '◈ Arbitre de coin' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="combat-vs">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?></div>
                </div>
                <div class="card-actions">
                    <a href="saisie.php?combat_id=<?= (int)$c['id'] ?>" class="btn btn-success"><?= icon('target') ?> Saisir les points</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($upcomingCombats): ?>
    <div class="section-title">
        <h2><?= icon('history') ?> Combats à venir</h2>
    </div>
    <div class="grid grid-2">
        <?php foreach ($upcomingCombats as $c): ?>
            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3);">
                    <span class="badge badge-a_venir">À VENIR</span>
                    <?php if (isset($c['role'])): ?>
                        <span style="font-family:var(--font-ui);font-size:var(--fs-xs);font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;">
                            <?= $c['role'] === 'central' ? 'Arbitre central' : 'Arbitre de coin' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="combat-vs">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?></div>
                </div>
                <p style="text-align:center;margin-top:var(--space-3);font-family:var(--font-ui);font-size:var(--fs-sm);color:var(--text-dim);">
                    <?= icon('clock') ?> Prévu le <?= formatDateFr($c['date_prevue']) ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
