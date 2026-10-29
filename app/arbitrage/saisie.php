<?php
require_once __DIR__ . '/../config/config.php';
requirePermission('arbitrage.acces', 'arbitrage.tous_combats');

$user = currentUser();
$combatId = idPositif($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['combat_id'] ?? '') : ($_GET['combat_id'] ?? ''));

purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $combatId);
if (!$combat) {
    renderErrorPage(404, 'Combat introuvable', 'Ce combat n\'existe pas ou a été supprimé.');
}

$stmt = $pdo->prepare('SELECT role FROM combat_arbitres WHERE combat_id = ? AND user_id = ?');
$stmt->execute([$combatId, $user['id']]);
$monRole = $stmt->fetch();

if (!$monRole && !can('arbitrage.tous_combats')) {
    renderErrorPage(403, 'Accès refusé', 'Vous n\'êtes pas arbitre sur ce combat.');
}

$error = null;

// Les points se donnent désormais via api/points.php (boutons +1 / +2 / +3).
// L'ancien formulaire de vote par manche est retiré : il modifiait aussi le score.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'signaler_retard') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
    } elseif (!can('arbitrage.retard', 'combats.retard') || (!$monRole && !can('arbitrage.tous_combats'))) {
        flash('error', 'Votre rôle n\'a pas la permission de signaler un retard sur ce combat.');
    } else {
        $minutesRetard = max(-600, min(600, (int)($_POST['retard_minutes'] ?? 0)));
        if ($minutesRetard === 0) {
            flash('error', 'Indique un nombre de minutes différent de zéro.');
        } else {
            $nbAffectes = appliquerRetard($pdo, $minutesRetard);
            $sens = $minutesRetard > 0 ? 'ajouté' : 'retiré';
            flash('success', abs($minutesRetard) . " minute(s) de retard {$sens}. {$nbAffectes} combat(s) à venir replanifié(s).");
        }
    }
    redirect('arbitrage/saisie.php?combat_id=' . $combatId);
}

// Pause : n'importe quel arbitre du combat (ou l'organisation) peut arrêter le
// chrono en cas de souci (sabre, blessure, matériel…), puis reprendre.
$peutPause = ($monRole && can('arbitrage.voter')) || can('combats.demarrer', 'combats.terminer');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_action'] ?? '', ['pause', 'reprendre'], true)) {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
    } elseif (!$peutPause) {
        flash('error', 'Votre rôle ne permet pas de mettre ce combat en pause.');
    } else {
        $r = $_POST['form_action'] === 'pause' ? mettreEnPauseCombat($pdo, $combatId) : reprendreCombat($pdo, $combatId);
        flash($r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirect('arbitrage/saisie.php?combat_id=' . $combatId);
}

$j1 = $combat['joueur1_pseudo'];
$j2 = $combat['joueur2_pseudo'];
$roles = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];
$etat = etatScore($pdo, $combat);
$mes = $etat['par_arbitre'][(int)$user['id']] ?? null;
$enPause = $combat['statut'] === 'en_cours' && !empty($combat['en_pause_depuis']);
$peutNoter = $combat['statut'] === 'en_cours' && !$enPause && $monRole && can('arbitrage.voter');

$pageTitle = 'Arbitrage · ' . $j1 . ' contre ' . $j2;
require __DIR__ . '/../includes/header.php';
?>

<a href="index.php" class="back-nav"><?= icon('chevron-left') ?> Mes combats</a>

<header class="combat-head combat-head-compact">
    <p class="eyebrow"><?= $monRole ? 'Vous : arbitre ' . e(mb_strtolower($roles[$monRole['role']] ?? $monRole['role'])) : 'Supervision · lecture seule' ?><?= badgeStatut($combat['statut']) ?></p>
    <h1 class="player-names"><?= e($j1) ?><span class="vs">contre</span><?= e($j2) ?></h1>
</header>

<?php if ($error): ?>
    <div class="flash flash-error" role="alert"><?= icon('alert-triangle') ?><span class="flash-body"><?= e($error) ?></span></div>
<?php endif; ?>

