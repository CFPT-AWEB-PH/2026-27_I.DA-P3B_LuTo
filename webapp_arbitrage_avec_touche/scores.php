<?php
/**
 * scores.php : Page publique dédiée aux spectateurs : montre les points
 * attribués à chaque combat (en cours et terminés), avec le détail manche
 * par manche. Complète classement.php (qui montre le classement général
 * des joueurs) en se concentrant sur le score de chaque combat.
 */

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

$termines = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'termine'
     ORDER BY c.temps_fin DESC"
)->fetchAll();

// Détail manche par manche de tous les combats affichés, en une seule
// requête (plutôt qu'une requête par combat dans la boucle d'affichage).
$idsCombats = array_merge(array_column($enCours, 'id'), array_column($termines, 'id'));
$manchesParCombat = [];
if ($idsCombats) {
    $ph = implode(',', array_fill(0, count($idsCombats), '?'));
    $stmt = $pdo->prepare("SELECT * FROM manches_resultats WHERE combat_id IN ($ph) ORDER BY manche ASC");
    $stmt->execute(array_values($idsCombats));
    foreach ($stmt->fetchAll() as $m) {
        $manchesParCombat[$m['combat_id']][] = $m;
    }
}

$pageTitle = 'Résultats';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Résultats</h1>
        <p class="sub">Tous les combats terminés, du plus récent au plus ancien.</p>
    </div>
</div>

<?php if ($enCours): ?>
    <a class="flash flash-info" href="index.php">
        <span class="live-dot" aria-hidden="true"></span>
        <span class="flash-body"><strong><?= count($enCours) ?> combat<?= count($enCours) > 1 ? 's' : '' ?> en cours</strong> : suivez les scores en direct.</span>
        <?= icon('arrow-right') ?>
    </a>
<?php endif; ?>

<section class="section" aria-labelledby="heading-termines">
    <div class="section-title">
        <h2 id="heading-termines">Combats terminés<?php if ($termines): ?><span class="count"><?= count($termines) ?></span><?php endif; ?></h2>
    </div>
    <?php if (!$termines): ?>
        <?= etatVide('Aucun combat terminé', 'Chaque résultat s\'ajoute ici dès la fin du combat.', '<a class="btn btn-secondary" href="index.php">Voir les combats en direct</a>', 'flag') ?>
    <?php else: ?>
        <ul class="result-list">
            <?php foreach ($termines as $c): ?><?= ligneResultat($c, $pdo) ?><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<details class="form-section">
    <summary>Comment le score est-il calculé ? <span class="summary-arrow" aria-hidden="true"><?= icon('chevron-down') ?></span></summary>
    <div class="form-section-body">
        <p>Chaque arbitre donne des points pendant le combat. Le <strong>score officiel</strong> d'un joueur est le total <strong>le plus bas</strong> des trois arbitres.</p>
        <p>Le vainqueur gagne des <strong>points de victoire</strong> selon l'écart final : 1 à 5 points d'écart = 1 PV, 6 à 15 = 2 PV, plus de 15 = 3 PV.</p>
    </div>
</details>

<?php require __DIR__ . '/includes/footer.php'; ?>
