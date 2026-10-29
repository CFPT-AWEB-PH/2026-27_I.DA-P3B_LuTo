<?php
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Sabre Laser Arbitrage';
$user = currentUser();
$nomApplication = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');

/*
 * Page courante : un seul attribut aria-current="page" sert à la fois aux
 * lecteurs d'écran et au style (pas de classe « active » en double).
 */
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));
$espace = in_array($currentDir, ['admin', 'arbitrage'], true) ? $currentDir : '';

function navCurrent(string $script, ?string $dir = null): string
{
    global $currentScript, $currentDir, $espace;
    if ($dir !== null) {
        return $currentDir === $dir ? ' aria-current="page"' : '';
    }
    return ($currentScript === $script && $espace === '') ? ' aria-current="page"' : '';
}

// Navigation publique : les 4 destinations des spectateurs
$navPublique = [
    ['index.php',      'live',    'Direct'],
    ['arbre.php',      'bracket', 'Arbre'],
    ['scores.php',     'list',    'Résultats'],
    ['classement.php', 'trophy',  'Classement'],
];
$peutArbitrer = $user && can('arbitrage.acces', 'arbitrage.tous_combats');
$peutAdmin = $user && peutAccederAdmin();

// 5e onglet mobile : l'espace de travail du compte connecté. L'administration
// passe avant l'arbitrage (un admin peut aussi arbitrer : menu du compte).
$ongletEspace = null;
if ($peutAdmin) $ongletEspace = ['admin/index.php', 'dashboard', 'Admin', 'admin'];
elseif ($peutArbitrer) $ongletEspace = ['arbitrage/index.php', 'target', 'Arbitrage', 'arbitrage'];

// Bouton « Aide » : ouvre la fiche du guide qui correspond à la page affichée
$ficheAide = guideFichePourPage($pdo, $currentScript, $currentDir);
$lienAide = BASE_URL . 'aide.php' . ($ficheAide ? '#' . $ficheAide : '');

