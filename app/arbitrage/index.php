<?php
require_once __DIR__ . '/../config/config.php';
requirePermission('arbitrage.acces', 'arbitrage.tous_combats');

purgerCombatsExpires($pdo);

$user = currentUser();
$tous = can('arbitrage.tous_combats');

$sql = "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo, ca.role
        FROM combats c
        JOIN joueurs j1 ON j1.id = c.joueur1_id
        JOIN joueurs j2 ON j2.id = c.joueur2_id
        " . ($tous ? 'LEFT' : '') . " JOIN combat_arbitres ca ON ca.combat_id = c.id AND ca.user_id = ?
        WHERE c.statut IN ('en_cours','a_venir')
        ORDER BY FIELD(c.statut,'en_cours','a_venir'), c.date_prevue IS NULL, c.date_prevue ASC, c.id ASC";
$st = $pdo->prepare($sql);
$st->execute([$user['id']]);
$combats = $st->fetchAll();

$sqlFinis = "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo, ca.role
        FROM combats c
        JOIN joueurs j1 ON j1.id = c.joueur1_id
        JOIN joueurs j2 ON j2.id = c.joueur2_id
        " . ($tous ? 'LEFT' : '') . " JOIN combat_arbitres ca ON ca.combat_id = c.id AND ca.user_id = ?
        WHERE c.statut = 'termine'
        ORDER BY c.temps_fin DESC LIMIT 6";
$st = $pdo->prepare($sqlFinis);
$st->execute([$user['id']]);
$finis = $st->fetchAll();

$live = array_values(array_filter($combats, fn($c) => $c['statut'] === 'en_cours'));
$avenir = array_values(array_filter($combats, fn($c) => $c['statut'] === 'a_venir'));
$roles = ['central' => 'Arbitre central', 'coin1' => 'Arbitre de coin 1', 'coin2' => 'Arbitre de coin 2'];

// Touches déjà enregistrées pour chaque combat en cours
$comptes = [];
foreach ($live as $c) {
    $comptes[$c['id']] = compterTouches(getTouchesCombat($pdo, $c));
}

$pageTitle = 'Espace arbitrage';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Arbitrage</h1>
        <p class="sub"><?= $tous ? 'Tous les combats du tournoi.' : 'Les combats qui vous sont confiés.' ?> Il est <strong class="tabular" data-server-clock><?= date('H:i:s') ?></strong>.</p>
    </div>
</div>

<?php if (!$combats && !$finis): ?>
    <?= etatVide('Aucun combat ne vous est confié pour le moment', 'Cette page se met à jour toute seule dès qu\'un combat vous est attribué.', '', 'target') ?>
<?php endif; ?>

<?php if ($live): ?>
<section class="section" aria-labelledby="h-live">
    <div class="section-live-header">
        <span class="live-dot" aria-hidden="true"></span>
        <h2 id="h-live">À arbitrer maintenant</h2>
    </div>
    <div class="grid grid-2">
        <?php foreach ($live as $c): ?>
            <?= carteCombatLive($pdo, $c, 'saisie.php?combat_id=' . (int)$c['id'], $c['role'] ? 'Ouvrir mon pupitre' : 'Suivre le combat (lecture seule)', $c['role'] ? ($roles[$c['role']] ?? $c['role']) : 'Supervision') ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($avenir): ?>
<section class="section" aria-labelledby="h-avenir">
    <div class="section-title"><h2 id="h-avenir">À venir<span class="count"><?= count($avenir) ?></span></h2></div>
    <ul class="upcoming">
        <?php foreach ($avenir as $c): ?>
            <?= ligneAVenir($c, 'saisie.php?combat_id=' . (int)$c['id'], [
                'sous'      => '<small class="hint">' . ($c['role'] ? 'Vous : ' . e($roles[$c['role']] ?? $c['role']) : 'Non affecté') . '</small>',
                'retard'    => 'en attente',
                'sansHeure' => 'démarrage manuel',
            ]) ?>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($finis): ?>
<section class="section" aria-labelledby="h-finis">
    <div class="section-title"><h2 id="h-finis">Derniers combats terminés</h2></div>
    <ul class="result-list">
        <?php foreach ($finis as $c): ?><?= ligneResultat($c, $pdo) ?><?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?= scriptJs('live.js') ?>
<?php if (!$combats): ?>
<script nonce="<?= e(cspNonce()) ?>">setInterval(() => { if (!document.hidden) window.location.reload(); }, 30000);</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
