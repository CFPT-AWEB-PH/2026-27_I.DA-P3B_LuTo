<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Sabre Laser Arbitrage';
$user = currentUser();
$nomApplication = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');

/**
 * Détermine le lien de nav "actif" pour poser aria-current="page" —
 * essentiel pour l'accessibilité (lecteurs d'écran) ET pour le style visuel,
 * qui s'appuient tous les deux sur ce seul attribut (pas de classe dupliquée).
 */
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));

function navCurrent(string $script, ?string $dir = null): string
{
    global $currentScript, $currentDir;
    if ($dir !== null) {
        return $currentDir === $dir ? ' aria-current="page"' : '';
    }
    return ($currentScript === $script && $currentDir !== 'admin' && $currentDir !== 'arbitrage') ? ' aria-current="page"' : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta name="description" content="<?= e($nomApplication) ?> — gestion de combats de sabre laser : arbitrage à 3 (central + 2 coins), moyenne des points en temps réel, classement et suivi public des combats.">
<title><?= e($pageTitle) ?> — <?= e($nomApplication) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Rajdhani:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if (!empty($extraHead)) echo $extraHead; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
<script>
    // Configuration lue depuis Admin → Paramètres, exposée au JS avant le
    // chargement de live.js (aucune valeur temps réel codée en dur côté JS).
    window.SABRE_CONFIG = { pollMs: <?= getParametreInt($pdo, 'intervalle_actualisation_ms', 4000) ?> };
</script>
</head>
<body>
<?php require __DIR__ . '/icons.php'; ?>
<a class="skip-link visually-hidden-focusable" href="#main-content">Aller au contenu principal</a>
<div class="bg-fx" aria-hidden="true"></div>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>index.php"><span class="brand-blade" aria-hidden="true"></span><?= e($nomApplication) ?></a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="main-nav">
            <?= icon('sliders') ?><span class="visually-hidden">Ouvrir le menu</span>
        </button>
        <nav class="main-nav" id="main-nav" aria-label="Navigation principale">
            <a href="<?= BASE_URL ?>index.php"<?= navCurrent('index.php') ?>><?= icon('home') ?> Accueil</a>
            <a href="<?= BASE_URL ?>arbre.php"<?= navCurrent('arbre.php') ?>><?= icon('bracket') ?> Arbre</a>
            <a href="<?= BASE_URL ?>classement.php"<?= navCurrent('classement.php') ?>><?= icon('trophy') ?> Classement</a>
            <a href="<?= BASE_URL ?>scores.php"<?= navCurrent('scores.php') ?>><?= icon('chart') ?> Scores</a>
            <a href="<?= BASE_URL ?>ecran.php"<?= navCurrent('ecran.php') ?>><?= icon('monitor') ?> Écran</a>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'admin'): ?>
                    <a href="<?= BASE_URL ?>admin/index.php"<?= navCurrent('', 'admin') ?> class="nav-admin"><?= icon('dashboard') ?> Admin</a>
                <?php endif; ?>
                <?php if (in_array($user['role'], ['admin', 'arbitre'], true)): ?>
                    <a href="<?= BASE_URL ?>arbitrage/index.php"<?= navCurrent('', 'arbitrage') ?> class="nav-arbitrage"><?= icon('target') ?> Arbitrage</a>
                <?php endif; ?>
                <span class="user-badge"><?= icon('user') ?> <?= e($user['username']) ?> (<?= e($user['role']) ?>)</span>
                <form method="post" action="<?= BASE_URL ?>logout.php" class="logout-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <button type="submit" class="btn-link"><?= icon('logout') ?> Déconnexion</button>
                </form>
            <?php else: ?>
                <a href="<?= BASE_URL ?>login.php"<?= navCurrent('login.php') ?>><?= icon('login') ?> Connexion</a>
                <a href="<?= BASE_URL ?>register.php"<?= navCurrent('register.php') ?> class="btn-link-alt"><?= icon('user-plus') ?> Créer un compte</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php $retardActuel = getParametreInt($pdo, 'retard_minutes', 0); ?>
<?php if ($retardActuel > 0): ?>
    <div class="retard-banner" role="status">
        <?= icon('clock-alert') ?>
        Retard actuel : <strong><?= $retardActuel ?> minute<?= $retardActuel > 1 ? 's' : '' ?></strong> — les horaires affichés en tiennent compte.
    </div>
<?php endif; ?>
<main class="page-content" id="main-content">
<div aria-live="polite" role="status" class="visually-hidden" id="flash-live-region">
    <?php foreach (getFlashes() as $flash): $__flashes[] = $flash; ?>
        <?= e($flash['message']) ?>
    <?php endforeach; ?>
</div>
<?php foreach ($__flashes ?? [] as $flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
