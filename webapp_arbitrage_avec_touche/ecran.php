<?php
/**
 * ecran.php : Écran de salle : affichage TV plein écran, rotation automatique.
 *
 * Écrans : En direct · À venir · Arbre (si publié) · Arbitres.
 * Clavier (ou télécommande) : ← → changer d'écran, Espace pause, F plein écran.
 */
require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$nomApplication = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');
$retardActuel   = getParametreInt($pdo, 'retard_minutes', 0);
$rotationMs     = getParametreInt($pdo, 'intervalle_rotation_affichage_ms', 8000);
$pollMs         = getParametreInt($pdo, 'intervalle_actualisation_ms', 4000);

$enCours = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'en_cours'
     ORDER BY c.temps_debut ASC"
)->fetchAll();

$aVenir = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE c.statut = 'a_venir'
     ORDER BY c.date_prevue IS NULL, c.date_prevue ASC, c.id ASC
     LIMIT 8"
)->fetchAll();

$idsAVenir = array_column($aVenir, 'id');
$arbitresParCombat = [];
if ($idsAVenir) {
    $ph   = implode(',', array_fill(0, count($idsAVenir), '?'));
    $stmt = $pdo->prepare(
        "SELECT ca.combat_id, ca.role, u.username FROM combat_arbitres ca
         JOIN users u ON u.id = ca.user_id WHERE ca.combat_id IN ($ph)"
    );
    $stmt->execute($idsAVenir);
    foreach ($stmt->fetchAll() as $row) {
        $arbitresParCombat[$row['combat_id']][] = $row;
    }
}

$slugsArb = rolesAvecPermission($pdo, 'arbitrage.acces');
$stArb = $pdo->prepare(
    "SELECT u.id, u.username,
            MAX(c.id) AS combat_actuel_id,
            MAX(CONCAT(j1.pseudo, ' contre ', j2.pseudo)) AS combat_nom
     FROM users u
     LEFT JOIN combat_arbitres ca ON ca.user_id = u.id
     LEFT JOIN combats c ON c.id = ca.combat_id AND c.statut = 'en_cours'
     LEFT JOIN joueurs j1 ON j1.id = c.joueur1_id
     LEFT JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE u.actif = 1 AND u.role IN (" . implode(',', array_fill(0, count($slugsArb), '?')) . ")
     GROUP BY u.id, u.username
     ORDER BY (MAX(c.id) IS NULL), u.username ASC"
);
$stArb->execute($slugsArb);
$arbitresService = $stArb->fetchAll();

$touchesParCombat = [];
foreach ($enCours as $c) {
    $touchesParCombat[$c['id']] = compterTouches(getTouchesCombat($pdo, $c));
}

$arbre = donneesArbre($pdo);
$nbArbitresOccupes = count(array_filter($arbitresService, fn($a) => $a['combat_actuel_id']));

// Écrans de la rotation. « vide » : sauté par la rotation automatique
// (toujours accessible par les onglets), sauf le direct.
$ecrans = [
    ['direct',   'En direct', false],
    ['avenir',   'À venir',   !$aVenir],
];
if ($arbre['nbColonnes'] > 0) $ecrans[] = ['arbre', 'Arbre', false];
$ecrans[] = ['arbitres', 'Arbitres', !$arbitresService];
?>
<!DOCTYPE html>
<html lang="fr" <?= attributsTheme($pdo) ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<?= balisesIcones($pdo) ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<script nonce="<?= e(cspNonce()) ?>" src="<?= e(asset('js/pwa.js')) ?>" defer></script>
<title>Écran de salle · <?= e($nomApplication) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<script nonce="<?= e(cspNonce()) ?>">
window.SABRE_CONFIG = {
    pollMs: <?= $pollMs ?>,
    serverNow: <?= (int)round(microtime(true) * 1000) ?>,
    tzOffsetMs: <?= (int)date('Z') * 1000 ?>,
    baseUrl: <?= json_encode(BASE_URL) ?>
};
window.SABRE_CONFIG.skew = window.SABRE_CONFIG.serverNow - Date.now();
window.serverNow = () => Date.now() + window.SABRE_CONFIG.skew;
window.BASE_URL = <?= json_encode(BASE_URL) ?>;
window.BLE_COMBATS_EN_COURS = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'j1' => $c['joueur1_pseudo'], 'j2' => $c['joueur2_pseudo']], $enCours)) ?>;
</script>
</head>
<body class="ec-page">
<?php require __DIR__ . '/includes/icons.php'; ?>

