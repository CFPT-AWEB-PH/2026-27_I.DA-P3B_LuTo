<?php
require_once __DIR__ . '/config/config.php';

$classement = getClassement($pdo);
$rangs = rangsClassement($classement);

// Stats globales
$totalJoueurs  = count($classement);
$totalMatchs   = $classement ? array_sum(array_column($classement, 'matchs_joues')) / 2 : 0;
$totalVictoires = $classement ? array_sum(array_column($classement, 'victoires')) : 0;

$leader = ($rangs[0]['podium'] ?? 0) === 1 ? $classement[0] : null;
$exAequo = $leader && ($rangs[1]['rang'] ?? null) === 1;
$nbClasses = count(array_filter($rangs, fn($r) => $r['rang'] !== null));

$pageTitle = 'Classement';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Classement</h1>
        <p class="sub">Trié par points de victoire, puis par nombre de victoires. Mis à jour à la fin de chaque combat.</p>
    </div>
</div>

<?php if (!$classement): ?>
    <?= etatVide('Aucun joueur inscrit', 'Le classement s\'affichera dès que des combats auront été disputés.', '', 'trophy') ?>
<?php else: ?>
<div class="stat-grid stat-grid-ranking">
    <?php if ($leader): ?>
        <div class="stat-card stat-card-leader"><div class="stat-card-value is-text is-gold"><?= e($leader['pseudo']) ?><?= $exAequo ? ' <span class="stat-card-of">et ex æquo</span>' : '' ?></div><div class="stat-card-label"><?= icon('trophy', 'icon-sm') ?> En tête · <?= (int)$leader['points_victoire'] ?> <abbr title="points de victoire">PV</abbr></div></div>
    <?php endif; ?>
    <div class="stat-card"><div class="stat-card-value"><?= (int)$totalMatchs ?></div><div class="stat-card-label">Combats joués</div></div>
    <div class="stat-card"><div class="stat-card-value"><?= $nbClasses ?><span class="stat-card-of">/<?= $totalJoueurs ?></span></div><div class="stat-card-label">Joueurs classés</div></div>
</div>

<div class="table-wrap">
    <table class="ranking">
        <caption class="visually-hidden">Classement général des joueurs</caption>
        <thead>
            <tr>
                <th scope="col" class="col-rank">Rang</th>
                <th scope="col">Joueur</th>
                <th scope="col" class="num"><abbr title="Points de victoire">PV</abbr></th>
                <th scope="col" class="num col-opt">Combats</th>
                <th scope="col" class="num"><abbr title="Victoires">V</abbr></th>
                <th scope="col" class="num col-opt"><abbr title="Défaites">D</abbr></th>
                <th scope="col" class="col-opt">Taux de victoire</th>
                <th scope="col" class="num col-opt">Points marqués</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($classement as $i => $j):
            $matchs = (int)$j['matchs_joues'];
            $wins   = (int)$j['victoires'];
            $rate   = $matchs > 0 ? round($wins / $matchs * 100) : 0;
        ?>
            <?php if ($rangs[$i]['rang'] === null && ($i === 0 || $rangs[$i - 1]['rang'] !== null)): ?>
                <tr class="row-group"><th scope="rowgroup" colspan="8">Pas encore combattu</th></tr>
            <?php endif; ?>
            <tr class="<?= $rangs[$i]['rang'] === null ? 'row-unranked' : '' ?>">
                <td><?= pastilleRang($rangs[$i]) ?></td>
                <td><div class="player-cell"><strong><?= e($j['pseudo']) ?></strong><?php if (!empty($j['equipe'])): ?><span class="hint"><?= e($j['equipe']) ?></span><?php endif; ?></div></td>
                <td class="num score-cell"><?= (int)$j['points_victoire'] ?></td>
                <td class="num col-opt"><?= $matchs ?></td>
                <td class="num"><?= $wins ?></td>
                <td class="num col-opt"><?= (int)$j['defaites'] ?></td>
                <td class="col-opt">
                    <?php if ($matchs > 0): ?>
                        <div class="winrate"><span class="tabular" style="min-width:3.2em"><?= $rate ?> %</span><div class="winrate-bar" aria-hidden="true"><div class="winrate-fill" style="width:<?= $rate ?>%"></div></div></div>
                    <?php else: ?><span class="muted">–</span><?php endif; ?>
                </td>
                <td class="num col-opt"><?= fmtScore($j['points_cumules']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="hint mt-4">Points de victoire (PV) selon l'écart final : 1 à 5 points = 1 PV, 6 à 15 = 2 PV, plus de 15 = 3 PV. Même rang = ex æquo.</p>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
