<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

purgerCombatsExpires($pdo);

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$joueurs = $pdo->query('SELECT * FROM joueurs ORDER BY pseudo ASC')->fetchAll();
$arbitresDisponibles = $pdo->query("SELECT * FROM users WHERE role IN ('arbitre','admin') AND actif = 1 ORDER BY username ASC")->fetchAll();

function saveAssignations(PDO $pdo, int $combatId, array $post): void
{
    $pdo->prepare('DELETE FROM combat_arbitres WHERE combat_id = ?')->execute([$combatId]);
    $roles = ['central' => $post['arbitre_central'] ?? '', 'coin1' => $post['arbitre_coin1'] ?? '', 'coin2' => $post['arbitre_coin2'] ?? ''];
    $stmt = $pdo->prepare('INSERT INTO combat_arbitres (combat_id, user_id, role) VALUES (?, ?, ?)');
    foreach ($roles as $role => $userId) {
        if ($userId) {
            $stmt->execute([$combatId, (int)$userId, $role]);
        }
    }
}

/**
 * Un même arbitre ne peut pas occuper deux rôles sur le même combat
 * (contrainte imposée aussi en base par uniq_combat_user). On valide ici
 * en amont pour renvoyer un message clair plutôt qu'une erreur SQL brute.
 */
function arbitresChoisisEnDouble(array $post): bool
{
    $choisis = array_filter([
        $post['arbitre_central'] ?? '',
        $post['arbitre_coin1'] ?? '',
        $post['arbitre_coin2'] ?? '',
    ], static fn($v) => $v !== '' && $v !== null);

    return count($choisis) !== count(array_unique($choisis));
}

// ---- GÉNÉRATION AUTOMATIQUE DES COMBATS ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'generer_auto') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/combats.php?action=generer');
    }

    $joueurIdsSelectionnes = array_map('intval', $_POST['joueurs_participants'] ?? []);
    $arbitreIdsSelectionnes = array_map('intval', $_POST['arbitres_presents'] ?? []);
    $viderArbre = !empty($_POST['vider_arbre_existant']);

    if (count($joueurIdsSelectionnes) < 2) {
        flash('error', 'Sélectionne au moins 2 joueurs participants.');
        redirect('admin/combats.php?action=generer');
    }

    $resume = genererCombatsAutomatiques($pdo, $joueurIdsSelectionnes, $arbitreIdsSelectionnes, $viderArbre);

    if ($resume['erreur']) {
        flash('error', $resume['erreur']);
        redirect('admin/combats.php?action=generer');
    }

    $message = "{$resume['combats_crees']} combat(s) généré(s) et placés au 1er tour de l'arbre.";
    if ($resume['non_apparies']) {
        $message .= ' Non appariés (aucun adversaire compatible selon les règles configurées) : ' . implode(', ', $resume['non_apparies']) . '.';
    }
    foreach ($resume['avertissements'] as $avert) {
        $message .= ' ' . $avert;
    }
    flash($resume['non_apparies'] || $resume['avertissements'] ? 'info' : 'success', $message);
    redirect('admin/combats.php');
}

// ---- SIGNALER UN RETARD ----
// Accessible aux admins ET aux arbitres (voir aussi arbitrage/saisie.php),
// donc pas de requireRole('admin') ici : la vérification de rôle se fait
// séparément à l'inclusion de ce fichier (déjà limité à l'admin plus haut),
// mais la fonction appliquerRetard() elle-même est partagée entre les deux
// points d'entrée pour ne pas dupliquer la logique.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'signaler_retard') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/combats.php');
    }
    $minutes = (int)($_POST['retard_minutes'] ?? 0);
    if ($minutes === 0) {
        flash('error', 'Indique un nombre de minutes différent de zéro.');
        redirect('admin/combats.php');
    }
    $nbAffectes = appliquerRetard($pdo, $minutes);
    $sens = $minutes > 0 ? 'ajouté' : 'retiré';
    flash('success', abs($minutes) . " minute(s) de retard {$sens}. {$nbAffectes} combat(s) à venir replanifié(s). Le retard est visible publiquement.");
    redirect('admin/combats.php');
}