<header class="ec-top">
    <div class="ec-brand"><?= marqueHtml($pdo) ?><span class="ec-brand-nom"><?= e($nomApplication) ?></span></div>
    <div class="ec-top-end">
        <span class="ec-paused" hidden><?= icon('pause') ?>Rotation en pause</span>
        <div class="ec-clock" data-server-clock aria-label="Heure du serveur"><?= date('H:i:s') ?></div>
        <button type="button" class="ec-fs" data-ec-fs title="Plein écran (F)" aria-label="Plein écran"><?= icon('maximize') ?></button>
    </div>
</header>

<main class="ec-body">
<?php foreach ($ecrans as $i => [$cle, $libelle, $vide]): ?>
    <section class="ec-slide ec-slide-<?= $cle ?><?= $i === 0 ? ' active' : '' ?>" id="ecran-<?= $cle ?>" data-slide="<?= $i ?>" data-vide="<?= $vide ? '1' : '0' ?>"
             role="tabpanel" aria-labelledby="onglet-<?= $cle ?>">
    <?php if ($cle === 'direct'): ?>
        <div class="ec-title">
            <?php if ($enCours): ?><span class="live-dot" aria-hidden="true"></span><?php endif; ?>
            En direct
            <?php if (count($enCours) > 1): ?><span class="ec-count"><?= count($enCours) ?> combats</span><?php endif; ?>
            <?php if ($retardActuel > 0): ?><span class="ec-retard"><?= icon('clock-alert') ?>Retard <?= $retardActuel ?> min</span><?php endif; ?>
        </div>
        <?php if (!$enCours): ?>
            <div class="ec-empty"><div>Aucun combat en cours
                <?php if ($aVenir && $aVenir[0]['date_prevue']): ?><small>Prochain combat à <?= date('H:i', strtotime($aVenir[0]['date_prevue'])) ?> : <?= e($aVenir[0]['joueur1_pseudo']) ?> contre <?= e($aVenir[0]['joueur2_pseudo']) ?>, <span data-countdown-to="<?= dateVersMs($aVenir[0]['date_prevue']) ?>" data-late-text="imminent"></span></small><?php endif; ?>
            </div></div>
        <?php else: ?>
            <div class="ec-live" data-n="<?= count($enCours) ?>">
                <?php foreach ($enCours as $c): $t = $touchesParCombat[$c['id']]; ?>
                    <article class="ec-fight" data-combat-id="<?= (int)$c['id'] ?>" data-statut="en_cours">
                        <div class="ec-duel">
                            <div class="ec-p1"><div class="side-label aka ec-side">Aka</div><div class="ec-name"><?= e($c['joueur1_pseudo']) ?></div><div class="ec-score js-score1"><?= fmtScore($c['score_joueur1']) ?></div></div>
                            <div class="ec-vs" aria-hidden="true"></div>
                            <div class="ec-p2"><div class="side-label ao ec-side">Ao</div><div class="ec-name"><?= e($c['joueur2_pseudo']) ?></div><div class="ec-score js-score2"><?= fmtScore($c['score_joueur2']) ?></div></div>
                        </div>
                        <?= barreAvantage(etatScore($pdo, $c), $c['joueur1_pseudo'], $c['joueur2_pseudo'], 'lead-lg ec-lead') ?>
                        <div class="ec-timer timer js-timer" <?= attrsChrono($c) ?> role="timer" aria-label="Temps restant"><?= gmdate('i:s', tempsRestant($c)) ?></div>
                        <div class="ec-hits" data-hits="<?= (int)$c['id'] ?>">
                            <span class="h1">Touches <strong class="js-h1"><?= $t['j1'] ?></strong></span>
                            <span class="hd">Doubles <strong class="js-hd"><?= $t['doubles'] ?></strong></span>
                            <span class="h2">Touches <strong class="js-h2"><?= $t['j2'] ?></strong></span>
                        </div>
                        <span class="visually-hidden js-live-status" aria-live="polite"></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($cle === 'avenir'): ?>
        <div class="ec-title">À venir<?php if ($aVenir): ?><span class="ec-count"><?= count($aVenir) ?> prochain<?= count($aVenir) > 1 ? 's' : '' ?></span><?php endif; ?></div>
        <?php if (!$aVenir): ?>
            <div class="ec-empty">Aucun combat prévu</div>
        <?php else: ?>
            <div class="table-wrap ec-table-wrap">
                <table class="ec-table">
                    <thead><tr><th scope="col">Heure</th><th scope="col">Combat</th><th scope="col">Arbitres</th><th scope="col" class="text-right">Dans</th></tr></thead>
                    <tbody>
                    <?php foreach ($aVenir as $c): ?>
                        <tr data-combat-id="<?= (int)$c['id'] ?>" data-statut="a_venir">
                            <td class="t"><?= $c['date_prevue'] ? date('H:i', strtotime($c['date_prevue'])) : '--:--' ?></td>
                            <td class="ec-match"><span class="dot-aka" aria-hidden="true"></span><?= e($c['joueur1_pseudo']) ?><span class="vs">contre</span><span class="dot-ao" aria-hidden="true"></span><?= e($c['joueur2_pseudo']) ?></td>
                            <td class="refs"><?= !empty($arbitresParCombat[$c['id']]) ? e(implode(', ', array_column($arbitresParCombat[$c['id']], 'username'))) : '<span class="refs-manque">à affecter</span>' ?></td>
                            <td class="eta" <?= $c['date_prevue'] ? 'data-countdown-to="' . dateVersMs($c['date_prevue']) . '" data-late-text="imminent"' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($cle === 'arbre'): ?>
        <div class="ec-title">Arbre du tournoi
            <span class="bk-legend ec-legend" aria-hidden="true">
                <span><i class="l-live"></i>En cours</span>
                <span><i class="l-wait"></i>À venir</span>
                <span><i class="l-done"></i>Qualifié</span>
            </span>
        </div>
        <?= arbreHtml($arbre, ['liens' => false, 'live' => false, 'fit' => 'tout', 'cadre' => 'conteneur', 'zoomMax' => 3]) ?>

    <?php else: ?>
        <div class="ec-title">Arbitres<?php if ($arbitresService): ?><span class="ec-count"><?= $nbArbitresOccupes ?> en combat sur <?= count($arbitresService) ?></span><?php endif; ?></div>
        <?php if (!$arbitresService): ?>
            <div class="ec-empty">Aucun arbitre actif</div>
        <?php else: ?>
            <div class="ec-refs">
                <?php foreach ($arbitresService as $a): ?>
                    <div class="ec-ref <?= $a['combat_actuel_id'] ? 'busy' : '' ?>">
                        <span class="avatar" aria-hidden="true"><?= e(mb_substr($a['username'], 0, 1)) ?></span>
                        <div>
                            <div class="n"><?= e($a['username']) ?></div>
                            <div class="s"><?= $a['combat_actuel_id'] ? 'Arbitre ' . e($a['combat_nom']) : 'Disponible' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </section>
