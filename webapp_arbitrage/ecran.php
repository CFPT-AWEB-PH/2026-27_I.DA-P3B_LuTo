<?php
/**
 * ecran.php — Affichage public "écran de salle" : pensé pour être laissé
 * ouvert en permanence sur un écran/TV dans le hall. Fait défiler
 * automatiquement plusieurs écrans d'information (combats en cours,
 * prochains combats, arbitres de service) sans navigation ni interaction
 * requise. La fréquence de rotation est configurable dans
 * Admin → Paramètres ("intervalle_rotation_affichage_ms").
 */

require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$nomApplication = getParametre($pdo, 'nom_application', 'Sabre Laser Arbitrage');
$retardActuel = getParametreInt($pdo, 'retard_minutes', 0);
$rotationMs = getParametreInt($pdo, 'intervalle_rotation_affichage_ms', 8000);
$pollMs = getParametreInt($pdo, 'intervalle_actualisation_ms', 4000);

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
     ORDER BY c.date_prevue ASC
     LIMIT 8"
)->fetchAll();

$idsAVenir = array_column($aVenir, 'id');
$arbitresParCombat = [];
if ($idsAVenir) {
    $ph = implode(',', array_fill(0, count($idsAVenir), '?'));
    $stmt = $pdo->prepare(
        "SELECT ca.combat_id, ca.role, u.username FROM combat_arbitres ca JOIN users u ON u.id = ca.user_id WHERE ca.combat_id IN ($ph)"
    );
    $stmt->execute($idsAVenir);
    foreach ($stmt->fetchAll() as $row) {
        $arbitresParCombat[$row['combat_id']][] = $row;
    }
}

$arbitresService = $pdo->query(
    "SELECT u.*, c.id AS combat_actuel_id, j1.pseudo AS combat_j1, j2.pseudo AS combat_j2
     FROM users u
     LEFT JOIN combat_arbitres ca ON ca.user_id = u.id
     LEFT JOIN combats c ON c.id = ca.combat_id AND c.statut = 'en_cours'
     LEFT JOIN joueurs j1 ON j1.id = c.joueur1_id
     LEFT JOIN joueurs j2 ON j2.id = c.joueur2_id
     WHERE u.role IN ('arbitre','admin') AND u.actif = 1
     GROUP BY u.id
     ORDER BY (c.id IS NOT NULL) DESC, u.username ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex">
<title>Écran — <?= e($nomApplication) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
<script>window.SABRE_CONFIG = { pollMs: <?= $pollMs ?> };</script>
<style>
    /* Mise en page dédiée "écran de salle" : plein écran, pas de nav, gros
       caractères lisibles de loin. Réutilise les tokens de design du site
       (couleurs, polices) pour rester visuellement cohérent. */
    body { overflow: hidden; }
    .ecran-wrap {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        padding: var(--space-5) var(--space-6);
    }
    .ecran-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: var(--space-5);
        flex-wrap: wrap;
        gap: var(--space-3);
    }
    .ecran-brand {
        font-family: var(--font-display);
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-size: clamp(1.4rem, 2.5vw, 2.2rem);
        display: flex;
        align-items: center;
        gap: var(--space-3);
    }
    .ecran-brand .brand-blade { width: 6px; height: 2em; border-radius: 4px; background: var(--grad-brand); box-shadow: 0 0 24px var(--cyan); }
    .ecran-horloge { font-family: var(--font-display); font-size: clamp(1.2rem, 2vw, 1.8rem); color: var(--cyan-soft); font-variant-numeric: tabular-nums; }

    .ecran-slides { position: relative; flex: 1; }
    .ecran-slide {
        display: none;
        animation: fadeUp var(--dur-slow) var(--ease-out) both;
    }
    .ecran-slide.active { display: block; }
    .ecran-slide h2 {
        font-size: clamp(1.4rem, 3vw, 2.4rem);
        margin-bottom: var(--space-5);
        display: flex; align-items: center; gap: var(--space-3);
    }

    .ecran-combats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: var(--space-5); }
    .ecran-combat-card { padding: var(--space-5); }
    .ecran-combat-card .combat-vs .player { font-size: clamp(1.2rem, 2.4vw, 2rem); }
    .ecran-combat-card .score-big { font-size: clamp(2.2rem, 5vw, 4rem) !important; }
    .ecran-combat-card .timer { font-size: clamp(1.8rem, 3.5vw, 3rem); }

    .ecran-table { font-size: clamp(1rem, 1.6vw, 1.35rem); }
    .ecran-table th, .ecran-table td { padding: var(--space-3) var(--space-4); }

    .ecran-arbitres-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: var(--space-4); }
    .ecran-arbitre-card { font-size: clamp(1.1rem, 1.8vw, 1.5rem); text-align: center; }
    .ecran-arbitre-card i { font-size: 1.6em; display: block; margin-bottom: var(--space-2); }

    .ecran-dots { display: flex; justify-content: center; gap: var(--space-2); margin-top: var(--space-5); }
    .ecran-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--border-strong); transition: background var(--dur-base) var(--ease-out); }
    .ecran-dot.active { background: var(--cyan); box-shadow: 0 0 10px var(--cyan); }

    .ecran-footer-link { position: fixed; bottom: var(--space-2); right: var(--space-3); font-size: 0.7rem; color: var(--text-faint); opacity: 0.5; }

    @media (prefers-reduced-motion: reduce) {
        .ecran-slide { animation: none; }
    }
