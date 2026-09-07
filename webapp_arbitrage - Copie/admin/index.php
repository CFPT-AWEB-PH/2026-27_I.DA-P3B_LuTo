<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

purgerCombatsExpires($pdo);

$stats = [
    'joueurs'      => (int)$pdo->query('SELECT COUNT(*) c FROM joueurs')->fetch()['c'],
    'arbitres'     => (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'arbitre'")->fetch()['c'],
    'en_cours'     => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'en_cours'")->fetch()['c'],
    'a_venir'      => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'a_venir'")->fetch()['c'],
    'termines'     => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'termine'")->fetch()['c'],
];

$pageTitle = 'Tableau de bord admin';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<h1>Tableau de bord</h1>

<div class="grid grid-3">
    <div class="card"><h3>Joueurs</h3><p class="score-big"><?= $stats['joueurs'] ?></p></div>
    <div class="card"><h3>Arbitres</h3><p class="score-big"><?= $stats['arbitres'] ?></p></div>
    <div class="card"><h3>Combats en cours</h3><p class="score-big"><?= $stats['en_cours'] ?></p></div>
    <div class="card"><h3>Combats à venir</h3><p class="score-big"><?= $stats['a_venir'] ?></p></div>
    <div class="card"><h3>Combats terminés</h3><p class="score-big"><?= $stats['termines'] ?></p></div>
</div>

<div class="btn-row" style="margin-top:20px;">
    <a href="joueurs.php?action=new" class="btn">+ Ajouter un joueur</a>
    <a href="users.php?action=new" class="btn btn-secondary">+ Créer un utilisateur / arbitre</a>
    <a href="combats.php?action=new" class="btn btn-success">+ Créer un combat</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
