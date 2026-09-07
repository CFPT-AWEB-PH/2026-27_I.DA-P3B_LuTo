<?php
require_once __DIR__ . '/config/config.php';

$classement = getClassement($pdo);

// Stats globales
$totalJoueurs  = count($classement);
$totalMatchs   = $classement ? array_sum(array_column($classement, 'matchs_joues')) / 2 : 0;
$totalVictoires = $classement ? array_sum(array_column($classement, 'victoires')) : 0;

$pageTitle = 'Classement';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('trophy') ?> Classement général</h1>

<?php if ($classement): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-card-value"><?= $totalJoueurs ?></div>
        <div class="stat-card-label">Joueurs</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value"><?= (int)$totalMatchs ?></div>
        <div class="stat-card-label">Combats joués</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-value"><?= $totalVictoires ?></div>
        <div class="stat-card-label">Victoires total</div>
    </div>
</div>
<?php endif; ?>

<?php if (!$classement): ?>
    <div class="empty-state">
        <?= icon('trophy') ?>
        <p style="margin-top:var(--space-3);">Aucun joueur enregistré pour le moment.</p>
        <p style="font-size:var(--fs-sm);margin-top:var(--space-1);">Le classement s'affichera dès que des combats auront été disputés.</p>
    </div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Classement général de tous les joueurs, trié par victoires puis points cumulés</caption>
        <thead>
            <tr>
                <th scope="col" style="width:3rem;">#</th>
                <th scope="col">Joueur</th>
                <th scope="col" style="text-align:center;">Matchs</th>
                <th scope="col" style="text-align:center;">Victoires</th>
                <th scope="col" style="text-align:center;">Défaites</th>
                <th scope="col">% Victoires</th>
                <th scope="col" style="text-align:right;">Points</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $medals = ['🥇','🥈','🥉'];
        $podiumClasses = ['podium-1','podium-2','podium-3'];
        foreach ($classement as $i => $j):
            $matchs = (int)$j['matchs_joues'];
            $wins   = (int)$j['victoires'];
            $rate   = $matchs > 0 ? round($wins / $matchs * 100) : 0;
            $podium = $podiumClasses[$i] ?? '';
        ?>
            <tr class="<?= $podium ?>">
                <td><strong style="font-family:var(--font-display);font-size:1.1em;"><?= $medals[$i] ?? ($i + 1) ?></strong></td>
                <td>
                    <span style="font-weight:700;color:var(--text);font-family:var(--font-ui);font-size:var(--fs-md);"><?= e($j['pseudo']) ?></span>
                </td>
                <td style="text-align:center;color:var(--text-dim);"><?= $matchs ?></td>
                <td style="text-align:center;font-weight:700;color:var(--text);"><?= $wins ?></td>
                <td style="text-align:center;color:var(--text-dim);"><?= (int)$j['defaites'] ?></td>
                <td style="min-width:90px;">
                    <span style="font-family:var(--font-ui);font-size:var(--fs-sm);font-weight:700;"><?= $rate ?>%</span>
                    <?php if ($matchs > 0): ?>
                    <div class="winrate-bar"><div class="winrate-fill" style="width:<?= $rate ?>%"></div></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-family:var(--font-display);font-size:var(--fs-md);font-weight:800;color:var(--text);"><?= number_format($j['points_cumules'], 1) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
