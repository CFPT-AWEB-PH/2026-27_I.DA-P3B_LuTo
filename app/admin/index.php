<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (!can('admin.dashboard')) {
    // Pas de tableau de bord : on envoie vers la 1re section admin autorisée
    $onglets = adminOnglets();
    if ($onglets) redirect('admin/' . $onglets[0][0]);
    requirePermission('admin.dashboard');
}

purgerCombatsExpires($pdo);

$stats = [
    'joueurs'      => (int)$pdo->query('SELECT COUNT(*) c FROM joueurs')->fetch()['c'],
    'arbitres'     => (function () use ($pdo) {
        $slugs = rolesAvecPermission($pdo, 'arbitrage.acces');
        $st = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE actif = 1 AND role IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')');
        $st->execute($slugs);
        return (int)$st->fetch()['c'];
    })(),
    'en_cours'     => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'en_cours'")->fetch()['c'],
    'a_venir'      => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'a_venir'")->fetch()['c'],
    'termines'     => (int)$pdo->query("SELECT COUNT(*) c FROM combats WHERE statut = 'termine'")->fetch()['c'],
];

$prochains = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo,
            (SELECT COUNT(*) FROM combat_arbitres ca WHERE ca.combat_id = c.id) AS nb_arbitres
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut IN ('en_cours','a_venir')
     ORDER BY FIELD(c.statut,'en_cours','a_venir'), c.date_prevue IS NULL, c.date_prevue ASC, c.id ASC
     LIMIT 8"
)->fetchAll();
$idsSansArbitres = $pdo->query(
    "SELECT c.id FROM combats c WHERE c.statut = 'a_venir'
       AND (SELECT COUNT(*) FROM combat_arbitres ca WHERE ca.combat_id = c.id) < 3
     ORDER BY c.date_prevue IS NULL, c.date_prevue, c.id"
)->fetchAll(PDO::FETCH_COLUMN);
$sansArbitres = count($idsSansArbitres);
// Un seul combat à corriger : le lien ouvre directement sa fiche
$lienAffecter = $sansArbitres === 1 && can('combats.modifier')
    ? 'combats.php?action=edit&id=' . (int)$idsSansArbitres[0]
    : 'combats.php?filtre=a_venir';

$pageTitle = 'Administration';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Vue d'ensemble</h1>
        <p class="sub">Il est <strong class="tabular" data-server-clock><?= date('H:i:s') ?></strong> · démarrage automatique <?= getParametreInt($pdo, 'demarrage_auto', 1) === 1 ? 'activé' : 'désactivé' ?></p>
    </div>
    <div class="btn-row">
        <?php if (can('joueurs.creer')): ?><a href="joueurs.php?action=new" class="btn btn-secondary"><?= icon('user-plus') ?>Ajouter un joueur</a><?php endif; ?>
        <?php if (can('combats.creer')): ?><a href="combats.php?action=new" class="btn"><?= icon('plus') ?>Nouveau combat</a><?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <a class="stat-card <?= $stats['en_cours'] ? 'is-live' : '' ?>" href="combats.php?filtre=en_cours"><div class="stat-card-value"><?= $stats['en_cours'] ?></div><div class="stat-card-label">En cours</div></a>
    <a class="stat-card" href="combats.php?filtre=a_venir"><div class="stat-card-value"><?= $stats['a_venir'] ?></div><div class="stat-card-label">À venir</div></a>
    <a class="stat-card" href="combats.php?filtre=termine"><div class="stat-card-value"><?= $stats['termines'] ?></div><div class="stat-card-label">Terminés</div></a>
    <a class="stat-card" href="joueurs.php"><div class="stat-card-value"><?= $stats['joueurs'] ?></div><div class="stat-card-label">Joueurs</div></a>
    <a class="stat-card" href="users.php"><div class="stat-card-value"><?= $stats['arbitres'] ?></div><div class="stat-card-label">Arbitres actifs</div></a>
</div>

<?php if ($sansArbitres): ?>
    <div class="flash flash-warning" role="status">
        <?= icon('alert-triangle') ?>
        <span class="flash-body"><strong><?= $sansArbitres > 1 ? $sansArbitres . " combats à venir n'ont" : "1 combat à venir n'a" ?> pas ses 3 arbitres.</strong> <?= $sansArbitres > 1 ? 'Ils ne pourront' : 'Il ne pourra' ?> pas démarrer.</span>
        <a href="<?= e($lienAffecter) ?>" class="btn btn-sm btn-secondary flash-action">Affecter les arbitres</a>
    </div>
<?php endif; ?>

<section class="section" aria-labelledby="h-programme">
    <div class="section-title"><h2 id="h-programme">Programme</h2><a class="link-more" href="combats.php">Gérer les combats <?= icon('chevron-right') ?></a></div>
    <?php if (!$prochains): ?>
        <?= etatVide('Aucun combat en cours ou à venir', 'Générez l\'arbre à partir des joueurs inscrits, ou créez un combat à la main.', can('combats.generer') ? '<a href="combats.php?action=generer" class="btn">' . icon('shuffle') . 'Générer le tournoi</a>' : '', 'calendar') ?>
    <?php else: ?>
        <ul class="upcoming">
            <?php foreach ($prochains as $c):
                $manque = $c['statut'] === 'a_venir' && (int)$c['nb_arbitres'] < 3;
                echo ligneAVenir($c, '../combat.php?id=' . (int)$c['id'], [
                    'sous'      => $manque ? '<span class="warn-text">' . icon('alert-triangle', 'icon-sm') . (int)$c['nb_arbitres'] . ' arbitre' . ((int)$c['nb_arbitres'] > 1 ? 's' : '') . ' sur 3</span>' : '',
                    'droite'    => $c['statut'] === 'en_cours' ? '<span class="score-cell"><span class="side-1">' . fmtScore($c['score_joueur1']) . '</span> <span class="muted">à</span> <span class="side-2">' . fmtScore($c['score_joueur2']) . '</span></span>' : null,
                    'retard'    => 'en attente',
                    'sansHeure' => 'démarrage manuel',
                ]);
            endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?= scriptJs('live.js') ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
