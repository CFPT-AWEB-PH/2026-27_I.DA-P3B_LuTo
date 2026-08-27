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

// Vérifie que l'utilisateur est bien arbitre sur ce combat (sauf admin qui peut consulter)
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
        // La manche transmise par le formulaire est celle que l'arbitre avait
        // sous les yeux en votant : soumettrePoints() la compare à la manche
        // réellement en cours pour éviter qu'un vote tardif soit rattaché à
        // la mauvaise manche si les autres arbitres ont déjà clos la précédente.
        $mancheSoumise = ($_POST['manche'] ?? '') !== '' ? (int)$_POST['manche'] : null;
        $result = soumettrePoints($pdo, $combatId, $user['id'], $p1, $p2, $mancheSoumise);
        if ($result['success']) {
            flash('success', 'Points enregistrés pour la manche en cours.');
            redirect('arbitrage/saisie.php?combat_id=' . $combatId);
        } else {
            $error = $result['error'];
        }
    }
    $combat = getCombat($pdo, $combatId); // recharge après soumission
}

// ---- Signaler un retard : accessible aux arbitres assignés (et à l'admin),
// typiquement une fois le combat terminé, pour informer immédiatement les
// spectateurs et replanifier les combats suivants sans repasser par l'admin.
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

$monVote = null;
foreach ($votes as $v) {
    if ($v['arbitre_user_id'] == $user['id']) $monVote = $v;
}

$pageTitle = 'Saisie des points';
require __DIR__ . '/../includes/header.php';
?>

<h1><?= icon('target') ?> Saisie des points — Manche <?= $manche ?></h1>
<span class="badge badge-<?= e($combat['statut']) ?>"><?= strtoupper(str_replace('_',' ',$combat['statut'])) ?></span>

<div class="card" data-combat-id="<?= $combatId ?>" id="combat-detail">
    <div class="combat-vs">
        <div class="player"><?= e($combat['joueur1_pseudo']) ?><br><span class="js-score1 score-big"><?= number_format($combat['score_joueur1'],1) ?></span></div>
        <div class="vs-label" aria-hidden="true">VS</div>
        <div class="player"><?= e($combat['joueur2_pseudo']) ?><br><span class="js-score2 score-big"><?= number_format($combat['score_joueur2'],1) ?></span></div>
    </div>
    <?php if ($combat['statut'] === 'en_cours'): ?>
        <div class="timer js-timer" data-remaining="<?= tempsRestant($combat) ?>" role="timer" aria-label="Temps restant">--:--</div>
        <p class="visually-hidden js-live-status" aria-live="polite"></p>
    <?php endif; ?>

    <div class="section-title" style="margin-top: var(--space-4);"><h3 id="heading-vote">Arbitres — vote manche <?= $manche ?></h3></div>
    <div class="arbitre-list" aria-labelledby="heading-vote">
        <?php foreach ($arbitres as $a): ?>
            <?php $aVote = in_array($a['user_id'], $votedUserIds); ?>
            <span class="arbitre-chip <?= $aVote ? 'voted' : '' ?>">
                <?= icon($a['role'] === 'central' ? 'target' : 'corner') ?> <?= e($a['username']) ?>
                <?= $aVote ? icon('check-circle') . ' a voté' : '(en attente)' ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash flash-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($combat['statut'] === 'termine' && ($monRole || $user['role'] === 'admin')): ?>
    <div class="card" style="max-width: 420px;">
        <h3><?= icon('clock') ?> Signaler un retard</h3>
        <p><small class="hint">Décale automatiquement l'heure prévue de tous les combats à venir et informe les spectateurs, sans passer par l'admin.</small></p>
        <form method="post" action="saisie.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="combat_id" value="<?= $combatId ?>">
            <input type="hidden" name="form_action" value="signaler_retard">
            <div class="field">
                <label for="retard_minutes">Minutes (négatif pour rattraper du temps)</label>
                <input type="number" id="retard_minutes" name="retard_minutes" step="1" required placeholder="ex. 10 ou -5">
            </div>
            <button type="submit">Appliquer le retard</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($combat['statut'] !== 'en_cours'): ?>
    <div class="empty-state">Ce combat n'est pas (ou plus) en cours. Aucune saisie possible.</div>
<?php elseif (!$monRole): ?>
    <div class="empty-state">Vous consultez ce combat en tant qu'administrateur (lecture seule).</div>
<?php else: ?>
    <div class="card" style="max-width: 420px;">
        <h3>Votre vote pour la manche <?= $manche ?></h3>
        <?php if ($monVote): ?>
            <p>Vous avez déjà voté : <strong><?= e($combat['joueur1_pseudo']) ?> = <?= $monVote['joueur1_points'] ?></strong>,
               <strong><?= e($combat['joueur2_pseudo']) ?> = <?= $monVote['joueur2_points'] ?></strong></p>
            <p><small class="hint">Vous pouvez modifier votre vote tant que la manche n'est pas clôturée (tant que tous les arbitres n'ont pas voté).</small></p>
        <?php endif; ?>
        <form method="post" action="saisie.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="combat_id" value="<?= $combatId ?>">
            <input type="hidden" name="manche" value="<?= $manche ?>">

            <div class="field">
                <label for="points_j1" class="side-blue-label">Points pour <?= e($combat['joueur1_pseudo']) ?> (0 à <?= e(rtrim(rtrim(number_format($pointsMax, 2, '.', ''), '0'), '.')) ?>)</label>
                <div class="stepper stepper-blue">
                    <button type="button" class="stepper-btn js-step-down" data-target="points_j1" aria-label="Diminuer">&minus;</button>
                    <input type="number" id="points_j1" name="points_j1" min="0" max="<?= e((string)$pointsMax) ?>" step="0.5" required inputmode="decimal" value="<?= $monVote['joueur1_points'] ?? '' ?>">
                    <button type="button" class="stepper-btn js-step-up" data-target="points_j1" aria-label="Augmenter">+</button>
                </div>
            </div>

            <div class="field">
                <label for="points_j2" class="side-red-label">Points pour <?= e($combat['joueur2_pseudo']) ?> (0 à <?= e(rtrim(rtrim(number_format($pointsMax, 2, '.', ''), '0'), '.')) ?>)</label>
                <div class="stepper stepper-red">
                    <button type="button" class="stepper-btn js-step-down" data-target="points_j2" aria-label="Diminuer">&minus;</button>
                    <input type="number" id="points_j2" name="points_j2" min="0" max="<?= e((string)$pointsMax) ?>" step="0.5" required inputmode="decimal" value="<?= $monVote['joueur2_points'] ?? '' ?>">
                    <button type="button" class="stepper-btn js-step-up" data-target="points_j2" aria-label="Augmenter">+</button>
                </div>
            </div>

            <button type="submit" class="btn-vote-submit"><?= icon('check-circle') ?> <?= $monVote ? 'Modifier mon vote' : 'Valider mon vote' ?></button>
        </form>
    </div>
<?php endif; ?>

<p><a href="index.php"><?= icon('chevron-left') ?> Retour à mes combats</a></p>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<script>
    if (<?= $combat['statut'] === 'en_cours' ? 'true' : 'false' ?>) {
        watchMancheChange(<?= (int)$combatId ?>, <?= (int)$manche ?>);
    }
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