</style>
</head>
<body>
<div class="bg-fx" aria-hidden="true"></div>
<div class="ecran-wrap">
    <div class="ecran-header">
        <div class="ecran-brand"><span class="brand-blade" aria-hidden="true"></span><?= e($nomApplication) ?></div>
        <div class="ecran-horloge" id="ecran-horloge" aria-hidden="true">--:--:--</div>
    </div>

    <?php if ($retardActuel > 0): ?>
        <div class="retard-banner" role="status" style="margin-bottom: var(--space-4); border-radius: var(--radius-md); border: 1px solid rgba(255,59,92,0.4);">
            <?= icon('clock-alert') ?>
            Retard actuel : <strong><?= $retardActuel ?> minute<?= $retardActuel > 1 ? 's' : '' ?></strong>
        </div>
    <?php endif; ?>

    <div class="ecran-slides">
        <section class="ecran-slide active" data-slide="0" aria-label="Combats en cours">
            <h2><?= icon('broadcast', 'text-live') ?> En cours</h2>
            <?php if (!$enCours): ?>
                <div class="empty-state">Aucun combat en cours pour le moment.</div>
            <?php else: ?>
                <div class="ecran-combats-grid">
                    <?php foreach ($enCours as $c): ?>
                        <div class="card ecran-combat-card combat-card" data-combat-id="<?= (int)$c['id'] ?>">
                            <div class="combat-vs">
                                <div class="player"><?= e($c['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($c['score_joueur1'], 1) ?></span></div>
                                <div class="vs-label" aria-hidden="true">VS</div>
                                <div class="player"><?= e($c['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($c['score_joueur2'], 1) ?></span></div>
                            </div>
                            <div class="timer js-timer" data-remaining="<?= tempsRestant($c) ?>" role="timer" aria-label="Temps restant">--:--</div>
                            <p class="visually-hidden js-live-status" aria-live="polite"></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ecran-slide" data-slide="1" aria-label="Prochains combats">
            <h2><?= icon('history') ?> Prochains combats</h2>
            <?php if (!$aVenir): ?>
                <div class="empty-state">Aucun combat prévu pour le moment.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="ecran-table">
                        <caption class="visually-hidden">Liste des prochains combats avec leurs arbitres</caption>
                        <thead><tr><th scope="col">Combat</th><th scope="col">Heure prévue</th><th scope="col">Arbitres</th></tr></thead>
                        <tbody>
                        <?php foreach ($aVenir as $c): ?>
                            <tr>
                                <td><?= e($c['joueur1_pseudo']) ?> vs <?= e($c['joueur2_pseudo']) ?></td>
                                <td><?= formatDateFr($c['date_prevue']) ?></td>
                                <td>
                                    <?php if (!empty($arbitresParCombat[$c['id']])): ?>
                                        <?= implode(', ', array_map(static fn($a) => e($a['username']), $arbitresParCombat[$c['id']])) ?>
                                    <?php else: ?>
                                        <span class="text-faint">à affecter</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="ecran-slide" data-slide="2" aria-label="Arbitres de service">
            <h2><?= icon('referee-badge') ?> Arbitres de service</h2>
            <?php if (!$arbitresService): ?>
                <div class="empty-state">Aucun arbitre actif.</div>
            <?php else: ?>
                <div class="ecran-arbitres-grid">
                    <?php foreach ($arbitresService as $a): ?>
                        <div class="card ecran-arbitre-card">
                            <?= $a['combat_actuel_id'] ? icon('broadcast', 'text-live') : icon('user-check') ?>
                            <strong><?= e($a['username']) ?></strong>
                            <br>
                            <?php if ($a['combat_actuel_id']): ?>
                                <small class="hint">En combat : <?= e($a['combat_j1']) ?> vs <?= e($a['combat_j2']) ?></small>
                            <?php else: ?>
                                <small class="hint">Disponible</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="ecran-dots" role="tablist" aria-label="Écrans">
        <span class="ecran-dot active" data-dot="0"></span>
        <span class="ecran-dot" data-dot="1"></span>
        <span class="ecran-dot" data-dot="2"></span>
    </div>
</div>

<a href="<?= BASE_URL ?>index.php" class="ecran-footer-link">Quitter le mode écran</a>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<script>
(function () {
    // Horloge, mise à jour chaque seconde.
    const horloge = document.getElementById('ecran-horloge');
    function tickHorloge() {
        horloge.textContent = new Date().toLocaleTimeString('fr-FR');
    }
    tickHorloge();
    setInterval(tickHorloge, 1000);

    // Rotation entre les écrans, fréquence configurable (Admin → Paramètres).
    const slides = Array.from(document.querySelectorAll('.ecran-slide'));
    const dots = Array.from(document.querySelectorAll('.ecran-dot'));
    const rotationMs = <?= $rotationMs ?>;
    let index = 0;

    function afficher(i) {
        slides.forEach((s, j) => s.classList.toggle('active', j === i));
        dots.forEach((d, j) => d.classList.toggle('active', j === i));
    }

    function suivant() {
        index = (index + 1) % slides.length;
        afficher(index);
        // Après un cycle complet, on recharge la page pour repartir sur des
        // données fraîches (nouveaux combats, retard mis à jour, etc.) sans
        // dépendre d'un endpoint d'API dédié à cette page.
        if (index === 0) {
            window.setTimeout(() => window.location.reload(), rotationMs);
        } else {
            window.setTimeout(suivant, rotationMs);
        }
    }

    if (slides.length > 1) {
        window.setTimeout(suivant, rotationMs);
    }
})();
</script>
</body>
</html>
