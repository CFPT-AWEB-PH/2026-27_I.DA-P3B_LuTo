<?php
/**
 * arbre.php : arbre du tournoi (élimination directe).
 *
 * Les tours sont nommés comme le public les connaît (quarts, demi-finales,
 * finale) et les tours pas encore créés sont affichés en « à déterminer » :
 * on voit tout de suite le chemin jusqu'à la finale.
 *
 * L'arbre s'ajuste à l'écran (arbre.js) : en entier, à la largeur, ou en
 * taille réelle avec défilement. Il s'imprime sur une page A4 paysage.
 */
require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$arbre = donneesArbre($pdo);
$nbColonnes = $arbre['nbColonnes'];
$nomApplication = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');

$pageTitle = 'Arbre du tournoi';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Arbre du tournoi</h1>
        <p class="sub no-print">Élimination directe. Touchez un combat pour voir son score et ses touches.</p>
        <p class="print-only bk-print-meta"><?= e($nomApplication) ?> · état au <?= date('d/m/Y') ?> à <?= date('H:i') ?></p>
    </div>
    <?php if ($nbColonnes > 0): ?>
    <div class="bk-legend" aria-hidden="true">
        <span><i class="l-live"></i>En cours</span>
        <span><i class="l-wait"></i>À venir</span>
        <span><i class="l-done"></i>Qualifié</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($nbColonnes === 0): ?>

    <?= etatVide('L\'arbre n\'est pas encore publié', 'Il apparaîtra ici dès que l\'organisation aura placé les premiers combats.', can('combats.generer') ? '<a class="btn" href="' . BASE_URL . 'admin/combats.php?action=generer">' . icon('shuffle') . 'Générer le tournoi</a>' : '', 'bracket') ?>

<?php else: ?>

<?php if ($arbre['incoherences'] && can('combats.modifier')): ?>
    <div class="flash flash-warning bk-admin-note no-print" role="note">
        <?= icon('alert-triangle') ?>
        <div class="flash-body">
            <strong>Visible par l'organisation uniquement.</strong> Nombre de combats inattendu :
            <?= e(implode(' ', $arbre['incoherences'])) ?> Vérifiez « Tour » et « Position » des combats.
        </div>
        <a class="btn btn-sm btn-secondary flash-action" href="<?= BASE_URL ?>admin/combats.php">Corriger</a>
    </div>
<?php endif; ?>

<div class="bk-toolbar no-print">
    <div class="segmented" role="group" aria-label="Taille de l'arbre">
        <button type="button" data-bk-mode="tout" aria-pressed="true" title="Tout l'arbre dans l'écran"><?= icon('maximize') ?>Tout voir</button>
        <button type="button" data-bk-mode="largeur" aria-pressed="false" title="Toute la largeur, défilement vers le bas"><?= icon('fit-width') ?>Largeur</button>
        <button type="button" data-bk-mode="reel" aria-pressed="false" title="Taille réelle, défilement dans les deux sens"><?= icon('search') ?>Taille réelle</button>
    </div>
    <span class="bk-zoom-info hint" aria-live="polite"></span>
    <button type="button" class="btn btn-secondary btn-sm" data-bk-print><?= icon('printer') ?>Imprimer</button>
</div>

<?= arbreHtml($arbre, ['fit' => 'tout', 'cadre' => 'page']) ?>

<?php endif; ?>

<?= scriptJs('live.js') ?>
<?= scriptJs('arbre.js') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
