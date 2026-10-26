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
     ORDER BY c.date_prevue IS NULL, c.date_prevue ASC, c.id ASC
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

// Seuls les joueurs qui ont déjà combattu figurent dans l'aperçu du classement
$classement = array_slice(array_values(array_filter(getClassement($pdo), fn($j) => (int)$j['matchs_joues'] > 0)), 0, 5);
$rangs = rangsClassement($classement);

$prochain = $aVenir[0] ?? null;

$pageTitle = 'Combats en direct';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>En direct</h1>
        <p class="sub">Scores et chronos en temps réel. Cette page se met à jour toute seule.</p>
    </div>
</div>

<section class="section" aria-labelledby="heading-en-cours">
    <div class="section-live-header">
        <?php if ($enCours): ?><span class="live-dot" aria-hidden="true"></span><?php endif; ?>
        <h2 id="heading-en-cours">Combats en cours<?php if ($enCours): ?><span class="count"><?= count($enCours) ?></span><?php endif; ?></h2>
    </div>

    <?php if (!$enCours): ?>
        <div class="empty-state">
            <?= icon('clock') ?>
            <p>Aucun combat en cours</p>
            <?php if ($prochain && $prochain['date_prevue']): ?>
                <p>Prochain combat à <strong><?= date('H:i', strtotime($prochain['date_prevue'])) ?></strong> (<span data-countdown-to="<?= dateVersMs($prochain['date_prevue']) ?>" data-late-text="imminent"></span>) : <?= e($prochain['joueur1_pseudo']) ?> contre <?= e($prochain['joueur2_pseudo']) ?>.</p>
            <?php elseif ($prochain): ?>
                <p>Prochain combat : <?= e($prochain['joueur1_pseudo']) ?> contre <?= e($prochain['joueur2_pseudo']) ?>.</p>
            <?php else: ?>
                <p>Les combats apparaîtront ici dès leur démarrage.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid grid-2">
            <?php foreach ($enCours as $c): ?>
                <?= carteCombatLive($pdo, $c, BASE_URL . 'combat.php?id=' . (int)$c['id'], 'Suivre le combat', $c['tour'] !== null ? nomTour((int)$c['tour'], nombreToursArbre($pdo)) : '') ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section" aria-labelledby="heading-a-venir">
    <div class="section-title">
        <h2 id="heading-a-venir">À venir<?php if ($aVenir): ?><span class="count"><?= count($aVenir) ?></span><?php endif; ?></h2>
        <a href="arbre.php" class="link-more">Voir l'arbre <?= icon('chevron-right') ?></a>
    </div>
    <?php if (!$aVenir): ?>
        <?= etatVide('Aucun combat prévu', 'Le programme s\'affichera ici dès que l\'organisation l\'aura publié.', '', '', 'compact') ?>
    <?php else: ?>
        <ul class="upcoming">
            <?php foreach ($aVenir as $c): ?><?= ligneAVenir($c, 'combat.php?id=' . (int)$c['id']) ?><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="grid grid-2">
    <section aria-labelledby="heading-classement">
        <div class="section-title">
            <h2 id="heading-classement">Classement</h2>
            <a href="classement.php" class="link-more">Classement complet <?= icon('chevron-right') ?></a>
        </div>
        <?php if (!$classement): ?>
            <?= etatVide('Pas encore de classement', 'Il se construit dès la fin du premier combat.', '', '', 'compact') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col" class="col-rank">Rang</th><th scope="col">Joueur</th><th scope="col" class="num"><abbr title="Points de victoire">PV</abbr></th><th scope="col" class="num">Victoires</th></tr></thead>
                <tbody>
                <?php foreach ($classement as $i => $j): ?>
                    <tr>
                        <td><?= pastilleRang($rangs[$i]) ?></td>
                        <td><strong><?= e($j['pseudo']) ?></strong></td>
                        <td class="num score-cell"><?= (int)$j['points_victoire'] ?></td>
                        <td class="num"><?= (int)$j['victoires'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="heading-resultats">
        <div class="section-title">
            <h2 id="heading-resultats">Derniers résultats</h2>
            <a href="scores.php" class="link-more">Tous les résultats <?= icon('chevron-right') ?></a>
        </div>
        <?php if (!$termines): ?>
            <?= etatVide('Aucun combat terminé', 'Les résultats apparaîtront ici à la fin de chaque combat.', '', '', 'compact') ?>
        <?php else: ?>
            <ul class="result-list result-list-compact">
                <?php foreach ($termines as $c): ?><?= ligneResultat($c, $pdo) ?><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?= scriptJs('live.js') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
