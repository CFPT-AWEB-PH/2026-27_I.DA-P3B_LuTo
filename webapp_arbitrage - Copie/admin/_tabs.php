<?php $current = basename($_SERVER['SCRIPT_NAME']); ?>
<nav class="tabs" aria-label="Sections d'administration">
    <a href="index.php"<?= $current === 'index.php' ? ' aria-current="page"' : '' ?>><?= icon('dashboard') ?> Tableau de bord</a>
    <a href="joueurs.php"<?= $current === 'joueurs.php' ? ' aria-current="page"' : '' ?>><?= icon('users') ?> Joueurs</a>
    <a href="users.php"<?= $current === 'users.php' ? ' aria-current="page"' : '' ?>><?= icon('shield') ?> Utilisateurs / Arbitres</a>
    <a href="combats.php"<?= $current === 'combats.php' ? ' aria-current="page"' : '' ?>><?= icon('bolt') ?> Combats</a>
    <a href="parametres.php"<?= $current === 'parametres.php' ? ' aria-current="page"' : '' ?>><?= icon('settings') ?> Paramètres</a>
</nav>
