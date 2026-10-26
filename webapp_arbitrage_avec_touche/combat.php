<?php
require_once __DIR__ . '/config/config.php';

$id = (int)($_GET['id'] ?? 0);
purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $id);
if (!$combat) {
    renderErrorPage(404, 'Combat introuvable', 'Ce combat n\'existe pas ou a été supprimé.');
}

$arbitres = getArbitresDuCombat($pdo, $id);


$statut = $combat['statut'];
$etat = etatScore($pdo, $combat);
$j1 = $combat['joueur1_pseudo'];
$j2 = $combat['joueur2_pseudo'];
$roles = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];

// Lien « Retour » : uniquement vers une page de ce site (jamais une URL externe)
$retour = BASE_URL . 'index.php';
$ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
if ($ref && ($ref['host'] ?? '') === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
    && preg_match('#^/[A-Za-z0-9_\-/.]*\.php$#', $ref['path'] ?? '')) {
    $retour = $ref['path'] . (isset($ref['query']) && preg_match('/^[A-Za-z0-9_=&%-]*$/', $ref['query']) ? '?' . $ref['query'] : '');
}

$pageTitle = $j1 . ' contre ' . $j2;
require __DIR__ . '/includes/header.php';
?>

<a href="<?= e($retour) ?>" class="back-nav"><?= icon('chevron-left') ?> Retour</a>

<header class="combat-head">
    <p class="eyebrow"><?= $combat['tour'] !== null ? e(nomTour((int)$combat['tour'], nombreToursArbre($pdo))) : 'Combat' ?><?= badgeStatut($statut) ?></p>
    <h1 class="player-names"><?= e($j1) ?><span class="vs">contre</span><?= e($j2) ?></h1>
</header>

<div class="grid grid-aside">
    <div class="stack">
        <section class="card match-card" data-combat-id="<?= (int)$combat['id'] ?>" data-statut="<?= e($statut) ?>" id="combat-detail" aria-label="Score">
            <?= duelScore($combat, ['live' => true, 'lg' => true, 'result' => true]) ?>
            <?php if ($statut !== 'a_venir') echo barreAvantage($etat, $j1, $j2, 'lead-lg', $statut === 'termine'); ?>

            <?php if ($statut === 'en_cours'): ?>
                <div class="timer js-timer" <?= attrsChrono($combat) ?> role="timer" aria-label="Temps restant"><?= gmdate('i:s', tempsRestant($combat)) ?></div>
                <p class="timer-label">Temps restant</p>
                <p class="visually-hidden js-live-status" aria-live="polite"></p>

            <?php elseif ($statut === 'termine'): ?>
                <p class="result-line">
                    <?= $combat['vainqueur_id'] ? 'Victoire de ' . e($combat['vainqueur_pseudo']) : 'Match nul' ?>
                    <?php if ($combat['temps_fin']): ?><span class="hint">Terminé à <?= date('H:i', strtotime($combat['temps_fin'])) ?></span><?php endif; ?>
                </p>

            <?php else: ?>
                <div class="start-line">
                    <?php if ($combat['date_prevue']): ?>
                        <span class="start-time"><?= date('H:i', strtotime($combat['date_prevue'])) ?></span>
                        <span class="timer-label">Début <span data-countdown-to="<?= dateVersMs($combat['date_prevue']) ?>" data-late-text="imminent"></span></span>
                    <?php else: ?>
                        <span class="start-time is-tbd">Horaire à venir</span>
                        <span class="timer-label">Lancé par l'organisation</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="match-meta">
                <span>Durée <strong><?= formatDuree((int)$combat['duree_secondes']) ?></strong></span>
                <?php if ($combat['temps_debut']): ?><span>Début <strong><?= date('H:i:s', strtotime($combat['temps_debut'])) ?></strong></span><?php endif; ?>
            </div>
        </section>

        <?php $detailPoints = array_sum(array_column($etat['par_arbitre'], 'nb')) > 0; ?>
        <?php if ($statut === 'a_venir' || !$etat['par_arbitre']): ?>
        <section class="card">
            <h3>Arbitres</h3>
            <?php if ($etat['par_arbitre']): ?>
                <div class="arbitre-list"><?= pastillesArbitres($arbitres) ?></div>
            <?php else: ?>
                <p class="hint">Les arbitres ne sont pas encore désignés.</p>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="card">
            <h3>Points par arbitre</h3>
            <p class="hint">Le score officiel retient le total le plus bas des trois arbitres (en gras).</p>
            <?php if ($statut === 'termine' && !$detailPoints): ?>
                <p class="hint">Le détail par arbitre n'a pas été enregistré pour ce combat.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="ref-table">
                        <thead><tr><th scope="col">Arbitre</th><th scope="col" class="num side-1">Aka</th><th scope="col" class="num side-2">Ao</th></tr></thead>
                        <tbody>
                        <?php foreach ($etat['par_arbitre'] as $a): ?>
                            <tr>
                                <td><strong><?= e($a['username']) ?></strong> <span class="hint"><?= e($roles[$a['role']] ?? $a['role']) ?></span></td>
                                <td class="num <?= $a['j1'] == $etat['j1'] ? 'min side-1' : '' ?>"><?= fmtScore($a['j1']) ?></td>
                                <td class="num <?= $a['j2'] == $etat['j2'] ? 'min side-2' : '' ?>"><?= fmtScore($a['j2']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>

    <?php if ($statut !== 'a_venir'): ?>
    <section class="card touch-panel" data-touches
             data-combat-id="<?= (int)$combat['id'] ?>" data-statut="<?= e($statut) ?>"
             data-j1="<?= e($j1) ?>" data-j2="<?= e($j2) ?>" data-duree="<?= (int)$combat['duree_secondes'] ?>">
        <div class="card-head"><h3>Touches</h3></div>
        <span class="skeleton skeleton-block" aria-hidden="true"></span>
        <span class="skeleton skeleton-line" aria-hidden="true"></span>
        <span class="skeleton skeleton-line" aria-hidden="true" style="width:70%"></span>
    </section>
    <?php else: ?>
    <section class="card">
        <h3>Touches</h3>
        <?= etatVide('Pas encore de touche', 'L\'historique des touches des sabres s\'affichera ici dès le début du combat.', '', 'saber', 'compact') ?>
    </section>
    <?php endif; ?>
</div>

<?= scriptJs('live.js') ?>
<?= scriptJs('touches.js') ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
