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

<h1>Combats</h1>

<div class="card" style="max-width:520px;">
    <h3><?= icon('clock') ?> Retard</h3>
    <p>Retard actuel affiché aux spectateurs : <strong><?= $retardActuel ?> minute<?= $retardActuel > 1 ? 's' : '' ?></strong></p>
    <form method="post" action="combats.php" class="wide" style="align-items:flex-end;">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="signaler_retard">
        <div class="grid grid-2">
            <div class="field">
                <label for="retard_minutes">Ajouter (ou retirer, en négatif) des minutes</label>
                <input type="number" id="retard_minutes" name="retard_minutes" step="1" required placeholder="ex. 10 ou -5">
            </div>
        </div>
        <div class="btn-row">
            <button type="submit">Appliquer le retard</button>
        </div>
        <small class="hint">Décale automatiquement l'heure prévue de tous les combats à venir et informe les spectateurs (bandeau visible sur tout le site).</small>
    </form>
</div>

<?php if ($action === 'generer'): ?>
    <div class="card" style="max-width:720px;">
        <h3><?= icon('shuffle') ?> Génération automatique des combats (1er tour)</h3>
        <p>Sélectionne les joueurs présents et les arbitres disponibles : l'appli forme automatiquement des paires selon les règles configurées dans <a href="parametres.php">Admin → Paramètres</a> (écart de poids, d'âge, de grade, même sexe), place le 1er tour de l'arbre, et répartit les arbitres en rotation.</p>

        <form method="post" action="combats.php" class="wide">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="generer_auto">

            <fieldset style="border:1px solid var(--border); border-radius: var(--radius-md); padding: var(--space-3);">
                <legend><?= icon('users') ?> Joueurs participants</legend>
                <?php if (!$joueurs): ?>
                    <p class="empty-state">Aucun joueur enregistré. <a href="joueurs.php">Ajoute des joueurs</a> d'abord.</p>
                <?php else: ?>
                    <div class="grid grid-3">
                        <?php foreach ($joueurs as $j): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="joueurs_participants[]" value="<?= $j['id'] ?>">
                                <?= e($j['pseudo']) ?>
                                <?php if ($j['poids']): ?><small class="hint"> (<?= number_format($j['poids'], 1) ?> kg)</small><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset style="border:1px solid var(--border); border-radius: var(--radius-md); padding: var(--space-3); margin-top: var(--space-4);">
                <legend><?= icon('referee-badge') ?> Arbitres présents</legend>
                <?php if (!$arbitresDisponibles): ?>
                    <p class="empty-state">Aucun arbitre actif. <a href="users.php">Ajoute des arbitres</a> d'abord.</p>
                <?php else: ?>
                    <div class="grid grid-3">
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="arbitres_presents[]" value="<?= $a['id'] ?>">
                                <?= e($a['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="field" style="margin-top: var(--space-4);">
                <label class="checkbox-label" for="vider_arbre_existant">
                    <input type="checkbox" id="vider_arbre_existant" name="vider_arbre_existant" value="1">
                    Remplacer l'arbre existant (uniquement les combats à venir, non commencés)
                </label>
            </div>

            <div class="btn-row">
                <button type="submit"><?= icon('shuffle') ?> Générer les combats</button>
                <a href="combats.php" class="btn btn-secondary">Annuler</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if (in_array($action, ['new', 'edit'])): ?>
    <div class="card" style="max-width:520px;">
        <h3><?= $action === 'new' ? 'Nouveau combat' : 'Modifier le combat' ?></h3>
        <form method="post" action="combats.php" class="wide">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
            <?php if ($action === 'edit' && !empty($editCombat['id'])): ?><input type="hidden" name="id" value="<?= $editCombat['id'] ?>"><?php endif; ?>

            <div class="field">
                <label for="joueur1_id">Joueur 1 *</label>
                <select id="joueur1_id" name="joueur1_id" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($joueurs as $j): ?>
                        <option value="<?= $j['id'] ?>" <?= (($editCombat['joueur1_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="joueur2_id">Joueur 2 *</label>
                <select id="joueur2_id" name="joueur2_id" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($joueurs as $j): ?>
                        <option value="<?= $j['id'] ?>" <?= (($editCombat['joueur2_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="duree_secondes">Durée du combat (secondes) *</label>
                <input type="number" id="duree_secondes" name="duree_secondes" min="30" step="10" required value="<?= $editCombat['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180) ?>">
                <small class="hint">Minimum 30 secondes.</small>
            </div>
            <div class="field">
                <label for="date_prevue">Date/heure prévue</label>
                <input type="datetime-local" id="date_prevue" name="date_prevue" value="<?= $editCombat && $editCombat['date_prevue'] ? date('Y-m-d\TH:i', strtotime($editCombat['date_prevue'])) : '' ?>">
            </div>

            <h4 id="heading-arbre-form"><?= icon('bracket') ?> Arbre de tournoi (optionnel)</h4>
            <div class="grid grid-2">
                <div class="field">
                    <label for="tour">Tour</label>
                    <input type="number" id="tour" name="tour" min="1" step="1" aria-describedby="heading-arbre-form" value="<?= $editCombat['tour'] ?? '' ?>">
                    <small class="hint">1 = premier tour, 2 = quarts, etc.</small>
                </div>
                <div class="field">
                    <label for="position">Position dans le tour</label>
                    <input type="number" id="position" name="position" min="0" step="1" value="<?= $editCombat['position'] ?? '' ?>">
                    <small class="hint">0, 1, 2… de haut en bas dans l'arbre.</small>
                </div>
            </div>

            <h4 id="heading-arbitres-form">Arbitres</h4>
            <div class="field">
                <label for="arbitre_central">Arbitre central *</label>
                <select id="arbitre_central" name="arbitre_central" aria-describedby="heading-arbitres-form">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($arbitresDisponibles as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (($editArbitres['central'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="arbitre_coin1">Arbitre de coin 1 *</label>
                <select id="arbitre_coin1" name="arbitre_coin1">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($arbitresDisponibles as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (($editArbitres['coin1'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="arbitre_coin2">Arbitre de coin 2 *</label>
                <select id="arbitre_coin2" name="arbitre_coin2">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($arbitresDisponibles as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (($editArbitres['coin2'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <p id="arbitres-doublon-alerte" class="flash flash-error" role="alert" hidden>
                Un même arbitre ne peut pas occuper deux rôles : merci de choisir 3 arbitres distincts.
            </p>

            <div class="btn-row">
                <button type="submit">Enregistrer</button>
                <a href="combats.php" class="btn btn-secondary">Annuler</a>
            </div>
        </form>
        <script>
        // Empêche de choisir le même arbitre pour deux rôles à la fois : grise les options
        // déjà prises dans les deux autres menus, et bloque l'envoi si un doublon subsiste.
        (function () {
            const ids = ['arbitre_central', 'arbitre_coin1', 'arbitre_coin2'];
            const selects = ids.map((id) => document.getElementById(id));
            const alertBox = document.getElementById('arbitres-doublon-alerte');
            const form = selects[0]?.closest('form');
            if (!form) return;

            function sync() {
                const chosen = selects.map((s) => s.value).filter(Boolean);
                const hasDuplicate = new Set(chosen).size !== chosen.length;

                selects.forEach((select) => {
                    const others = selects.filter((s) => s !== select).map((s) => s.value).filter(Boolean);
                    Array.from(select.options).forEach((opt) => {
                        opt.disabled = opt.value !== '' && opt.value !== select.value && others.includes(opt.value);
                    });
                });

                alertBox.hidden = !hasDuplicate;
            }

            selects.forEach((select) => select.addEventListener('change', sync));
            form.addEventListener('submit', (event) => {
                const chosen = selects.map((s) => s.value).filter(Boolean);
                if (new Set(chosen).size !== chosen.length) {
                    event.preventDefault();
                    alertBox.hidden = false;
                    alertBox.scrollIntoView({ block: 'center' });
                }
            });

            sync();
        })();
        </script>
    </div>
<?php elseif ($action !== 'generer'): ?>
    <p class="btn-row">
        <a href="combats.php?action=new" class="btn">+ Nouveau combat</a>
        <a href="combats.php?action=generer" class="btn btn-secondary"><?= icon('shuffle') ?> Génération automatique</a>
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Liste de tous les combats, quel que soit leur statut</caption>
        <thead><tr><th scope="col">Combat</th><th scope="col">Statut</th><th scope="col">Score</th><th scope="col">Arbre</th><th scope="col">Date prévue</th><th scope="col">Durée</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($combats as $c): $label = e($c['joueur1_pseudo']) . ' vs ' . e($c['joueur2_pseudo']); ?>
            <tr>
                <td><?= $label ?></td>
                <td><span class="badge badge-<?= e($c['statut']) ?>"><?= strtoupper(str_replace('_',' ',$c['statut'])) ?></span></td>
                <td><?= number_format($c['score_joueur1'],1) ?> - <?= number_format($c['score_joueur2'],1) ?></td>
                <td><?= $c['tour'] !== null ? 'Tour ' . (int)$c['tour'] . ' · #' . (int)$c['position'] : '—' ?></td>
                <td><?= formatDateFr($c['date_prevue']) ?></td>
                <td><?= (int)($c['duree_secondes']/60) ?> min</td>
                <td class="actions-inline">
                    <a href="../combat.php?id=<?= $c['id'] ?>" class="btn btn-secondary">Voir<span class="visually-hidden"> le combat <?= $label ?></span></a>
                    <?php if ($c['statut'] === 'a_venir'): ?>
                        <a href="combats.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-secondary">Éditer<span class="visually-hidden"> <?= $label ?></span></a>
                        <a href="combats.php?action=start&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-success" onclick="return confirm('Démarrer le combat <?= e(addslashes($c['joueur1_pseudo'] . ' vs ' . $c['joueur2_pseudo'])) ?> maintenant ?');">Démarrer</a>
                    <?php elseif ($c['statut'] === 'en_cours'): ?>
                        <a href="combats.php?action=finish&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-danger" onclick="return confirm('Terminer ce combat maintenant ?');">Terminer</a>
                    <?php endif; ?>
                    <a href="combats.php?action=delete&id=<?= $c['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="btn btn-danger" onclick="return confirm('Supprimer le combat <?= e(addslashes($c['joueur1_pseudo'] . ' vs ' . $c['joueur2_pseudo'])) ?> ?');">Supprimer<span class="visually-hidden"> <?= $label ?></span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$combats): ?>
            <tr><td colspan="7" class="empty-state">Aucun combat créé.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