<?php endforeach; ?>
</main>

<nav class="ec-nav" role="tablist" aria-label="Changer d'écran">
    <?php foreach ($ecrans as $i => [$cle, $libelle, $vide]): ?>
        <button type="button" class="ec-tab<?= $i === 0 ? ' active' : '' ?><?= $vide ? ' is-vide' : '' ?>" id="onglet-<?= $cle ?>" data-dot="<?= $i ?>" role="tab"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="ecran-<?= $cle ?>"><?= e($libelle) ?><span class="ec-tab-bar" aria-hidden="true"></span></button>
    <?php endforeach; ?>
</nav>

<div id="ble-overlay" aria-live="assertive" aria-atomic="true">
    <div>
        <div class="ble-label" id="ble-overlay-label"></div>
        <div class="ble-who" id="ble-overlay-who"></div>
        <div class="ble-time" id="ble-overlay-time"></div>
    </div>
</div>

<a href="<?= BASE_URL ?>index.php" class="ec-quit">Quitter l'écran de salle</a>

<?= scriptJs('app.js') ?>
<?= scriptJs('live.js') ?>
<?= scriptJs('arbre.js') ?>
<?= scriptJs('ble_ecran.js') ?>
<script nonce="<?= e(cspNonce()) ?>">
(function () {
    'use strict';
    const slides = Array.from(document.querySelectorAll('.ec-slide'));
    const tabs = Array.from(document.querySelectorAll('.ec-tab'));
    const pauseBadge = document.querySelector('.ec-paused');
    const rotMs = <?= max(3000, $rotationMs) ?>;
    const hasLive = <?= $enCours ? 'true' : 'false' ?>;
    let current = 0, timer = null, enPause = false;

    // Le direct reste deux fois plus longtemps quand il y a des combats ; l'arbre
    // une fois et demie (il y a plus à lire).
    const dureeDe = i => {
        const cle = slides[i].id;
        if (cle === 'ecran-direct' && hasLive) return rotMs * 2;
        if (cle === 'ecran-arbre') return Math.round(rotMs * 1.5);
        return rotMs;
    };

    function show(i) {
        slides.forEach((s, j) => {
            s.classList.toggle('active', j === i);
            s.setAttribute('aria-hidden', j === i ? 'false' : 'true');
        });
        tabs.forEach((d, j) => {
            d.classList.remove('active');
            d.setAttribute('aria-selected', j === i ? 'true' : 'false');
            d.tabIndex = j === i ? 0 : -1;
        });
        // Relance la barre de progression de l'onglet actif (durée de l'écran)
        void tabs[i].offsetWidth;
        tabs[i].style.setProperty('--ec-dur', dureeDe(i) + 'ms');
        tabs[i].classList.add('active');
        current = i;
        if (slides[i].id === 'ecran-arbre' && window.ajusterArbres) window.ajusterArbres();
    }

    /** Écran suivant de la rotation, en sautant les écrans vides. */
    function suivant(depuis) {
        for (let k = 1; k <= slides.length; k++) {
            const j = (depuis + k) % slides.length;
            if (j === 0 || slides[j].dataset.vide !== '1') return j;
        }
        return 0;
    }
    function next() {
        const nxt = suivant(current);
        if (nxt === 0) { window.location.reload(); return; } // données fraîches à chaque tour
        show(nxt);
        schedule();
    }
    function schedule() {
        clearTimeout(timer);
        if (!enPause) timer = setTimeout(next, dureeDe(current));
    }
    function aller(i) { show((i + slides.length) % slides.length); schedule(); }
    function basculerPause() {
        enPause = !enPause;
        document.body.classList.toggle('ec-en-pause', enPause);
        if (pauseBadge) pauseBadge.hidden = !enPause;
        if (enPause) clearTimeout(timer); else aller(current);
    }

    tabs.forEach((d, i) => d.addEventListener('click', () => aller(i)));

    // Plein écran (bouton, touche F, double-clic)
    const fsBtn = document.querySelector('[data-ec-fs]');
    function pleinEcran() {
        if (document.fullscreenElement) document.exitFullscreen && document.exitFullscreen();
        else if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
    }
    if (fsBtn) {
        if (!document.documentElement.requestFullscreen) fsBtn.hidden = true;
        fsBtn.addEventListener('click', pleinEcran);
    }
    document.addEventListener('dblclick', e => { if (!e.target.closest('button, a')) pleinEcran(); });
    document.addEventListener('fullscreenchange', () => document.body.classList.toggle('ec-fullscreen', !!document.fullscreenElement));

    // Clavier / télécommande
    document.addEventListener('keydown', e => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); aller(current + 1); }
        else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); aller(current - 1); }
        else if (e.key === ' ' || e.key === 'k' || e.key === 'K') { e.preventDefault(); basculerPause(); }
        else if (e.key === 'f' || e.key === 'F') { pleinEcran(); }
        else if (/^[1-9]$/.test(e.key) && Number(e.key) <= slides.length) { aller(Number(e.key) - 1); }
    });

    // Une touche ramène sur l'écran « En direct »
    document.addEventListener('touche-ecran', () => { if (current !== 0) aller(0); });

    // Curseur et commandes masqués quand la souris ne bouge plus (TV)
    let inactif = null;
    const reveil = () => {
        document.body.classList.remove('ec-idle');
        clearTimeout(inactif);
        inactif = setTimeout(() => document.body.classList.add('ec-idle'), 3000);
    };
    ['mousemove', 'mousedown', 'keydown', 'touchstart'].forEach(ev => document.addEventListener(ev, reveil, { passive: true }));
    reveil();

    // L'écran ne doit pas se mettre en veille pendant le tournoi
    let verrou = null;
    async function garderAllume() {
        try {
            if (!('wakeLock' in navigator) || document.hidden) return;
            verrou = await navigator.wakeLock.request('screen');
            verrou.addEventListener('release', () => { verrou = null; });
        } catch (_) { /* refusé (ex. HTTP) : sans gravité */ }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !verrou) garderAllume(); });
    garderAllume();

    show(0);
    schedule();
})();
</script>
</body>
</html>
