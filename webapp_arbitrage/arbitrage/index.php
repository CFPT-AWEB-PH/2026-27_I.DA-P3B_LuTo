<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin', 'arbitre');

purgerCombatsExpires($pdo);

$user = currentUser();

if ($user['role'] === 'admin') {
    // L'admin voit tous les combats en cours / à venir
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
    <div class="empty-state">Aucun combat ne vous est assigné pour le moment.</div>
<?php else: ?>
    <div class="grid grid-2">
        <?php foreach ($combats as $c): ?>
            <div class="card">
                <span class="badge badge-<?= e($c['statut']) ?>"><?= strtoupper(str_replace('_',' ',$c['statut'])) ?></span>
                <?php if (isset($c['role'])): ?>
                    <span class="badge" style="background:rgba(255,255,255,0.08); color:var(--text-dim); border-color:var(--border);"><?= $c['role'] === 'central' ? 'Arbitre central' : 'Arbitre de coin' ?></span>
                <?php endif; ?>
                <div class="combat-vs" style="margin-top: var(--space-3);">
                    <div class="player"><?= e($c['joueur1_pseudo']) ?></div>
                    <div class="vs-label" aria-hidden="true">VS</div>
                    <div class="player"><?= e($c['joueur2_pseudo']) ?></div>
                </div>
                <p style="text-align:center; margin-top: var(--space-3);">
                    <?php if ($c['statut'] === 'en_cours'): ?>
                        <a href="saisie.php?combat_id=<?= (int)$c['id'] ?>" class="btn">Saisir les points — <?= e($c['joueur1_pseudo']) ?> vs <?= e($c['joueur2_pseudo']) ?></a>
                    <?php else: ?>
                        <small class="hint">Prévu le <?= formatDateFr($c['date_prevue']) ?></small>
                    <?php endif; ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
