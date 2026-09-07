<?php
/**
 * ecran.php — Écran de salle : affichage TV plein écran, rotation automatique.
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
     ORDER BY c.date_prevue ASC
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
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
<script>window.SABRE_CONFIG = { pollMs: <?= $pollMs ?> };</script>
<style>
/* ── Reset écran ── */
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; overflow: hidden; }

/* ── Fond + layout global ── */
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-body);
    display: flex;
    flex-direction: column;
}

/* Grille de fond subtile */
.ecran-bg {
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image:
        linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
    background-size: 60px 60px;
    mask-image: radial-gradient(ellipse 90% 90% at 50% 50%, black, transparent 85%);
}

/* ── Topbar écran ── */
.ecran-top {
    position: relative; z-index: 10;
    display: flex; align-items: center; justify-content: space-between;
    padding: clamp(.8rem,2vw,1.4rem) clamp(1rem,3vw,2.5rem);
    border-bottom: 1px solid var(--border);
    background: rgba(5,5,7,.8);
    backdrop-filter: blur(16px);
    flex: none;
}

.ecran-brand {
    display: flex; align-items: center; gap: 14px;
    font-family: var(--font-display);
    font-size: clamp(1rem, 2.2vw, 1.8rem);
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #fff;
}
.ecran-brand-blade {
    width: 5px; height: 2em; border-radius: 4px;
    background: linear-gradient(180deg, var(--accent), rgba(74,240,200,.25));
    box-shadow: 0 0 16px rgba(74,240,200,.6);
    flex: none;
}

.ecran-clock {
    font-family: var(--font-display);
    font-size: clamp(1.1rem, 2.4vw, 2rem);
    font-weight: 700;
    color: var(--accent);
    letter-spacing: .06em;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 0 20px rgba(74,240,200,.5);
}

/* ── Slides ── */
.ecran-body { flex: 1; position: relative; overflow: hidden; z-index: 5; }

.ecran-slide {
    position: absolute; inset: 0;
    display: flex; flex-direction: column;
    padding: clamp(1rem,2.5vw,2rem) clamp(1rem,3vw,2.5rem);
    opacity: 0; pointer-events: none;
    transition: opacity .5s ease, transform .5s ease;
    transform: translateY(12px);
}
.ecran-slide.active {
    opacity: 1; pointer-events: auto; transform: translateY(0);
}

/* ── Titre de slide ── */
.ecran-title {
    display: flex; align-items: center; gap: 12px;
    font-family: var(--font-display);
    font-size: clamp(1.1rem, 2.4vw, 1.9rem);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: clamp(.8rem,2vw,1.6rem);
    color: rgba(255,255,255,.85);
    flex: none;
}
.ecran-title .icon { color: var(--accent); width: 1.1em; height: 1.1em; }
.ecran-title .live-dot {
    width: .55em; height: .55em; border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 10px var(--accent);
    animation: pulse 1.4s ease-in-out infinite;
    flex: none;
}
@keyframes pulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.5; transform:scale(.8); } }

/* ── Grille combats en cours ── */
.ecran-live-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(340px,100%), 1fr));
    gap: clamp(.8rem,2vw,1.6rem);
    flex: 1; align-content: start;
    overflow-y: auto;
}

/* Carte combat TV */
.ecran-fight-card {
    background: rgba(255,255,255,.04);
    border: 1.5px solid var(--accent);
    border-radius: 14px;
    padding: clamp(1rem,2vw,1.6rem);
    box-shadow: 0 0 30px rgba(74,240,200,.12), inset 0 0 30px rgba(74,240,200,.03);
    position: relative; overflow: hidden;
}
.ecran-fight-card::before {
    content: ""; position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(74,240,200,.05) 0%, transparent 60%);
    pointer-events: none;
}
.ecran-fight-label {
    font-size: clamp(.55rem,1.2vw,.85rem); font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--accent); margin-bottom: clamp(.4rem,1vw,.8rem);
    display: flex; align-items: center; gap: 6px;
}