<section class="card pad-card" id="combat-detail" data-combat-id="<?= $combatId ?>" data-statut="<?= e($combat['statut']) ?>" aria-label="Pupitre de saisie des points">
    <div class="pad-top">
        <?php if ($combat['statut'] === 'en_cours'): ?>
            <div class="pad-timer">
                <div class="timer js-timer" <?= attrsChrono($combat) ?> role="timer" aria-label="Temps restant"><?= gmdate('i:s', tempsRestant($combat)) ?></div>
                <span class="hint"><?= $enPause ? 'en pause' : 'restant' ?></span>
            </div>
            <span class="pad-state" id="pad-state" aria-live="polite"></span>
            <?php if ($peutPause): ?>
                <form method="post" action="saisie.php" class="pad-pause-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="combat_id" value="<?= $combatId ?>">
                    <input type="hidden" name="form_action" value="<?= $enPause ? 'reprendre' : 'pause' ?>">
                    <?php if ($enPause): ?>
                        <button type="submit" class="btn"><?= icon('play') ?>Reprendre le combat</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-secondary" aria-label="Mettre le combat en pause"><?= icon('pause') ?>Pause</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php elseif ($combat['statut'] === 'a_venir'): ?>
            <span class="pad-notice"><?= icon('clock') ?>
                <?php if ($combat['date_prevue']): ?>
                    <span>Début à <strong><?= date('H:i', strtotime($combat['date_prevue'])) ?></strong>, <span data-countdown-to="<?= dateVersMs($combat['date_prevue']) ?>" data-late-text="imminent"></span>. Les boutons apparaîtront au coup d'envoi.</span>
                <?php else: ?>
                    <span>Horaire non fixé : l'organisation lancera le combat. Les boutons apparaîtront au coup d'envoi.</span>
                <?php endif; ?>
            </span>
        <?php else: ?>
            <span class="pad-notice"><?= icon('flag') ?><strong><?= $combat['vainqueur_id'] ? 'Victoire de ' . e($combat['vainqueur_pseudo']) . ' · ' . (int)$combat['points_victoire'] . ' PV' : 'Match nul' ?></strong></span>
        <?php endif; ?>
    </div>
    <p class="visually-hidden js-live-status" aria-live="polite"></p>
    <?php if ($enPause): ?>
        <div class="flash flash-warning pad-paused" role="status"><?= icon('pause') ?><span class="flash-body"><strong>Combat en pause</strong> depuis <?= date('H:i:s', strtotime($combat['en_pause_depuis'])) ?> : le chrono est arrêté et les points sont bloqués jusqu'à la reprise.</span></div>
    <?php endif; ?>

    <div class="pad <?= $peutNoter ? '' : 'pad-off' ?>" id="pad"
         data-combat-id="<?= $combatId ?>" data-csrf="<?= e(csrfToken()) ?>" data-actif="<?= $peutNoter ? '1' : '0' ?>" data-pause="<?= $enPause ? '1' : '0' ?>">
        <?php foreach ([1 => ['aka', 'Aka', $j1], 2 => ['ao', 'Ao', $j2]] as $n => [$cls, $lib, $nom]): ?>
        <div class="pad-side pad-<?= $cls ?>">
            <div class="pad-head">
                <span class="side-label <?= $cls ?>"><?= $lib ?></span>
                <span class="pad-name"><?= e($nom) ?></span>
            </div>
            <?php if ($monRole): ?>
                <div class="pad-score js-mine<?= $n ?>" aria-label="Vos points pour <?= e($nom) ?>"><?= fmtScore($mes['j' . $n] ?? 0) ?></div>
                <div class="pad-mine">Vos points <span class="pad-official" title="Score officiel : le total le plus bas des 3 arbitres">Officiel <strong class="js-score<?= $n ?>"><?= fmtScore($etat['j' . $n]) ?></strong></span></div>
            <?php else: ?>
                <div class="pad-score js-score<?= $n ?>" title="Score officiel : le total le plus bas des 3 arbitres"><?= fmtScore($etat['j' . $n]) ?></div>
                <div class="pad-mine">Score officiel</div>
            <?php endif; ?>
            <div class="pad-btns">
                <?php foreach (VALEURS_POINTS as $v): ?>
                    <button type="button" class="pad-btn" data-joueur="<?= $n ?>" data-valeur="<?= $v ?>" aria-label="<?= $v ?> point<?= $v > 1 ? 's' : '' ?> pour <?= e($nom) ?>">+<?= $v ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?= barreAvantage($etat, $j1, $j2, 'lead-lg', $combat['statut'] === 'termine') ?>

    <?php if ($peutNoter): ?>
    <div class="pad-actions">
        <button type="button" class="btn btn-secondary" id="pad-undo"><?= icon('history') ?> Annuler mon dernier point</button>
        <ul class="my-log grow" id="my-log" aria-label="Mes derniers points"></ul>
    </div>
    <p class="kbd-hint">Raccourcis : <kbd>1</kbd><kbd>2</kbd><kbd>3</kbd> Aka · <kbd>7</kbd><kbd>8</kbd><kbd>9</kbd> Ao · <kbd>⌫</kbd> annuler</p>
    <?php elseif ($combat['statut'] === 'en_cours' && !$monRole): ?>
    <p class="pad-notice"><?= icon('eye') ?>Lecture seule : vous n'êtes pas l'un des 3 arbitres de ce combat.</p>
    <?php endif; ?>
