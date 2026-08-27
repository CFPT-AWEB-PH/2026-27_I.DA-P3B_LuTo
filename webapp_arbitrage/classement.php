<?php
require_once __DIR__ . '/config/config.php';

$classement = getClassement($pdo);

$pageTitle = 'Classement';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('trophy') ?> Classement général</h1>

<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Classement général de tous les joueurs, trié par victoires puis points cumulés</caption>
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col">Joueur</th>
                <th scope="col">Matchs</th>
                <th scope="col">Victoires</th>
                <th scope="col">Défaites</th>
                <th scope="col">Points cumulés</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($classement as $i => $j): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($j['pseudo']) ?></td>
                <td><?= (int)$j['matchs_joues'] ?></td>
                <td><?= (int)$j['victoires'] ?></td>
                <td><?= (int)$j['defaites'] ?></td>
                <td><?= number_format($j['points_cumules'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$classement): ?>
            <tr><td colspan="6" class="empty-state">Aucun joueur enregistré.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