/* Joueurs face-à-face */
.ecran-versus {
    display: flex; align-items: stretch; gap: 0;
    margin-bottom: clamp(.5rem,1.2vw,1rem);
}
.ecran-player {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; gap: 4px; text-align: center;
    padding: 4px;
}
.ecran-player-name {
    font-family: var(--font-ui);
    font-size: clamp(.85rem,1.8vw,1.4rem);
    font-weight: 700; color: rgba(255,255,255,.85);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    width: 100%;
}
.ecran-player-score {
    font-family: var(--font-display);
    font-size: clamp(2rem,5vw,4.5rem);
    font-weight: 900;
    color: var(--accent);
    line-height: 1;
    letter-spacing: -.02em;
    text-shadow: 0 0 30px rgba(74,240,200,.5);
    transition: color .3s, text-shadow .3s;
}
.score-updated { color: #fff !important; text-shadow: 0 0 40px rgba(255,255,255,.9) !important; }

.ecran-vs-sep {
    display: flex; align-items: center; padding: 0 10px;
    font-family: var(--font-display); font-size: clamp(.8rem,1.6vw,1.3rem);
    font-weight: 900; color: rgba(255,255,255,.2); letter-spacing: .04em;
    flex: none;
}

.ecran-timer {
    text-align: center;
    font-family: var(--font-display);
    font-size: clamp(1.2rem,3vw,2.6rem);
    font-weight: 900;
    letter-spacing: .08em;
    color: rgba(255,255,255,.6);
}
.ecran-timer.urgent { color: var(--danger); animation: blink .7s ease-in-out infinite; }
@keyframes blink { 0%,100% { opacity:1; } 50% { opacity:.4; } }

/* ── Slide "À venir" ── */
.ecran-table {
    width: 100%; border-collapse: collapse;
    font-size: clamp(.85rem,1.8vw,1.3rem);
}
.ecran-table th {
    padding: clamp(.5rem,1.2vw,1rem) clamp(.6rem,1.5vw,1.2rem);
    text-align: left; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
    font-size: .7em; color: rgba(255,255,255,.4);
    border-bottom: 1px solid var(--border);
}
.ecran-table td {
    padding: clamp(.5rem,1.2vw,1rem) clamp(.6rem,1.5vw,1.2rem);
    border-bottom: 1px solid var(--border);
    color: rgba(255,255,255,.8);
    vertical-align: middle;
}
.ecran-table tbody tr:hover td { background: rgba(255,255,255,.03); }
.ecran-table .fight-name { font-weight: 700; font-family: var(--font-ui); }
.ecran-table .fight-time { color: var(--accent); font-family: var(--font-display); font-weight: 700; letter-spacing: .04em; }
.ecran-table .fight-refs { color: rgba(255,255,255,.5); font-size: .9em; }

/* ── Slide "Arbitres" ── */
.ecran-refs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(220px,100%), 1fr));
    gap: clamp(.7rem,1.5vw,1.2rem);
    align-content: start;
    flex: 1; overflow-y: auto;
}
.ecran-ref-card {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border-mid);
    border-radius: 12px;
    padding: clamp(.8rem,1.8vw,1.4rem);
    text-align: center;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.ecran-ref-card.is-live {
    border-color: var(--accent);
    background: rgba(74,240,200,.06);
    box-shadow: 0 0 16px rgba(74,240,200,.12);
}
.ecran-ref-icon { font-size: clamp(1.4rem,3vw,2.5rem); line-height: 1; }
.ecran-ref-name {
    font-family: var(--font-ui); font-weight: 800;
    font-size: clamp(.9rem,1.8vw,1.4rem);
    color: #fff;
}
.ecran-ref-status {
    font-size: clamp(.65rem,1.2vw,.9rem);
    color: rgba(255,255,255,.45);
}
.ecran-ref-card.is-live .ecran-ref-status { color: var(--accent); font-weight: 700; }

/* ── État vide ── */
.ecran-empty {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 12px;
    color: rgba(255,255,255,.25);
    font-family: var(--font-display); font-size: clamp(1rem,2vw,1.5rem);
    font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
}
.ecran-empty-icon { font-size: clamp(2rem,5vw,4rem); opacity: .4; }

/* ── Retard banner ── */
.ecran-retard {
    background: rgba(224,84,84,.15); border: 1px solid rgba(224,84,84,.35);
    border-radius: 8px; padding: 8px 16px; margin-bottom: 12px;
    font-size: clamp(.75rem,1.4vw,1rem); color: #ffa8a8; font-weight: 700;
    display: flex; align-items: center; gap: 8px; flex: none;
}

