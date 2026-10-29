<?php $current = basename($_SERVER['SCRIPT_NAME']); ?>
<nav class="tabs" aria-label="Sections de l'administration">
<?php foreach (adminOnglets() as [$fichier, $ic, $libelle]): ?>
    <a href="<?= $fichier ?>"<?= $current === $fichier ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($libelle) ?></a>
<?php endforeach; ?>
</nav>