</section>

<div class="grid grid-2 mt-4" style="align-items:start;">
    <section class="card">
        <h3>Points par arbitre</h3>
        <p class="hint">Le score officiel retient le total le plus bas des trois arbitres (en gras). C'est pourquoi il peut ne pas bouger quand vous donnez un point.</p>
        <div class="table-wrap">
            <table class="ref-table" id="ref-table">
                <thead><tr><th scope="col">Arbitre</th><th scope="col" class="num side-1">Aka</th><th scope="col" class="num side-2">Ao</th></tr></thead>
                <tbody>
                <?php foreach ($etat['par_arbitre'] as $a): ?>
                    <tr>
                        <td><strong><?= e($a['username']) ?></strong> <span class="hint"><?= e($roles[$a['role']] ?? $a['role']) ?></span></td>
                        <td class="num <?= $a['j1'] == $etat['j1'] ? 'min side-1' : '' ?>"><?= fmtScore($a['j1']) ?></td>
                        <td class="num <?= $a['j2'] == $etat['j2'] ? 'min side-2' : '' ?>"><?= fmtScore($a['j2']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$etat['par_arbitre']): ?><tr><td colspan="3" class="hint">Aucun arbitre affecté.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (($monRole && can('arbitrage.retard')) || can('combats.retard')): ?>
        <details class="form-section mt-4">
            <summary><span><?= icon('clock-alert') ?> Signaler un retard</span><span class="summary-arrow" aria-hidden="true"><?= icon('chevron-down') ?></span></summary>
            <div class="form-section-body">
                <p class="hint" style="margin-bottom:var(--space-3);">Décale tous les combats à venir et prévient les spectateurs par un bandeau.</p>
                <form method="post" action="saisie.php" novalidate class="wide inline-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="combat_id" value="<?= $combatId ?>">
                    <input type="hidden" name="form_action" value="signaler_retard">
                    <div class="field">
                        <label for="retard_minutes">Minutes</label>
                        <div class="input-suffix">
                            <input type="number" id="retard_minutes" name="retard_minutes" step="1" min="-600" max="600" required placeholder="10" inputmode="numeric">
                            <span class="suffix">min</span>
                        </div>
                        <small class="hint">Nombre négatif pour rattraper du retard.</small>
                    </div>
                    <button type="submit" class="btn btn-secondary">Décaler les combats</button>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </section>

    <?php if ($combat['statut'] !== 'a_venir'): ?>
    <section class="card touch-panel" data-touches
             data-combat-id="<?= $combatId ?>" data-statut="<?= e($combat['statut']) ?>"
             data-j1="<?= e($j1) ?>" data-j2="<?= e($j2) ?>" data-duree="<?= (int)$combat['duree_secondes'] ?>">
        <div class="card-head"><h3>Touches</h3></div>
        <span class="skeleton skeleton-block" aria-hidden="true"></span>
    </section>
    <?php else: ?>
    <section class="card">
        <h3>Touches</h3>
        <?= etatVide('Pas encore de touche', 'Les touches des sabres s\'afficheront ici en direct dès le début du combat.', '', 'saber', 'compact') ?>
    </section>
    <?php endif; ?>
</div>

<?= scriptJs('live.js') ?>
<?= scriptJs('touches.js') ?>
<?= scriptJs('scoring.js') ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