/* ── Dots de navigation ── */
.ecran-nav {
    position: relative; z-index: 10;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: clamp(.4rem,1vw,.8rem);
    background: rgba(5,5,7,.7);
    border-top: 1px solid var(--border);
    flex: none;
}
.ecran-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: rgba(255,255,255,.15);
    transition: background .3s, box-shadow .3s, width .3s;
    cursor: pointer;
}
.ecran-dot.active {
    width: 24px; border-radius: 4px;
    background: var(--accent);
    box-shadow: 0 0 10px rgba(74,240,200,.6);
}

/* ── Lien quitter ── */
.ecran-quit {
    position: fixed; bottom: 10px; right: 16px;
    font-size: .65rem; color: rgba(255,255,255,.2);
    text-decoration: none; z-index: 20;
    transition: color .2s;
}
.ecran-quit:hover { color: rgba(255,255,255,.6); }

@media (prefers-reduced-motion: reduce) {
    .ecran-slide  { transition: none; }
    .live-dot, .ecran-timer.urgent { animation: none; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/icons.php'; ?>
<div class="ecran-bg" aria-hidden="true"></div>

<!-- Topbar -->
<div class="ecran-top">
    <div class="ecran-brand">
        <div class="ecran-brand-blade" aria-hidden="true"></div>
        <?= e($nomApplication) ?>
    </div>
    <div class="ecran-clock" id="ecran-clock" aria-label="Heure" aria-live="off">--:--:--</div>
</div>

<!-- Contenu slides -->
<div class="ecran-body">

    <!-- ── Slide 0 : En cours ── -->
    <section class="ecran-slide active" data-slide="0" aria-label="Combats en cours">
        <div class="ecran-title">
            <span class="live-dot" aria-hidden="true"></span>
            <?= icon('broadcast') ?>
            En cours
        </div>

        <?php if ($retardActuel > 0): ?>
            <div class="ecran-retard" role="status">
                <?= icon('clock-alert') ?>
                Retard : <strong><?= $retardActuel ?> min<?= $retardActuel > 1 ? 's' : '' ?></strong>
            </div>
        <?php endif; ?>

        <?php if (!$enCours): ?>
            <div class="ecran-empty">
                <div class="ecran-empty-icon">⚔️</div>
                Aucun combat en cours
            </div>
        <?php else: ?>
            <div class="ecran-live-grid">
                <?php foreach ($enCours as $c): ?>
                    <div class="ecran-fight-card" data-combat-id="<?= (int)$c['id'] ?>">
                        <div class="ecran-fight-label">
                            <span class="live-dot" aria-hidden="true"></span>
                            Combat en direct
                        </div>
                        <div class="ecran-versus">
                            <div class="ecran-player">
                                <div class="ecran-player-name"><?= e($c['joueur1_pseudo']) ?></div>
                                <div class="ecran-player-score js-score1">
                                    <?= number_format((float)$c['score_joueur1'], 1) ?>
                                </div>
                            </div>
                            <div class="ecran-vs-sep" aria-hidden="true">VS</div>
                            <div class="ecran-player">
                                <div class="ecran-player-name"><?= e($c['joueur2_pseudo']) ?></div>
                                <div class="ecran-player-score js-score2">
                                    <?= number_format((float)$c['score_joueur2'], 1) ?>
                                </div>
                            </div>
                        </div>
                        <div class="ecran-timer js-timer"
                             data-remaining="<?= tempsRestant($c) ?>"
                             role="timer" aria-label="Temps restant">--:--</div>
                        <span class="visually-hidden js-live-status" aria-live="polite"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Slide 1 : Prochains combats ── -->
    <section class="ecran-slide" data-slide="1" aria-label="Prochains combats">
        <div class="ecran-title">
            <?= icon('history') ?>
            Prochains combats
        </div>

        <?php if (!$aVenir): ?>
            <div class="ecran-empty">
                <div class="ecran-empty-icon">📋</div>
                Aucun combat prévu
            </div>
        <?php else: ?>
            <div style="overflow-y:auto; flex:1;">
                <table class="ecran-table">
                    <caption class="visually-hidden">Prochains combats</caption>
                    <thead>
                        <tr>
                            <th scope="col">Combat</th>
                            <th scope="col">Heure</th>
                            <th scope="col">Arbitres</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($aVenir as $c): ?>
                        <tr>
                            <td class="fight-name"><?= e($c['joueur1_pseudo']) ?> <span style="color:rgba(255,255,255,.3);">vs</span> <?= e($c['joueur2_pseudo']) ?></td>
                            <td class="fight-time"><?= formatDateFr($c['date_prevue']) ?></td>
                            <td class="fight-refs">
                                <?php if (!empty($arbitresParCombat[$c['id']])): ?>
                                    <?= e(implode(', ', array_column($arbitresParCombat[$c['id']], 'username'))) ?>
                                <?php else: ?>
                                    <span style="opacity:.4;">à affecter</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Slide 2 : Arbitres de service ── -->
    <section class="ecran-slide" data-slide="2" aria-label="Arbitres de service">
        <div class="ecran-title">
            <?= icon('referee-badge') ?>
            Arbitres de service
        </div>

        <?php if (!$arbitresService): ?>
            <div class="ecran-empty">
                <div class="ecran-empty-icon">🦺</div>
                Aucun arbitre actif
            </div>
        <?php else: ?>
            <div class="ecran-refs-grid">
                <?php foreach ($arbitresService as $a): ?>
                    <div class="ecran-ref-card <?= $a['combat_actuel_id'] ? 'is-live' : '' ?>">
                        <div class="ecran-ref-icon">
                            <?= $a['combat_actuel_id'] ? '🟢' : '⚪' ?>
                        </div>
                        <div class="ecran-ref-name"><?= e($a['username']) ?></div>
                        <div class="ecran-ref-status">
                            <?php if ($a['combat_actuel_id']): ?>
                                <?= icon('broadcast') ?> <?= e($a['combat_j1']) ?> vs <?= e($a['combat_j2']) ?>
                            <?php else: ?>
                                Disponible
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</div><!-- /.ecran-body -->

<!-- Dots de navigation -->
<div class="ecran-nav" role="tablist" aria-label="Navigation entre les écrans">
    <button class="ecran-dot active" data-dot="0" role="tab" aria-selected="true"  aria-label="Écran 1"></button>
    <button class="ecran-dot"        data-dot="1" role="tab" aria-selected="false" aria-label="Écran 2"></button>
    <button class="ecran-dot"        data-dot="2" role="tab" aria-selected="false" aria-label="Écran 3"></button>
</div>

<a href="<?= BASE_URL ?>index.php" class="ecran-quit">Quitter le mode écran</a>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<script>
(function () {
    'use strict';

    /* ── Horloge ── */
    const clock = document.getElementById('ecran-clock');
    const tick = () => { clock.textContent = new Date().toLocaleTimeString('fr-FR'); };
    tick(); setInterval(tick, 1000);

    /* ── Rotation slides ── */
    const slides   = Array.from(document.querySelectorAll('.ecran-slide'));
    const dots     = Array.from(document.querySelectorAll('.ecran-dot'));
    const rotMs    = <?= $rotationMs ?>;
    let current    = 0;
    let timer      = null;

    function show(i) {
        slides.forEach((s, j) => s.classList.toggle('active', j === i));
        dots.forEach((d, j) => {
            d.classList.toggle('active', j === i);
            d.setAttribute('aria-selected', j === i ? 'true' : 'false');
        });
        current = i;
    }

    function next() {
        const nxt = (current + 1) % slides.length;
        show(nxt);
        if (nxt === 0) {
            // Rechargement après un cycle complet pour données fraîches
            window.setTimeout(() => window.location.reload(), rotMs);
        } else {
            scheduleNext();
        }
    }

    function scheduleNext() {
        clearTimeout(timer);
        if (slides.length > 1) timer = setTimeout(next, rotMs);
    }

    // Clic manuel sur les dots
    dots.forEach((d, i) => d.addEventListener('click', () => { show(i); scheduleNext(); }));

    scheduleNext();
})();
</script>
</body>
</html>