$flashes = getFlashes();
$iconesFlash = ['success' => 'check', 'error' => 'alert-triangle', 'warning' => 'alert-triangle', 'info' => 'info'];
?>
<!DOCTYPE html>
<html lang="fr" <?= attributsTheme($pdo) ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?= balisesIcones($pdo) ?>
<!-- PWA : application installable (écran d'accueil, plein écran) -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="<?= e(nomCourtApplication($nomApplication)) ?>">
<meta name="description" content="<?= e($nomApplication) ?> : combats de sabre laser en direct, arbitrage à trois, résultats et classement.">
<title><?= e($pageTitle) ?> · <?= e($nomApplication) ?></title>
<link rel="preload" href="<?= BASE_URL ?>assets/fonts/barlow-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= BASE_URL ?>assets/fonts/barlow-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= BASE_URL ?>assets/fonts/barlow-condensed-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<?php if (!empty($extraHead)) echo $extraHead; ?>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<script nonce="<?= e(cspNonce()) ?>">
    // Réglages exposés au JS. serverNow = heure du serveur au moment de la
    // génération de la page : les chronos et comptes à rebours se calent dessus,
    // jamais sur l'horloge (parfois fausse) du téléphone ou du PC.
    window.SABRE_CONFIG = {
        pollMs: <?= getParametreInt($pdo, 'intervalle_actualisation_ms', 4000) ?>,
        serverNow: <?= (int)round(microtime(true) * 1000) ?>,
        tzOffsetMs: <?= (int)date('Z') * 1000 ?>,
        baseUrl: <?= json_encode(BASE_URL) ?>
    };
    window.SABRE_CONFIG.skew = window.SABRE_CONFIG.serverNow - Date.now();
    window.serverNow = () => Date.now() + window.SABRE_CONFIG.skew;
</script>
</head>
<body>
<?php require __DIR__ . '/icons.php'; ?>
<a class="skip-link visually-hidden-focusable" href="#main-content">Aller au contenu</a>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>index.php" aria-label="<?= e($nomApplication) ?> : accueil"><?= marqueHtml($pdo) ?><span class="brand-nom"><?= e($nomApplication) ?></span></a>

        <nav class="main-nav" id="main-nav" aria-label="Navigation principale">
            <?php foreach ($navPublique as [$href, $ic, $lib]): ?>
                <a href="<?= BASE_URL . $href ?>"<?= navCurrent($href) ?>><?= icon($ic) ?><?= e($lib) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-end">
            <span class="server-clock" data-server-clock title="Heure du serveur : toutes les heures affichées s'y réfèrent"><?= date('H:i:s') ?></span>
            <?php if ($peutArbitrer || $peutAdmin): ?>
                <span class="topbar-sep" aria-hidden="true"></span>
                <?php if ($peutArbitrer): ?>
                    <a class="workspace-link" href="<?= BASE_URL ?>arbitrage/index.php"<?= navCurrent('', 'arbitrage') ?> title="Espace arbitrage"><?= icon('target') ?><span>Arbitrage</span></a>
                <?php endif; ?>
                <?php if ($peutAdmin): ?>
                    <a class="workspace-link" href="<?= BASE_URL ?>admin/index.php"<?= navCurrent('', 'admin') ?> title="Administration"><?= icon('dashboard') ?><span>Admin</span></a>
                <?php endif; ?>
            <?php endif; ?>

            <a class="btn btn-icon topbar-aide" href="<?= e($lienAide) ?>"<?= navCurrent('aide.php') ?> title="<?= $ficheAide ? 'Aide sur cette page' : 'Aide et guide' ?>" aria-label="<?= $ficheAide ? 'Aide sur cette page' : 'Aide et guide' ?>"><?= icon('help') ?></a>
            <a class="btn btn-icon topbar-tv" href="<?= BASE_URL ?>ecran.php" title="Écran de salle (affichage TV)" aria-label="Écran de salle"><?= icon('monitor') ?></a>

            <?php if ($user): ?>
                <details class="menu account-menu">
                    <summary aria-label="Mon compte : <?= e($user['username']) ?>">
                        <span class="avatar" aria-hidden="true"><?= e(mb_substr($user['username'], 0, 1)) ?></span>
                    </summary>
                    <div class="menu-panel" role="menu">
                        <div class="account-head">
                            <strong><?= e($user['username']) ?></strong>
                            <span><?= e(nomRole($pdo, $user['role'])) ?></span>
                        </div>
                        <div class="menu-sep" role="separator"></div>
                        <?php if ($peutArbitrer): ?><a role="menuitem" href="<?= BASE_URL ?>arbitrage/index.php"><?= icon('target') ?>Espace arbitrage</a><?php endif; ?>
                        <?php if ($peutAdmin): ?><a role="menuitem" href="<?= BASE_URL ?>admin/index.php"><?= icon('dashboard') ?>Administration</a><?php endif; ?>
                        <a role="menuitem" href="<?= BASE_URL ?>compte.php"<?= navCurrent('compte.php') ?>><?= icon('lock') ?>Changer de mot de passe</a>
                        <a role="menuitem" href="<?= BASE_URL ?>ecran.php"><?= icon('monitor') ?>Écran de salle</a>
                        <a role="menuitem" href="<?= BASE_URL ?>aide.php"<?= navCurrent('aide.php') ?>><?= icon('help') ?>Aide et guide</a>
                        <div class="menu-sep" role="separator"></div>
                        <form method="post" action="<?= BASE_URL ?>logout.php" class="logout-form">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                            <button type="submit" role="menuitem"><?= icon('logout') ?>Se déconnecter</button>
                        </form>
                    </div>
                </details>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm nav-login" href="<?= BASE_URL ?>login.php"<?= navCurrent('login.php') ?>>Connexion</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<nav class="bottom-nav" aria-label="Navigation rapide">
    <div class="bottom-nav-inner">
        <?php foreach ($navPublique as [$href, $ic, $lib]): ?>
            <a class="bottom-nav-item" href="<?= BASE_URL . $href ?>"<?= navCurrent($href) ?>><?= icon($ic) ?><span><?= e($lib) ?></span></a>
        <?php endforeach; ?>
        <?php if ($ongletEspace): ?>
            <a class="bottom-nav-item" href="<?= BASE_URL . $ongletEspace[0] ?>"<?= navCurrent('', $ongletEspace[3]) ?>><?= icon($ongletEspace[1]) ?><span><?= e($ongletEspace[2]) ?></span></a>
        <?php endif; ?>
    </div>
</nav>

<?php $retardActuel = getParametreInt($pdo, 'retard_minutes', 0); ?>
<?php if ($retardActuel > 0): ?>
    <div class="retard-banner" role="status">
        <?= icon('clock-alert') ?>
        <span>Retard de <strong><?= $retardActuel ?> min</strong> : les horaires affichés en tiennent déjà compte.</span>
    </div>
<?php endif; ?>

<div class="toast-container" id="toast-region">
    <?php foreach ($flashes as $flash): $t = in_array($flash['type'], ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info'; ?>
        <div class="toast toast-<?= $t ?>" role="<?= $t === 'error' ? 'alert' : 'status' ?>" data-autohide="<?= in_array($t, ['success', 'info'], true) && empty($flash['persistant']) ? '1' : '0' ?>">
            <span class="toast-icon" aria-hidden="true"><?= icon($iconesFlash[$t]) ?></span>
            <span class="toast-msg"><?= e($flash['message']) ?></span>
            <button type="button" class="toast-close" aria-label="Fermer"><?= icon('x') ?></button>
        </div>
    <?php endforeach; ?>
</div>

<main class="page-content" id="main-content">