// ---- CREATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create') {
    if (!checkCsrf($_POST['csrf'] ?? null)) { flash('error','Session expirée.'); redirect('admin/combats.php'); }

    $j1 = (int)($_POST['joueur1_id'] ?? 0);
    $j2 = (int)($_POST['joueur2_id'] ?? 0);
    $duree = max(30, (int)($_POST['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180)));
    $datePrevue = $_POST['date_prevue'] ?: null;
    $tour = ($_POST['tour'] ?? '') !== '' ? (int)$_POST['tour'] : null;
    $position = ($_POST['position'] ?? '') !== '' ? (int)$_POST['position'] : null;

    if (!$j1 || !$j2 || $j1 === $j2) {
        flash('error', 'Merci de sélectionner deux joueurs différents.');
        stashOldInput($_POST);
        redirect('admin/combats.php?action=new');
    }

    if (arbitresChoisisEnDouble($_POST)) {
        flash('error', 'Un même arbitre ne peut pas occuper deux rôles sur le même combat. Merci de choisir 3 arbitres distincts.');
        stashOldInput($_POST);
        redirect('admin/combats.php?action=new');
    }

    $stmt = $pdo->prepare('INSERT INTO combats (joueur1_id, joueur2_id, duree_secondes, date_prevue, tour, position, statut) VALUES (?, ?, ?, ?, ?, ?, "a_venir")');
    $stmt->execute([$j1, $j2, $duree, $datePrevue, $tour, $position]);
    $newId = (int)$pdo->lastInsertId();

    saveAssignations($pdo, $newId, $_POST);

    flash('success', 'Combat créé.');
    redirect('admin/combats.php');
}

// ---- UPDATE (uniquement si à venir) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update') {
    if (!checkCsrf($_POST['csrf'] ?? null)) { flash('error','Session expirée.'); redirect('admin/combats.php'); }
    $editId = (int)$_POST['id'];
    $j1 = (int)($_POST['joueur1_id'] ?? 0);
    $j2 = (int)($_POST['joueur2_id'] ?? 0);
    $duree = max(30, (int)($_POST['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180)));
    $datePrevue = $_POST['date_prevue'] ?: null;
    $tour = ($_POST['tour'] ?? '') !== '' ? (int)$_POST['tour'] : null;
    $position = ($_POST['position'] ?? '') !== '' ? (int)$_POST['position'] : null;

    if (!$j1 || !$j2 || $j1 === $j2) {
        flash('error', 'Merci de sélectionner deux joueurs différents.');
        stashOldInput($_POST);
        redirect('admin/combats.php?action=edit&id=' . $editId);
    }

    if (arbitresChoisisEnDouble($_POST)) {
        flash('error', 'Un même arbitre ne peut pas occuper deux rôles sur le même combat. Merci de choisir 3 arbitres distincts.');
        stashOldInput($_POST);
        redirect('admin/combats.php?action=edit&id=' . $editId);
    }

    $pdo->prepare('UPDATE combats SET joueur1_id=?, joueur2_id=?, duree_secondes=?, date_prevue=?, tour=?, position=? WHERE id=? AND statut = "a_venir"')
        ->execute([$j1, $j2, $duree, $datePrevue, $tour, $position, $editId]);

    saveAssignations($pdo, $editId, $_POST);

    flash('success', 'Combat mis à jour.');
    redirect('admin/combats.php');
}

// ---- ACTIONS : démarrer / terminer / supprimer ----
if ($action === 'start' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        $nbArb = (int)$pdo->query('SELECT COUNT(*) c FROM combat_arbitres WHERE combat_id=' . (int)$id)->fetch()['c'];
        if ($nbArb < 3) {
            flash('error', 'Il faut affecter les 3 arbitres (central + 2 coins) avant de démarrer le combat.');
        } else {
            $pdo->prepare('UPDATE combats SET statut = "en_cours", temps_debut = NOW(), manche_actuelle = 1 WHERE id = ? AND statut = "a_venir"')->execute([$id]);
            flash('success', 'Combat démarré ! Chrono lancé.');
        }
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/combats.php');
}

if ($action === 'finish' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        terminerCombat($pdo, $id);
        flash('success', 'Combat terminé manuellement.');
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/combats.php');
}

if ($action === 'delete' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        $pdo->prepare('DELETE FROM combats WHERE id = ?')->execute([$id]);
        flash('success', 'Combat supprimé.');
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/combats.php');
}

$editCombat = null;
$editArbitres = [];
if ($action === 'edit' && $id) {
    $editCombat = getCombat($pdo, $id);
    foreach (getArbitresDuCombat($pdo, $id) as $a) {
        $editArbitres[$a['role']] = $a['user_id'];
    }
}

$old = oldInput();
if ($old && in_array($action, ['new', 'edit'], true)) {
    $editCombat = array_merge($editCombat ?? [], $old);
    $editArbitres = [
        'central' => $old['arbitre_central'] ?? ($editArbitres['central'] ?? null),
        'coin1'   => $old['arbitre_coin1'] ?? ($editArbitres['coin1'] ?? null),
        'coin2'   => $old['arbitre_coin2'] ?? ($editArbitres['coin2'] ?? null),
    ];
}

$combats = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     ORDER BY FIELD(c.statut,'en_cours','a_venir','termine','annule'), c.date_prevue ASC"
)->fetchAll();

$retardActuel = getParametreInt($pdo, 'retard_minutes', 0);

$pageTitle = 'Gestion des combats';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="section-title" style="margin-bottom:var(--space-5);">
    <h1>Combats</h1>
    <div class="btn-row" style="margin:0;">
        <a href="combats.php?action=new" class="btn"><?= icon('plus') ?> Nouveau combat</a>
        <a href="combats.php?action=generer" class="btn btn-secondary"><?= icon('shuffle') ?> Génération auto</a>
    </div>
</div>

<?php /* ── Retard (widget inline, pas une modal) ── */ ?>
<div class="card" style="max-width:480px;margin-bottom:var(--space-5);">
    <h3><?= icon('clock') ?> Retard en cours : <strong><?= $retardActuel ?> min</strong></h3>
    <form method="post" action="combats.php">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="signaler_retard">
        <div style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
            <div class="field" style="flex:1;min-width:140px;margin-bottom:0;">
                <label for="retard_minutes">Ajouter / retirer (min)</label>
                <input type="number" id="retard_minutes" name="retard_minutes" step="1" required placeholder="ex. 10 ou -5">
            </div>
            <button type="submit" class="btn btn-secondary"><?= icon('clock') ?> Appliquer</button>
        </div>
        <small class="hint" style="margin-top:var(--space-2);display:block;">Décale l'heure de tous les combats à venir et affiche un bandeau site-wide.</small>
    </form>
</div>

<?php /* ── Modal : nouveau / éditer combat ── */ ?>
<?php if (in_array($action, ['new', 'edit'])): ?>
<dialog id="form-modal" class="modal modal-form" style="max-width:min(640px,96vw);" aria-labelledby="modal-combat-title">
    <form method="post" action="combats.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
        <?php if ($action === 'edit' && !empty($editCombat['id'])): ?>
            <input type="hidden" name="id" value="<?= $editCombat['id'] ?>">
        <?php endif; ?>
        <div class="modal-header">
            <h3 id="modal-combat-title"><?= icon($action === 'new' ? 'plus' : 'edit') ?> <?= $action === 'new' ? 'Nouveau combat' : 'Modifier le combat' ?></h3>
            <a href="combats.php" class="modal-close-btn" aria-label="Fermer">&#x2715;</a>
        </div>
        <div class="modal-body">
            <div class="grid grid-2">
                <div class="field">
                    <label for="joueur1_id">Joueur 1 *</label>
                    <select id="joueur1_id" name="joueur1_id" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($joueurs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= (($editCombat['joueur1_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="joueur2_id">Joueur 2 *</label>
                    <select id="joueur2_id" name="joueur2_id" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($joueurs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= (($editCombat['joueur2_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="duree_secondes">Durée (secondes) *</label>
                    <input type="number" id="duree_secondes" name="duree_secondes" min="30" step="10" required value="<?= $editCombat['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180) ?>">
                </div>
                <div class="field">
                    <label for="date_prevue">Date / heure prévue</label>
                    <input type="datetime-local" id="date_prevue" name="date_prevue" value="<?= $editCombat && $editCombat['date_prevue'] ? date('Y-m-d\TH:i', strtotime($editCombat['date_prevue'])) : '' ?>">
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="tour">Tour (arbre)</label>
                    <input type="number" id="tour" name="tour" min="1" step="1" value="<?= $editCombat['tour'] ?? '' ?>">
                </div>
                <div class="field">
                    <label for="position">Position dans le tour</label>
                    <input type="number" id="position" name="position" min="0" step="1" value="<?= $editCombat['position'] ?? '' ?>">
                </div>
            </div>
            <div class="grid grid-3" style="margin-top:var(--space-2);">
                <div class="field">
                    <label for="arbitre_central"><?= icon('target') ?> Arbitre central</label>
                    <select id="arbitre_central" name="arbitre_central">
                        <option value="">— Aucun —</option>
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($editArbitres['central'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="arbitre_coin1"><?= icon('corner') ?> Coin 1</label>
                    <select id="arbitre_coin1" name="arbitre_coin1">
                        <option value="">— Aucun —</option>
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($editArbitres['coin1'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="arbitre_coin2"><?= icon('corner') ?> Coin 2</label>
                    <select id="arbitre_coin2" name="arbitre_coin2">
                        <option value="">— Aucun —</option>
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($editArbitres['coin2'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p id="arbitres-doublon-alerte" class="flash flash-error" role="alert" hidden>Un même arbitre ne peut pas occuper deux rôles.</p>
        </div>
        <div class="modal-footer">
            <a href="combats.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('check-circle') ?> Enregistrer</button>
        </div>
    </form>
</dialog>
<script>
(function () {
    const ids = ['arbitre_central','arbitre_coin1','arbitre_coin2'];
    const selects = ids.map(id => document.getElementById(id));
    const alertBox = document.getElementById('arbitres-doublon-alerte');
    const form = selects[0]?.closest('form');
    if (!form) return;
    function sync() {
        const chosen = selects.map(s => s.value).filter(Boolean);
        const dup = new Set(chosen).size !== chosen.length;
        selects.forEach(select => {
            const others = selects.filter(s => s !== select).map(s => s.value).filter(Boolean);
            Array.from(select.options).forEach(opt => {
                opt.disabled = opt.value !== '' && opt.value !== select.value && others.includes(opt.value);
            });
        });
        alertBox.hidden = !dup;
    }
    selects.forEach(s => s.addEventListener('change', sync));
    form.addEventListener('submit', e => {
        const chosen = selects.map(s => s.value).filter(Boolean);
        if (new Set(chosen).size !== chosen.length) { e.preventDefault(); alertBox.hidden = false; alertBox.scrollIntoView({block:'center'}); }
    });
    sync();
})();
</script>
<?php endif; ?>

<?php /* ── Modal : génération automatique ── */ ?>
<?php if ($action === 'generer'): ?>
<dialog id="form-modal" class="modal modal-form" style="max-width:min(720px,96vw);" aria-labelledby="modal-generer-title">
    <form method="post" action="combats.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="generer_auto">
        <div class="modal-header">
            <h3 id="modal-generer-title"><?= icon('shuffle') ?> Génération automatique des combats</h3>
            <a href="combats.php" class="modal-close-btn" aria-label="Fermer">&#x2715;</a>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:var(--space-4);font-size:var(--fs-sm);">Forme des paires selon les règles configurées (poids, âge, grade, sexe), place le 1er tour dans l'arbre et répartit les arbitres en rotation.</p>
            <fieldset style="border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-3);margin-bottom:var(--space-4);">
                <legend><?= icon('users') ?> Joueurs participants</legend>
                <?php if (!$joueurs): ?>
                    <p>Aucun joueur. <a href="joueurs.php">Ajouter des joueurs</a> d'abord.</p>
                <?php else: ?>
                    <div class="grid grid-3">
                        <?php foreach ($joueurs as $j): ?>
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                                <input type="checkbox" name="joueurs_participants[]" value="<?= $j['id'] ?>">
                                <?= e($j['pseudo']) ?><?php if ($j['poids']): ?> <small class="hint"><?= number_format($j['poids'],1) ?> kg</small><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <fieldset style="border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-3);margin-bottom:var(--space-4);">
                <legend><?= icon('referee-badge') ?> Arbitres présents</legend>
                <?php if (!$arbitresDisponibles): ?>
                    <p>Aucun arbitre actif. <a href="users.php">Ajouter des arbitres</a> d'abord.</p>
                <?php else: ?>
                    <div class="grid grid-3">
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                                <input type="checkbox" name="arbitres_presents[]" value="<?= $a['id'] ?>">
                                <?= e($a['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                <input type="checkbox" name="vider_arbre_existant" value="1">
                Remplacer les combats à venir existants
            </label>
        </div>
        <div class="modal-footer">
            <a href="combats.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('shuffle') ?> Générer les combats</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Liste de tous les combats</caption>
        <thead><tr><th scope="col">Combat</th><th scope="col">Statut</th><th scope="col">Score</th><th scope="col">Arbre</th><th scope="col">Date prévue</th><th scope="col">Durée</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($combats as $c): $label = e($c['joueur1_pseudo']) . ' vs ' . e($c['joueur2_pseudo']); ?>
            <tr>
                <td><strong><?= $label ?></strong></td>
                <td><span class="badge badge-<?= e($c['statut']) ?>"><?= strtoupper(str_replace('_',' ',$c['statut'])) ?></span></td>
                <td><?= number_format($c['score_joueur1'],1) ?> – <?= number_format($c['score_joueur2'],1) ?></td>
                <td><?= $c['tour'] !== null ? 'T' . (int)$c['tour'] . ' #' . (int)$c['position'] : '—' ?></td>
                <td><?= formatDateFr($c['date_prevue']) ?></td>
                <td><?= (int)($c['duree_secondes']/60) ?> min</td>
                <td class="actions-inline">
                    <a href="../combat.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><?= icon('eye') ?> Voir</a>
                    <?php if ($c['statut'] === 'a_venir'): ?>
                        <a href="combats.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm"><?= icon('edit') ?> Éditer</a>
                        <a href="combats.php?action=start&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-success btn-sm"
                           data-confirm="Démarrer ce combat maintenant ?" data-confirm-type="success" data-confirm-label="Démarrer" data-confirm-icon="⚡"><?= icon('bolt') ?> Démarrer</a>
                    <?php elseif ($c['statut'] === 'en_cours'): ?>
                        <a href="combats.php?action=finish&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-danger btn-sm"
                           data-confirm="Terminer ce combat maintenant ?" data-confirm-type="warning" data-confirm-label="Terminer" data-confirm-icon="🏁"><?= icon('check-circle') ?> Terminer</a>
                    <?php endif; ?>
                    <a href="combats.php?action=delete&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-danger btn-sm"
                       data-confirm="Supprimer «<?= $label ?>» définitivement ?" data-confirm-label="Supprimer" data-confirm-icon="🗑️"><?= icon('trash') ?> Supprimer</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$combats): ?>
            <tr><td colspan="7"><div class="empty-state"><?= icon('bracket') ?><p>Aucun combat créé.</p><a href="combats.php?action=new" class="btn" style="margin-top:var(--space-3);"><?= icon('plus') ?> Créer le premier combat</a></div></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
