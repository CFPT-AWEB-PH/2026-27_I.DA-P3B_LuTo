<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin', 'arbitre');

$user = currentUser();
$combatId = (int)($_GET['combat_id'] ?? $_POST['combat_id'] ?? 0);

purgerCombatsExpires($pdo);

$combat = getCombat($pdo, $combatId);
if (!$combat) {
    renderErrorPage(404, 'Combat introuvable', 'Ce combat n\'existe pas ou a été supprimé.');
}

$stmt = $pdo->prepare('SELECT role FROM combat_arbitres WHERE combat_id = ? AND user_id = ?');
$stmt->execute([$combatId, $user['id']]);
$monRole = $stmt->fetch();

if (!$monRole && $user['role'] !== 'admin') {
    renderErrorPage(403, 'Accès refusé', 'Vous n\'êtes pas arbitre sur ce combat.');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'voter') === 'voter') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } elseif (!$monRole) {
        $error = 'Seul un arbitre assigné peut soumettre des points.';
    } else {
        $p1 = (float)($_POST['points_j1'] ?? -1);
        $p2 = (float)($_POST['points_j2'] ?? -1);
        $mancheSoumise = ($_POST['manche'] ?? '') !== '' ? (int)$_POST['manche'] : null;
        $result = soumettrePoints($pdo, $combatId, $user['id'], $p1, $p2, $mancheSoumise);
        if ($result['success']) {
            flash('success', 'Points enregistrés pour la manche en cours.');
            redirect('arbitrage/saisie.php?combat_id=' . $combatId);
        } else {
            $error = $result['error'];
        }
    }
    $combat = getCombat($pdo, $combatId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'signaler_retard') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
    } elseif (!$monRole && $user['role'] !== 'admin') {
        flash('error', 'Seul un arbitre assigné à ce combat peut signaler un retard.');
    } else {
        $minutesRetard = (int)($_POST['retard_minutes'] ?? 0);
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

$pointsMax = getParametreFloat($pdo, 'points_max', 10);
$manche = (int)$combat['manche_actuelle'];
$arbitres = getArbitresDuCombat($pdo, $combatId);
$votes = getVotesManche($pdo, $combatId, $manche);
$votedUserIds = array_column($votes, 'arbitre_user_id');
$totalArbitres = count($arbitres);
$votedCount = count(array_filter($arbitres, fn($a) => in_array($a['user_id'], $votedUserIds)));
$votePct = $totalArbitres > 0 ? round($votedCount / $totalArbitres * 100) : 0;

$monVote = null;
foreach ($votes as $v) {
    if ($v['arbitre_user_id'] == $user['id']) $monVote = $v;
}

$pageTitle = 'Saisie des points';
require __DIR__ . '/../includes/header.php';
?>

<a href="index.php" class="back-nav"><?= icon('chevron-left') ?> Mes combats</a>

<?php /* ── Barre de contexte du combat ── */ ?>
<div class="combat-context-bar">
    <div class="player-names"><?= e($combat['joueur1_pseudo']) ?> <span style="color:var(--text-faint);font-weight:400;">vs</span> <?= e($combat['joueur2_pseudo']) ?></div>
    <span class="manche-label">Manche <?= $manche ?></span>
    <span class="badge badge-<?= e($combat['statut']) ?>"><?= strtoupper(str_replace('_',' ',$combat['statut'])) ?></span>
</div>

<?php /* ── Carte scores + timer ── */ ?>
<div class="card <?= $combat['statut'] === 'en_cours' ? 'card-urgent' : '' ?>" data-combat-id="<?= $combatId ?>" id="combat-detail">
    <div class="combat-vs">
        <div class="player"><?= e($combat['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($combat['score_joueur1'],1) ?></span></div>
        <div class="vs-label" aria-hidden="true">VS</div>
        <div class="player"><?= e($combat['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($combat['score_joueur2'],1) ?></span></div>
    </div>
    <?php if ($combat['statut'] === 'en_cours'): ?>
        <div class="timer js-timer" data-remaining="<?= tempsRestant($combat) ?>" role="timer" aria-label="Temps restant">--:--</div>
        <p class="visually-hidden js-live-status" aria-live="polite"></p>
    <?php endif; ?>

    <?php /* ── Progress votes ── */ ?>
    <div class="vote-progress">
        <div class="vote-progress-header">
            <span><?= icon('check-circle') ?> Votes manche <?= $manche ?></span>
            <strong><?= $votedCount ?> / <?= $totalArbitres ?></strong>
        </div>
        <div class="vote-progress-bar" role="progressbar" aria-valuenow="<?= $votePct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $votedCount ?> sur <?= $totalArbitres ?> arbitres ont voté">
            <div class="vote-progress-fill <?= $votePct >= 100 ? 'complete' : '' ?>" style="width:<?= $votePct ?>%"></div>
        </div>
    </div>

    <div class="arbitre-list" aria-label="État des votes par arbitre">
        <?php foreach ($arbitres as $a): ?>
            <?php $aVote = in_array($a['user_id'], $votedUserIds); ?>
            <span class="arbitre-chip <?= $aVote ? 'voted' : '' ?>">
                <?= icon($a['role'] === 'central' ? 'target' : 'corner') ?>
                <?= e($a['username']) ?>
                <?= $aVote ? icon('check-circle') : '<span style="color:var(--text-faint);font-size:.85em;">en attente</span>' ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error" role="alert"><?= icon('alert') ?> <?= e($error) ?></div>
<?php endif; ?>

<?php /* ── Formulaire de vote ── */ ?>
<?php if ($combat['statut'] !== 'en_cours'): ?>
    <div class="empty-state">
        <?= icon('check-circle') ?>
        <p style="margin-top:var(--space-3);">Ce combat n'est pas (ou plus) en cours.</p>
        <p style="font-size:var(--fs-sm);margin-top:var(--space-1);">Aucune saisie de points possible dans cet état.</p>
    </div>
<?php elseif (!$monRole): ?>
    <div class="empty-state">
        <?= icon('eye') ?>
        <p style="margin-top:var(--space-3);">Mode lecture seule (administrateur).</p>
    </div>
<?php else: ?>
    <div class="card" style="max-width:460px;">
        <?php if ($monVote): ?>
            <div class="flash flash-info" style="margin-bottom:var(--space-4);">
                <?= icon('check-circle') ?> Vote enregistré : <strong><?= $monVote['joueur1_points'] ?></strong> – <strong><?= $monVote['joueur2_points'] ?></strong>. Modifiable tant que la manche n'est pas close.
            </div>
        <?php endif; ?>

        <h3 style="margin-bottom:var(--space-4);"><?= $monVote ? 'Modifier mon vote' : 'Soumettre mes points' ?> — Manche <?= $manche ?></h3>

        <form method="post" action="saisie.php" novalidate id="vote-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="combat_id" value="<?= $combatId ?>">
            <input type="hidden" name="manche" value="<?= $manche ?>">

            <div class="field">
                <label for="points_j1"><?= e($combat['joueur1_pseudo']) ?> — Points (0 à <?= rtrim(rtrim(number_format($pointsMax, 2, '.', ''), '0'), '.') ?>)</label>
                <div class="stepper">
                    <button type="button" class="stepper-btn js-step-down" data-target="points_j1" aria-label="Diminuer les points de <?= e($combat['joueur1_pseudo']) ?>">&minus;</button>
                    <input type="number" id="points_j1" name="points_j1" min="0" max="<?= e((string)$pointsMax) ?>" step="0.5" required inputmode="decimal" value="<?= $monVote['joueur1_points'] ?? '' ?>" placeholder="0">
                    <button type="button" class="stepper-btn js-step-up" data-target="points_j1" aria-label="Augmenter les points de <?= e($combat['joueur1_pseudo']) ?>">+</button>
                </div>
            </div>

            <div class="field">
                <label for="points_j2"><?= e($combat['joueur2_pseudo']) ?> — Points (0 à <?= rtrim(rtrim(number_format($pointsMax, 2, '.', ''), '0'), '.') ?>)</label>
                <div class="stepper">
                    <button type="button" class="stepper-btn js-step-down" data-target="points_j2" aria-label="Diminuer les points de <?= e($combat['joueur2_pseudo']) ?>">&minus;</button>
                    <input type="number" id="points_j2" name="points_j2" min="0" max="<?= e((string)$pointsMax) ?>" step="0.5" required inputmode="decimal" value="<?= $monVote['joueur2_points'] ?? '' ?>" placeholder="0">
                    <button type="button" class="stepper-btn js-step-up" data-target="points_j2" aria-label="Augmenter les points de <?= e($combat['joueur2_pseudo']) ?>">+</button>
                </div>
            </div>

            <?php /* Bouton visible sur desktop, sticky sur mobile */ ?>
            <button type="submit" class="btn-vote-submit btn-success" style="display:none;" id="vote-submit-desktop">
                <?= icon('check-circle') ?> <?= $monVote ? 'Modifier mon vote' : 'Valider mon vote' ?>
            </button>
        </form>

        <p class="kbd-hint"><?= icon('keyboard') ?> <kbd>Ctrl</kbd> + <kbd>Enter</kbd> pour valider rapidement</p>
    </div>

    <?php /* Sticky bar mobile */ ?>
    <div class="sticky-action-bar" aria-hidden="true">
        <button type="submit" form="vote-form" class="btn btn-success btn-vote-submit">
            <?= icon('check-circle') ?> <?= $monVote ? 'Modifier mon vote' : 'Valider mon vote' ?>
        </button>
    </div>
<?php endif; ?>

<?php /* ── Signaler un retard ── */ ?>
<?php if ($combat['statut'] === 'termine' && ($monRole || $user['role'] === 'admin')): ?>
    <div class="card" style="max-width:420px;margin-top:var(--space-5);">
        <h3><?= icon('clock') ?> Signaler un retard</h3>
        <p style="margin:var(--space-2) 0 var(--space-4);"><small class="hint">Décale automatiquement l'heure de tous les combats à venir et informe les spectateurs.</small></p>
        <form method="post" action="saisie.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="combat_id" value="<?= $combatId ?>">
            <input type="hidden" name="form_action" value="signaler_retard">
            <div class="field">
                <label for="retard_minutes">Minutes (négatif pour rattraper du retard)</label>
                <input type="number" id="retard_minutes" name="retard_minutes" step="1" required placeholder="ex. 10 ou -5">
            </div>
            <button type="submit" class="btn-secondary"><?= icon('clock') ?> Appliquer le retard</button>
        </form>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<script>
    // Montre le bouton desktop, le sticky bar s'occupe du mobile
    const desktopBtn = document.getElementById('vote-submit-desktop');
    if (desktopBtn) desktopBtn.style.display = '';

    if (<?= $combat['statut'] === 'en_cours' ? 'true' : 'false' ?>) {
        watchMancheChange(<?= (int)$combatId ?>, <?= (int)$manche ?>);
    }
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
