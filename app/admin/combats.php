<?php
/**
 * admin/combats.php : planning et gestion des combats.
 *
 * - Liste filtrable (en cours / à venir / terminés) mise à jour en direct
 * - Sélection multiple : démarrer, terminer ou supprimer plusieurs combats
 * - Démarrage automatique à l'heure prévue (heure du serveur)
 * - Génération automatique avec planning (heure du 1er combat + intervalle)
 */
require_once __DIR__ . '/../config/config.php';
requirePermission('combats.voir');

purgerCombatsExpires($pdo);

$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');
$filtre = $_GET['filtre'] ?? 'actifs';
if (!in_array($filtre, ['actifs', 'en_cours', 'a_venir', 'termine', 'tous'], true)) $filtre = 'actifs';
$retourListe = 'admin/combats.php' . ($filtre !== 'actifs' ? '?filtre=' . $filtre : '');

$joueurs = $pdo->query(
    'SELECT j.*, g.nom AS grade_nom FROM joueurs j LEFT JOIN grades g ON g.id = j.grade_id ORDER BY j.pseudo ASC'
)->fetchAll();

// Arbitres possibles = comptes actifs dont le rôle a la permission « arbitrage.acces »
$__rolesArbitres = rolesAvecPermission($pdo, 'arbitrage.acces');
$__stArb = $pdo->prepare('SELECT * FROM users WHERE actif = 1 AND role IN (' . implode(',', array_fill(0, count($__rolesArbitres), '?')) . ') ORDER BY username ASC');
$__stArb->execute($__rolesArbitres);
$arbitresDisponibles = $__stArb->fetchAll();

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

/** Un même arbitre ne peut pas occuper deux rôles sur le même combat. */
function arbitresChoisisEnDouble(array $post): bool
{
    $choisis = array_filter([
        $post['arbitre_central'] ?? '',
        $post['arbitre_coin1'] ?? '',
        $post['arbitre_coin2'] ?? '',
    ], static fn($v) => $v !== '' && $v !== null);
    return count($choisis) !== count(array_unique($choisis));
}

/** Valeur datetime-local → DATETIME SQL (heure du serveur), ou null. */
function lireDateFormulaire(mixed $v): ?string
{
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $v)) return null;
    $d = DateTime::createFromFormat(strlen($v) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s', $v);
    if (!$d || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) return null;
    return $d->format('Y-m-d H:i:s');
}

function exigerCsrfPost(string $retour): void
{
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect($retour);
    }
}

/* ─────────────────────────── ACTIONS ─────────────────────────── */
$fa = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['form_action'] ?? '') : '';

// ---- Génération automatique ----
if ($fa === 'generer_auto') {
    exigerPermissionAction('combats.generer', 'admin/combats.php');
    exigerCsrfPost('admin/combats.php?action=generer');

    $joueurIds = array_values(array_unique(array_filter(array_map('idPositif', array_slice((array)($_POST['joueurs_participants'] ?? []), 0, 1000)))));
    $arbitreIds = array_values(array_unique(array_filter(array_map('idPositif', array_slice((array)($_POST['arbitres_presents'] ?? []), 0, 200)))));
    // Seuls des arbitres réellement habilités peuvent être affectés
    $arbitreIds = array_values(array_intersect($arbitreIds, array_map('intval', array_column($arbitresDisponibles, 'id'))));
    $vider = !empty($_POST['vider_arbre_existant']);

    if (count($joueurIds) < 2) {
        flash('error', 'Sélectionnez au moins 2 joueurs participants.');
        stashOldInput($_POST);
        redirect('admin/combats.php?action=generer');
    }

    $resume = genererCombatsAutomatiques($pdo, $joueurIds, $arbitreIds, $vider, [
        'debut'          => lireDateFormulaire($_POST['heure_debut'] ?? ''),
        'intervalle_min' => min(240, max(1, (int)($_POST['intervalle_min'] ?? 5))),
        'duree'          => min(3600, max(30, (int)($_POST['duree_secondes'] ?? 180))),
    ]);

    if ($resume['erreur']) {
        flash('error', $resume['erreur']);
        stashOldInput($_POST);
        redirect('admin/combats.php?action=generer');
    }
    if (!empty($_POST['intervalle_min'])) setParametre($pdo, 'intervalle_combats_min', (string)max(1, (int)$_POST['intervalle_min']));

    $message = $resume['combats_crees'] . ' combat(s) créé(s) au 1er tour de l\'arbre.';
    if (!empty($_POST['heure_debut'])) $message .= ' Ils démarreront automatiquement à leur heure.';
    if ($resume['non_apparies']) {
        $message .= ' Sans adversaire compatible : ' . implode(', ', $resume['non_apparies']) . '.';
    }
    foreach ($resume['avertissements'] as $avert) $message .= ' ' . $avert;
    flash($resume['non_apparies'] || $resume['avertissements'] ? 'info' : 'success', $message);
    redirect('admin/combats.php');
}

// ---- Retard global ----
if ($fa === 'signaler_retard') {
    exigerPermissionAction('combats.retard', $retourListe);
    exigerCsrfPost($retourListe);
    $minutes = max(-600, min(600, (int)($_POST['retard_minutes'] ?? 0)));
    if ($minutes === 0) {
        flash('error', 'Indiquez un nombre de minutes différent de zéro.');
        redirect($retourListe);
    }
    $nb = appliquerRetard($pdo, $minutes);
    flash('success', abs($minutes) . ' minute(s) ' . ($minutes > 0 ? 'ajoutée(s)' : 'retirée(s)') . ". {$nb} combat(s) à venir replanifié(s).");
    redirect($retourListe);
}

// ---- Démarrage automatique on/off ----
if ($fa === 'demarrage_auto') {
    exigerPermissionAction('combats.demarrer', $retourListe);
    exigerCsrfPost($retourListe);
    $on = !empty($_POST['valeur']) ? '1' : '0';
    setParametre($pdo, 'demarrage_auto', $on);
    flash('success', $on === '1' ? 'Démarrage automatique activé : chaque combat démarre à son heure prévue.' : 'Démarrage automatique désactivé : les combats se démarrent à la main.');
    redirect($retourListe);
}

// ---- Créer / modifier ----
if (in_array($fa, ['create', 'update'], true)) {
    $estCreation = $fa === 'create';
    exigerPermissionAction($estCreation ? 'combats.creer' : 'combats.modifier', $retourListe);
    exigerCsrfPost($retourListe);

    $editId = idPositif($_POST['id'] ?? '');
    $retourForm = $estCreation ? 'admin/combats.php?action=new' : 'admin/combats.php?action=edit&id=' . $editId;
    $j1 = idPositif($_POST['joueur1_id'] ?? '');
    $j2 = idPositif($_POST['joueur2_id'] ?? '');
    $duree = min(3600, max(30, (int)($_POST['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180))));
    $datePrevue = lireDateFormulaire($_POST['date_prevue'] ?? '');
    $tour = ($_POST['tour'] ?? '') !== '' ? min(64, max(1, (int)$_POST['tour'])) : null;
    $position = ($_POST['position'] ?? '') !== '' ? min(10000, max(0, (int)$_POST['position'])) : null;

    // Les joueurs et arbitres doivent exister (et les arbitres être habilités)
    $idsJoueurs = array_map('intval', array_column($joueurs, 'id'));
    $idsArbitres = array_map('intval', array_column($arbitresDisponibles, 'id'));
    foreach (['arbitre_central', 'arbitre_coin1', 'arbitre_coin2'] as $champ) {
        $v = $_POST[$champ] ?? '';
        $_POST[$champ] = ($v !== '' && in_array(idPositif($v), $idsArbitres, true)) ? (string)idPositif($v) : '';
    }
    $erreur = null;
    if (!$j1 || !$j2 || $j1 === $j2) $erreur = 'Choisissez deux joueurs différents.';
    elseif (!in_array($j1, $idsJoueurs, true) || !in_array($j2, $idsJoueurs, true)) $erreur = 'Joueur introuvable.';
    elseif (arbitresChoisisEnDouble($_POST)) $erreur = 'Un même arbitre ne peut pas occuper deux rôles sur le même combat.';
    if ($erreur) {
        flash('error', $erreur);
        stashOldInput($_POST);
        redirect($retourForm);
    }

    if ($estCreation) {
        $pdo->prepare("INSERT INTO combats (joueur1_id, joueur2_id, duree_secondes, date_prevue, tour, position, statut) VALUES (?, ?, ?, ?, ?, ?, 'a_venir')")
            ->execute([$j1, $j2, $duree, $datePrevue, $tour, $position]);
        $editId = (int)$pdo->lastInsertId();
    } else {
        $st = $pdo->prepare("UPDATE combats SET joueur1_id=?, joueur2_id=?, duree_secondes=?, date_prevue=?, tour=?, position=? WHERE id=? AND statut = 'a_venir'");
        $st->execute([$j1, $j2, $duree, $datePrevue, $tour, $position, $editId]);
        $existe = $pdo->prepare("SELECT statut FROM combats WHERE id = ?");
        $existe->execute([$editId]);
        if ($existe->fetchColumn() !== 'a_venir') {
            flash('error', 'Ce combat a déjà démarré : il ne peut plus être modifié.');
            redirect($retourListe);
        }
    }
    saveAssignations($pdo, $editId, $_POST);

    $msg = $estCreation ? 'Combat créé.' : 'Combat mis à jour.';
    if ($datePrevue && getParametreInt($pdo, 'demarrage_auto', 1) === 1) {
        $msg .= ' Démarrage automatique à ' . date('H:i', strtotime($datePrevue)) . (strtotime($datePrevue) <= time() ? ' (heure passée : il démarre dès que ses 3 arbitres sont libres).' : '.');
    }
    flash('success', $msg);
    redirect($retourListe);
}

// ---- Actions sur un ou plusieurs combats ----
if ($fa === 'lot') {
    exigerCsrfPost($retourListe);
    $ids = array_values(array_unique(array_filter(array_map('idPositif', array_slice((array)($_POST['ids'] ?? []), 0, 500)))));
    $quoi = $_POST['lot_action'] ?? '';
    $perm = ['demarrer' => 'combats.demarrer', 'terminer' => 'combats.terminer', 'supprimer' => 'combats.supprimer'][$quoi] ?? null;
    if (!$perm || !$ids) {
        flash('error', 'Aucun combat sélectionné.');
        redirect($retourListe);
    }
    exigerPermissionAction($perm, $retourListe);

    $ok = 0; $erreurs = [];
    foreach ($ids as $cid) {
        $c = getCombat($pdo, $cid);
        if (!$c) continue;
        if ($quoi === 'demarrer') {
            if ($c['statut'] !== 'a_venir') continue;
            $r = demarrerCombat($pdo, $cid);
            $r['ok'] ? $ok++ : $erreurs[] = $r['message'];
        } elseif ($quoi === 'terminer') {
            if ($c['statut'] !== 'en_cours') continue;
            terminerCombat($pdo, $cid);
            $ok++;
        } elseif ($quoi === 'supprimer') {
            supprimerCombat($pdo, $cid) ? $ok++ : $erreurs[] = 'Suppression impossible pour ' . $c['joueur1_pseudo'] . ' contre ' . $c['joueur2_pseudo'] . '.';
        }
    }
    $verbe = ['demarrer' => 'démarré(s)', 'terminer' => 'terminé(s)', 'supprimer' => 'supprimé(s)'][$quoi];
    if ($ok) flash('success', "{$ok} combat(s) {$verbe}.");
    foreach (array_slice($erreurs, 0, 5) as $er) flash('error', $er);
    if (!$ok && !$erreurs) flash('info', 'Aucun combat sélectionné ne pouvait être ' . rtrim($verbe, '(s)') . '.');
    redirect($retourListe);
}

// ---- Pause / reprise d'un combat en cours ----
if ($fa === 'pause_combat' || $fa === 'reprendre_combat') {
    exigerCsrfPost($retourListe);
    if (!can('combats.demarrer', 'combats.terminer')) {
        flash('error', 'Votre rôle ne permet pas de mettre un combat en pause.');
        redirect($retourListe);
    }
    $cid = idPositif($_POST['id'] ?? '');
    $r = $fa === 'pause_combat' ? mettreEnPauseCombat($pdo, $cid) : reprendreCombat($pdo, $cid);
    flash($r['ok'] ? 'success' : 'error', $r['message']);
    redirect($retourListe);
}

/* ─────────────────────────── DONNÉES ─────────────────────────── */
if ($action === 'new' && !can('combats.creer')) $action = 'list';
if ($action === 'edit' && !can('combats.modifier')) $action = 'list';
if ($action === 'generer' && !can('combats.generer')) $action = 'list';

$editCombat = null;
$editArbitres = [];
if ($action === 'edit' && $id) {
    $editCombat = getCombat($pdo, $id);
    if (!$editCombat || $editCombat['statut'] !== 'a_venir') {
        flash('error', 'Seuls les combats à venir peuvent être modifiés.');
        redirect($retourListe);
    }
    foreach (getArbitresDuCombat($pdo, $id) as $a) $editArbitres[$a['role']] = $a['user_id'];
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

$tousCombats = $pdo->query(
    "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo,
            (SELECT COUNT(*) FROM combat_arbitres ca WHERE ca.combat_id = c.id) AS nb_arbitres,
            (SELECT GROUP_CONCAT(u.username ORDER BY FIELD(ca.role,'central','coin1','coin2') SEPARATOR ', ')
               FROM combat_arbitres ca JOIN users u ON u.id = ca.user_id WHERE ca.combat_id = c.id) AS noms_arbitres
     FROM combats c
     JOIN joueurs j1 ON j1.id = c.joueur1_id
     JOIN joueurs j2 ON j2.id = c.joueur2_id
     ORDER BY FIELD(c.statut,'en_cours','a_venir','termine','annule'),
              CASE WHEN c.statut = 'termine' THEN NULL ELSE c.date_prevue IS NULL END,
              CASE WHEN c.statut = 'termine' THEN NULL ELSE c.date_prevue END ASC,
              c.temps_fin DESC, c.id ASC"
)->fetchAll();

$compte = ['en_cours' => 0, 'a_venir' => 0, 'termine' => 0];
foreach ($tousCombats as $c) if (isset($compte[$c['statut']])) $compte[$c['statut']]++;
$compte['actifs'] = $compte['en_cours'] + $compte['a_venir'];
$compte['tous'] = count($tousCombats);

$combats = array_values(array_filter($tousCombats, function ($c) use ($filtre) {
    return match ($filtre) {
        'actifs' => in_array($c['statut'], ['en_cours', 'a_venir'], true),
        'tous' => true,
        default => $c['statut'] === $filtre,
    };
}));

$demarrageAuto = getParametreInt($pdo, 'demarrage_auto', 1) === 1;
$retardActuel = getParametreInt($pdo, 'retard_minutes', 0);
$maintenant = time();
$peutLot = can('combats.demarrer') || can('combats.terminer') || can('combats.supprimer');

// Valeurs par défaut du formulaire de génération
$defIntervalle = getParametreInt($pdo, 'intervalle_combats_min', 5);
$defDebut = date('Y-m-d\TH:i', (int)(ceil(($maintenant + 5 * 60) / 300) * 300));
$oldGen = $action === 'generer' ? $old : [];

$pageTitle = 'Combats';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';

$roleLabels = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];
?>

<div class="page-head">
    <div>
        <h1>Combats</h1>
        <p class="sub"><?= $compte['en_cours'] ?> en cours · <?= $compte['a_venir'] ?> à venir · <?= $compte['termine'] ?> terminé<?= $compte['termine'] > 1 ? 's' : '' ?></p>
    </div>
    <div class="btn-row">
        <?php if (can('combats.generer')): ?><a href="combats.php?action=generer" class="btn btn-secondary"><?= icon('shuffle') ?>Générer le tournoi</a><?php endif; ?>
        <?php if (can('combats.creer')): ?><a href="combats.php?action=new<?= $filtre !== 'actifs' ? '&filtre=' . e($filtre) : '' ?>" class="btn"><?= icon('plus') ?>Nouveau combat</a><?php endif; ?>
    </div>
</div>

<div class="control-panel">
    <div>
        <span class="cp-label">Heure du serveur</span>
        <span class="cp-value" data-server-clock><?= date('H:i:s') ?></span>
    </div>
    <div>
        <?php if (can('combats.demarrer')): ?>
            <form method="post" action="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" data-autosubmit>
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="form_action" value="demarrage_auto">
                <label class="switch">
                    <span class="switch-text">
                        <strong>Démarrage automatique</strong>
                        <span class="hint"><?= $demarrageAuto ? 'Chaque combat démarre à son heure.' : 'Les combats se démarrent à la main.' ?></span>
                    </span>
                    <input type="checkbox" name="valeur" value="1" <?= $demarrageAuto ? 'checked' : '' ?> role="switch" aria-checked="<?= $demarrageAuto ? 'true' : 'false' ?>">
                </label>
                <noscript><button type="submit" class="btn btn-sm btn-secondary">Appliquer</button></noscript>
            </form>
        <?php else: ?>
            <span class="cp-label">Démarrage automatique</span>
            <span class="cp-value"><?= $demarrageAuto ? 'Activé' : 'Désactivé' ?></span>
        <?php endif; ?>
    </div>
    <div>
        <span class="cp-label">Retard du tournoi · <?= $retardActuel > 0 ? '<span class="text-warning">' . $retardActuel . ' min</span>' : 'aucun' ?></span>
        <?php if (can('combats.retard')): ?>
            <form method="post" action="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" class="retard-form" novalidate data-no-validate>
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="form_action" value="signaler_retard">
                <label for="retard_minutes" class="visually-hidden">Minutes de retard à ajouter (négatif pour rattraper)</label>
                <div class="input-suffix"><input type="number" id="retard_minutes" name="retard_minutes" step="1" required placeholder="+10" inputmode="numeric"><span class="suffix">min</span></div>
                <button type="submit" class="btn btn-secondary btn-sm">Décaler les combats</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <nav class="segmented" aria-label="Filtrer les combats">
        <?php foreach (['actifs' => 'En cours et à venir', 'en_cours' => 'En cours', 'a_venir' => 'À venir', 'termine' => 'Terminés', 'tous' => 'Tous'] as $k => $lib): ?>
            <a href="combats.php?filtre=<?= $k ?>" <?= $filtre === $k ? 'aria-current="true"' : '' ?>><?= $lib ?> <span class="count"><?= $compte[$k] ?></span></a>
        <?php endforeach; ?>
    </nav>
    <span class="live-hint" id="live-state">Mise à jour automatique</span>
</div>

<form method="post" action="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" id="lot-form" class="wide" data-no-validate>
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="form_action" value="lot">
    <input type="hidden" name="lot_action" id="lot-action" value="">

    <?php if ($peutLot): ?>
    <div class="bulk-bar" id="bulk-bar" role="region" aria-label="Actions sur la sélection">
        <span class="count"><span id="bulk-count">0</span> sélectionné(s)</span>
        <?php if (can('combats.demarrer')): ?><button type="button" class="btn btn-sm btn-secondary" data-lot="demarrer"><?= icon('play') ?>Démarrer</button><?php endif; ?>
        <?php if (can('combats.terminer')): ?><button type="button" class="btn btn-sm btn-secondary" data-lot="terminer"><?= icon('flag') ?>Terminer</button><?php endif; ?>
        <?php if (can('combats.supprimer')): ?><button type="button" class="btn btn-sm btn-danger" data-lot="supprimer"><?= icon('trash') ?>Supprimer</button><?php endif; ?>
        <button type="button" class="btn btn-icon btn-sm" id="bulk-clear" aria-label="Tout désélectionner" title="Tout désélectionner"><?= icon('x') ?></button>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table id="combats-table">
            <caption class="visually-hidden">Liste des combats</caption>
            <thead>
                <tr>
                    <?php if ($peutLot): ?><th scope="col" class="col-check"><input type="checkbox" id="check-all" aria-label="Tout sélectionner"></th><?php endif; ?>
                    <th scope="col">Heure</th>
                    <th scope="col">Combat</th>
                    <th scope="col">Statut</th>
                    <th scope="col" class="num">Score</th>
                    <th scope="col">Arbitres</th>
                    <th scope="col">Durée</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody id="combats-body">
            <?php foreach ($combats as $c):
                $prevue = $c['date_prevue'] ? strtotime($c['date_prevue']) : null;
                $blocage = null;
                if ($c['statut'] === 'a_venir' && (($prevue && $prevue <= $maintenant) || (int)$c['nb_arbitres'] < 3)) {
                    $blocage = raisonBlocageDemarrage($pdo, $c);
                }
                $nomCombat = $c['joueur1_pseudo'] . ' contre ' . $c['joueur2_pseudo'];
                $lienFiltre = $filtre !== 'actifs' ? '&filtre=' . e($filtre) : '';
                // Actions secondaires regroupées dans le menu « ⋯ »
                $menu = '';
                if ($c['statut'] === 'a_venir' && can('combats.modifier')) {
                    $menu .= '<a role="menuitem" href="combats.php?action=edit&id=' . (int)$c['id'] . $lienFiltre . '">' . icon('edit') . 'Modifier</a>';
                }
                if ($c['statut'] === 'en_cours' && can('combats.demarrer', 'combats.terminer')) {
                    $menu .= !empty($c['en_pause_depuis'])
                        ? boutonPost('combats.php', 'reprendre_combat', ['id' => (int)$c['id']], 'Reprendre le combat', 'play')
                        : boutonPost('combats.php', 'pause_combat', ['id' => (int)$c['id']], 'Mettre en pause', 'pause');
                }
                $menu .= '<a role="menuitem" href="../combat.php?id=' . (int)$c['id'] . '">' . icon('eye') . 'Voir la page publique</a>';
                if (can('combats.supprimer')) {
                    $menu .= '<div class="menu-sep" role="separator"></div><button type="button" role="menuitem" class="is-danger" data-one="supprimer" data-id="' . (int)$c['id'] . '">' . icon('trash') . 'Supprimer</button>';
                }
            ?>
                <tr data-id="<?= (int)$c['id'] ?>" class="<?= $c['statut'] === 'en_cours' ? 'row-live' : '' ?>">
                    <?php if ($peutLot): ?><td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" class="js-row-check" aria-label="Sélectionner <?= e($nomCombat) ?>"></td><?php endif; ?>
                    <td class="when-cell">
                        <?php if ($c['statut'] === 'en_cours'): ?>
                            <strong class="js-timer" <?= attrsChrono($c) ?>><?= gmdate('i:s', tempsRestant($c)) ?></strong>
                            <span class="eta<?= !empty($c['en_pause_depuis']) ? ' late' : '' ?>"><?= !empty($c['en_pause_depuis']) ? 'en pause' : 'restant' ?></span>
                        <?php elseif ($c['statut'] === 'termine'): ?>
                            <strong><?= $c['temps_fin'] ? date('H:i', strtotime($c['temps_fin'])) : '' ?></strong>
                            <span class="eta">fin <?= $c['temps_fin'] ? date('d/m', strtotime($c['temps_fin'])) : '' ?></span>
                        <?php elseif ($prevue): ?>
                            <strong><?= date('H:i', $prevue) ?></strong>
                            <?php if ($prevue > $maintenant): ?>
                                <span class="eta" data-countdown-to="<?= $prevue * 1000 ?>"></span>
                            <?php else: ?>
                                <span class="eta late">en attente</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong class="muted">--:--</strong>
                            <span class="eta">manuel</span>
                        <?php endif; ?>
                    </td>
                    <td class="c-duel">
                        <a class="duel-cell" href="../combat.php?id=<?= (int)$c['id'] ?>"><?= e($c['joueur1_pseudo']) ?><span class="vs">contre</span><?= e($c['joueur2_pseudo']) ?></a>
                        <?php if ($c['tour'] !== null): ?><span class="sub-line">Tour <?= (int)$c['tour'] ?> · position <?= (int)$c['position'] ?></span><?php endif; ?>
                        <?php if ($blocage): ?><span class="warn-text"><?= icon('alert-triangle', 'icon-sm') ?><?= e(ucfirst($blocage)) ?></span><?php endif; ?>
                    </td>
                    <td class="c-status"><?= badgeStatut($c['statut']) ?></td>
                    <td class="num score-cell c-score">
                        <?php if ($c['statut'] === 'a_venir'): ?><span class="muted">–</span>
                        <?php else: ?><span class="side-1"><?= fmtScore($c['score_joueur1']) ?></span><span class="muted">à</span><span class="side-2"><?= fmtScore($c['score_joueur2']) ?></span><?php endif; ?>
                    </td>
                    <td class="c-refs">
                        <?php if ((int)$c['nb_arbitres'] >= 3 || $blocage): ?>
                            <span class="hint"><?= e($c['noms_arbitres'] ?: 'aucun') ?></span>
                        <?php else: ?>
                            <span class="warn-text"><?= (int)$c['nb_arbitres'] ?> arbitre<?= (int)$c['nb_arbitres'] > 1 ? 's' : '' ?> sur 3</span>
                            <?php if ($c['noms_arbitres']): ?><span class="sub-line"><?= e($c['noms_arbitres']) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="nowrap c-duree"><?= formatDuree((int)$c['duree_secondes']) ?></td>
                    <td class="cell-actions">
                        <div class="actions-inline">
                            <?php if ($c['statut'] === 'a_venir' && (int)$c['nb_arbitres'] < 3 && can('combats.modifier')): ?>
                                <a class="btn btn-sm btn-secondary" href="combats.php?action=edit&id=<?= (int)$c['id'] . $lienFiltre ?>"><?= icon('user-plus') ?>Affecter</a>
                            <?php elseif ($c['statut'] === 'a_venir' && can('combats.demarrer')): ?>
                                <button type="button" class="btn btn-sm btn-secondary" data-one="demarrer" data-id="<?= (int)$c['id'] ?>"<?= (int)$c['nb_arbitres'] < 3 ? ' disabled title="Il faut 3 arbitres pour démarrer"' : '' ?>><?= icon('play') ?>Démarrer</button>
                            <?php elseif ($c['statut'] === 'en_cours' && can('combats.terminer')): ?>
                                <button type="button" class="btn btn-sm btn-secondary" data-one="terminer" data-id="<?= (int)$c['id'] ?>"><?= icon('flag') ?>Terminer</button>
                            <?php endif; ?>
                            <?= menuActions($menu, 'Plus d\'actions pour ' . $nomCombat) ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$combats): ?>
                <tr><td colspan="8">
                    <?= $compte['tous']
                        ? etatVide('Aucun combat dans ce filtre', 'Changez de filtre pour voir les autres combats.', '<a href="combats.php?filtre=tous" class="btn btn-secondary">Voir tous les combats</a>', 'bolt', 'compact')
                        : etatVide('Aucun combat pour le moment', 'Générez l\'arbre à partir des joueurs inscrits, ou créez un combat à la main.', can('combats.generer') ? '<a href="combats.php?action=generer" class="btn">' . icon('shuffle') . 'Générer le tournoi</a>' : '', 'bolt', 'compact') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<?php /* ═════════════ Modal : nouveau / modifier ═════════════ */ ?>
<?php if (in_array($action, ['new', 'edit'], true)):
    $valDate = !empty($editCombat['date_prevue']) ? date('Y-m-d\TH:i', strtotime($editCombat['date_prevue'])) : '';
?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-combat-title">
    <form method="post" action="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
        <?php if ($action === 'edit' && !empty($editCombat['id'])): ?><input type="hidden" name="id" value="<?= (int)$editCombat['id'] ?>"><?php endif; ?>
        <div class="modal-header">
            <h3 id="modal-combat-title"><?= $action === 'new' ? 'Nouveau combat' : 'Modifier le combat' ?></h3>
            <a href="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="grid grid-2">
                <div class="field">
                    <label for="joueur1_id"><span class="side-1">Aka</span> · joueur 1</label>
                    <select id="joueur1_id" name="joueur1_id" required>
                        <option value="">Choisir</option>
                        <?php foreach ($joueurs as $j): ?>
                            <option value="<?= (int)$j['id'] ?>" <?= (($editCombat['joueur1_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="joueur2_id"><span class="side-2">Ao</span> · joueur 2</label>
                    <select id="joueur2_id" name="joueur2_id" required>
                        <option value="">Choisir</option>
                        <?php foreach ($joueurs as $j): ?>
                            <option value="<?= (int)$j['id'] ?>" <?= (($editCombat['joueur2_id'] ?? null) == $j['id']) ? 'selected' : '' ?>><?= e($j['pseudo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="date_prevue">Heure de début</label>
                    <input type="datetime-local" id="date_prevue" name="date_prevue" value="<?= e($valDate) ?>">
                    <span class="hint"><?= $demarrageAuto ? 'Le combat démarre tout seul à cette heure (heure du serveur). Vide : démarrage manuel.' : 'Démarrage automatique désactivé : démarrage manuel.' ?></span>
                </div>
                <div class="field">
                    <label for="duree_secondes">Durée</label>
                    <select id="duree_secondes" name="duree_secondes">
                        <?php
                        $dureeActuelle = (int)($editCombat['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180));
                        $choix = array_unique(array_merge([60, 90, 120, 150, 180, 240, 300, 360, 480, 600], [$dureeActuelle]));
                        sort($choix);
                        foreach ($choix as $d): ?>
                            <option value="<?= $d ?>" <?= $d === $dureeActuelle ? 'selected' : '' ?>><?= formatDuree($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <fieldset>
                <legend>Arbitres</legend>
                <div class="grid grid-3">
                    <?php foreach (['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'] as $r => $lib): ?>
                    <div class="field">
                        <label for="arbitre_<?= $r ?>"><?= $lib ?></label>
                        <select id="arbitre_<?= $r ?>" name="arbitre_<?= $r ?>" class="js-arb">
                            <option value="">Aucun</option>
                            <?php foreach ($arbitresDisponibles as $a): ?>
                                <option value="<?= (int)$a['id'] ?>" <?= (($editArbitres[$r] ?? null) == $a['id']) ? 'selected' : '' ?>><?= e($a['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="hint mt-4">Un combat ne démarre qu'avec ses 3 arbitres.</p>
                <p id="arbitres-doublon-alerte" class="flash flash-error mt-4" role="alert" hidden><?= icon('alert-triangle') ?><span class="flash-body">Un même arbitre ne peut pas occuper deux rôles.</span></p>
            </fieldset>
            <details class="form-section" <?= (!empty($editCombat['tour']) || ($editCombat['position'] ?? '') !== '') ? 'open' : '' ?>>
                <summary><span>Place dans l'arbre <span class="hint">(facultatif)</span></span><span class="summary-arrow" aria-hidden="true"><?= icon('chevron-down') ?></span></summary>
                <div class="form-section-body grid grid-2">
                    <div class="field">
                        <label for="tour">Tour</label>
                        <input type="number" id="tour" name="tour" min="1" step="1" value="<?= e((string)($editCombat['tour'] ?? '')) ?>" placeholder="1">
                    </div>
                    <div class="field">
                        <label for="position">Position dans le tour</label>
                        <input type="number" id="position" name="position" min="0" step="1" value="<?= e((string)($editCombat['position'] ?? '')) ?>" placeholder="0">
                    </div>
                </div>
            </details>
        </div>
        <div class="modal-footer">
            <a href="combats.php<?= $filtre !== 'actifs' ? '?filtre=' . e($filtre) : '' ?>" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new' ? 'Créer le combat' : 'Enregistrer les modifications' ?></button>
        </div>
    </form>
</dialog>
<script nonce="<?= e(cspNonce()) ?>">
(function () {
    const selects = Array.from(document.querySelectorAll('.js-arb'));
    const j1 = document.getElementById('joueur1_id'), j2 = document.getElementById('joueur2_id');
    const alertBox = document.getElementById('arbitres-doublon-alerte');
    const form = j1.closest('form');
    function sync() {
        selects.forEach(select => {
            const others = selects.filter(s => s !== select).map(s => s.value).filter(Boolean);
            Array.from(select.options).forEach(o => { o.disabled = o.value !== '' && o.value !== select.value && others.includes(o.value); });
        });
        [[j1, j2], [j2, j1]].forEach(([a, b]) => Array.from(a.options).forEach(o => { o.disabled = o.value !== '' && o.value === b.value; }));
        const chosen = selects.map(s => s.value).filter(Boolean);
        alertBox.hidden = new Set(chosen).size === chosen.length;
    }
    [...selects, j1, j2].forEach(s => s.addEventListener('change', sync));
    form.addEventListener('submit', e => {
        const chosen = selects.map(s => s.value).filter(Boolean);
        if (new Set(chosen).size !== chosen.length) { e.preventDefault(); alertBox.hidden = false; }
    });
    sync();
})();
</script>
<?php endif; ?>

<?php /* ═════════════ Modal : génération automatique ═════════════ */ ?>
<?php if ($action === 'generer'):
    $selJ = array_map('intval', $oldGen['joueurs_participants'] ?? []);
    $selA = array_map('intval', $oldGen['arbitres_presents'] ?? []);
?>
<dialog id="form-modal" class="modal modal-form modal-wide" aria-labelledby="modal-generer-title">
    <form method="post" action="combats.php" novalidate id="gen-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="generer_auto">
        <div class="modal-header">
            <h3 id="modal-generer-title">Générer le tournoi</h3>
            <a href="combats.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <p class="hint">Forme les paires selon les règles d'appariement (poids, âge, grade, sexe) des paramètres, les place au 1er tour de l'arbre et répartit les arbitres.</p>

            <fieldset class="js-pick">
                <legend>Joueurs</legend>
                <?php if (!$joueurs): ?>
                    <p>Aucun joueur. <a href="joueurs.php">Ajoutez des joueurs</a> d'abord.</p>
                <?php else: ?>
                    <div class="pick-head">
                        <input type="search" class="js-pick-search" placeholder="Rechercher un joueur" aria-label="Rechercher un joueur">
                        <button type="button" class="btn btn-secondary btn-sm js-pick-all">Tout sélectionner</button>
                        <button type="button" class="btn btn-secondary btn-sm js-pick-none">Aucun</button>
                        <button type="button" class="btn btn-ghost btn-sm js-pick-invert">Inverser</button>
                        <span class="pick-count js-pick-count"></span>
                    </div>
                    <div class="pick-list">
                        <?php foreach ($joueurs as $j): ?>
                            <label data-search="<?= e(mb_strtolower($j['pseudo'] . ' ' . ($j['equipe'] ?? '') . ' ' . ($j['grade_nom'] ?? ''))) ?>">
                                <input type="checkbox" name="joueurs_participants[]" value="<?= (int)$j['id'] ?>" <?= in_array((int)$j['id'], $selJ, true) ? 'checked' : '' ?>>
                                <?= e($j['pseudo']) ?>
                                <small><?= $j['poids'] ? number_format($j['poids'], 0) . ' kg' : '' ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset class="js-pick">
                <legend>Arbitres présents</legend>
                <?php if (!$arbitresDisponibles): ?>
                    <p>Aucun arbitre actif. <a href="users.php">Ajoutez des arbitres</a> d'abord.</p>
                <?php else: ?>
                    <div class="pick-head">
                        <input type="search" class="js-pick-search" placeholder="Rechercher un arbitre" aria-label="Rechercher un arbitre">
                        <button type="button" class="btn btn-secondary btn-sm js-pick-all">Tout sélectionner</button>
                        <button type="button" class="btn btn-secondary btn-sm js-pick-none">Aucun</button>
                        <button type="button" class="btn btn-ghost btn-sm js-pick-invert">Inverser</button>
                        <span class="pick-count js-pick-count"></span>
                    </div>
                    <div class="pick-list">
                        <?php foreach ($arbitresDisponibles as $a): ?>
                            <label data-search="<?= e(mb_strtolower($a['username'] . ' ' . ($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''))) ?>">
                                <input type="checkbox" name="arbitres_presents[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $selA, true) ? 'checked' : '' ?>>
                                <?= e($a['username']) ?>
                                <small><?= e(nomRole($pdo, $a['role'])) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>Planning</legend>
                <div class="grid grid-3">
                    <div class="field">
                        <label for="heure_debut">1er combat à</label>
                        <input type="datetime-local" id="heure_debut" name="heure_debut" value="<?= e($oldGen['heure_debut'] ?? $defDebut) ?>">
                    </div>
                    <div class="field">
                        <label for="intervalle_min">Minutes entre deux combats</label>
                        <input type="number" id="intervalle_min" name="intervalle_min" min="1" step="1" value="<?= e((string)($oldGen['intervalle_min'] ?? $defIntervalle)) ?>">
                    </div>
                    <div class="field">
                        <label for="gen_duree">Durée d'un combat</label>
                        <select id="gen_duree" name="duree_secondes">
                            <?php $dd = (int)($oldGen['duree_secondes'] ?? getParametreInt($pdo, 'duree_combat_defaut', 180));
                            foreach (array_unique(array_merge([60, 90, 120, 150, 180, 240, 300, 360, 480, 600], [$dd])) as $d): ?>
                                <option value="<?= $d ?>" <?= $d === $dd ? 'selected' : '' ?>><?= formatDuree($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="plan-preview js-plan-preview" aria-live="polite"></p>
                <p class="hint">Videz « 1er combat à » pour créer les combats sans horaire (démarrage manuel).</p>
            </fieldset>

            <label class="check-row">
                <input type="checkbox" name="vider_arbre_existant" value="1" <?= !empty($oldGen['vider_arbre_existant']) ? 'checked' : '' ?>>
                Remplacer les combats à venir déjà placés dans l'arbre
            </label>
        </div>
        <div class="modal-footer">
            <a href="combats.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn">Générer les combats</button>
        </div>
    </form>
</dialog>
<script nonce="<?= e(cspNonce()) ?>">
(function () {
    document.querySelectorAll('.js-pick').forEach(box => {
        const labels = () => Array.from(box.querySelectorAll('.pick-list label'));
        const visibles = () => labels().filter(l => !l.classList.contains('hidden')).map(l => l.querySelector('input'));
        const count = box.querySelector('.js-pick-count');
        const maj = () => {
            if (!count) return;
            const n = labels().filter(l => l.querySelector('input').checked).length;
            count.textContent = n + ' sur ' + labels().length;
            document.dispatchEvent(new Event('pick-change'));
        };
        const search = box.querySelector('.js-pick-search');
        if (search) search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            labels().forEach(l => l.classList.toggle('hidden', q !== '' && !l.dataset.search.includes(q)));
        });
        box.querySelector('.js-pick-all')?.addEventListener('click', () => { visibles().forEach(i => i.checked = true); maj(); });
        box.querySelector('.js-pick-none')?.addEventListener('click', () => { visibles().forEach(i => i.checked = false); maj(); });
        box.querySelector('.js-pick-invert')?.addEventListener('click', () => { visibles().forEach(i => i.checked = !i.checked); maj(); });
        box.addEventListener('change', maj);
        maj();
    });

    // Aperçu du planning
    const prev = document.querySelector('.js-plan-preview');
    const debut = document.getElementById('heure_debut'), inter = document.getElementById('intervalle_min');
    function apercu() {
        const nbJ = document.querySelectorAll('input[name="joueurs_participants[]"]:checked').length;
        const nbC = Math.floor(nbJ / 2);
        if (!prev) return;
        if (!debut.value || nbC === 0) { prev.textContent = nbC ? nbC + ' combat(s) sans horaire.' : ''; return; }
        const d = new Date(debut.value);
        const fin = new Date(d.getTime() + (nbC - 1) * (parseInt(inter.value, 10) || 1) * 60000);
        const hm = x => String(x.getHours()).padStart(2, '0') + ':' + String(x.getMinutes()).padStart(2, '0');
        prev.textContent = 'Environ ' + nbC + ' combat(s), de ' + hm(d) + ' à ' + hm(fin) + ' (dernier début).';
    }
    [debut, inter].forEach(el => el && el.addEventListener('input', apercu));
    document.addEventListener('pick-change', apercu);
    apercu();
})();
</script>
<?php endif; ?>

<?= scriptJs('live.js') ?>
<script nonce="<?= e(cspNonce()) ?>">
(function () {
    const form = document.getElementById('lot-form');
    const bar = document.getElementById('bulk-bar');
    const checkAll = document.getElementById('check-all');
    const actionInput = document.getElementById('lot-action');
    const labels = { demarrer: 'Démarrer', terminer: 'Terminer', supprimer: 'Supprimer' };
    const cible = n => n > 1 ? `ces ${n} combats` : 'ce combat';
    const questions = {
        demarrer: n => [`Démarrer ${cible(n)} maintenant ?`, 'Le chrono démarre tout de suite pour les arbitres et les spectateurs.'],
        terminer: n => [`Terminer ${cible(n)} maintenant ?`, 'Le score actuel devient le résultat définitif.'],
        supprimer: n => [`Supprimer ${cible(n)} ?`, 'Suppression définitive. Les statistiques des joueurs seront recalculées.'],
    };
    const types = { demarrer: 'success', terminer: 'warning', supprimer: 'danger' };

    const checks = () => Array.from(document.querySelectorAll('.js-row-check'));
    function majBarre() {
        if (!bar) return;
        const n = checks().filter(c => c.checked).length;
        bar.classList.toggle('show', n > 0);
        document.getElementById('bulk-count').textContent = n;
        if (checkAll) {
            checkAll.checked = n > 0 && n === checks().length;
            checkAll.indeterminate = n > 0 && n < checks().length;
        }
    }
    async function envoyer(action, ids) {
        const [q, t] = questions[action](ids.length);
        const ok = await window.Modal.show({ message: q, text: t, confirmLabel: labels[action], type: types[action] });
        if (!ok) return;
        checks().forEach(c => { c.checked = ids.includes(c.value); });
        if (!checks().length) ids.forEach(id => { const h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h); });
        actionInput.value = action;
        form.submit();
    }

    document.addEventListener('change', e => {
        if (e.target === checkAll) checks().forEach(c => c.checked = checkAll.checked);
        if (e.target === checkAll || e.target.classList.contains('js-row-check')) majBarre();
    });
    document.addEventListener('click', e => {
        const lot = e.target.closest('[data-lot]');
        if (lot) envoyer(lot.dataset.lot, checks().filter(c => c.checked).map(c => c.value));
        const one = e.target.closest('[data-one]');
        if (one) {
            const ids = [one.dataset.id];
            // action sur une seule ligne : on ignore la sélection en cours
            const h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = one.dataset.id;
            const [q, t] = questions[one.dataset.one](1);
            window.Modal.show({ message: q, text: t, confirmLabel: labels[one.dataset.one], type: types[one.dataset.one] })
                .then(ok => {
                    if (!ok) return;
                    checks().forEach(c => { c.checked = false; c.disabled = true; });
                    form.appendChild(h);
                    actionInput.value = one.dataset.one;
                    form.submit();
                });
        }
    });
    document.getElementById('bulk-clear')?.addEventListener('click', () => { checks().forEach(c => c.checked = false); majBarre(); });

    // Mise à jour en direct du tableau (sans recharger la page ni perdre la sélection)
    const state = document.getElementById('live-state');
    async function rafraichir() {
        if (document.hidden || document.querySelector('dialog[open], details.menu[open]')) return;
        try {
            const r = await fetch(window.location.href, { cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } });
            if (!r.ok || r.redirected) throw new Error();
            const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            const nb = doc.getElementById('combats-body');
            const cur = document.getElementById('combats-body');
            if (!nb || !cur) return;
            const coches = new Set(checks().filter(c => c.checked).map(c => c.value));
            if (nb.innerHTML !== cur.innerHTML) {
                cur.innerHTML = nb.innerHTML;
                checks().forEach(c => { c.checked = coches.has(c.value); });
                majBarre();
                ['.segmented', '.page-head .sub'].forEach(sel => {
                    const a = document.querySelector(sel), b = doc.querySelector(sel);
                    if (a && b) a.innerHTML = b.innerHTML;
                });
            }
            state.classList.remove('is-error');
            state.textContent = 'Mis à jour à ' + new Date(window.serverNow() + (window.SABRE_CONFIG.tzOffsetMs || 0)).toISOString().substr(11, 8);
        } catch (_) {
            state.classList.add('is-error');
            state.textContent = 'Connexion perdue, nouvelle tentative…';
        }
    }
    setInterval(rafraichir, 5000);

    // Décompte local des chronos du tableau
    setInterval(() => {
        document.querySelectorAll('#combats-body .js-timer').forEach(t => {
            let r = parseInt(t.dataset.remaining || '0', 10);
            if (r > 0 && t.dataset.paused !== '1') { r--; t.dataset.remaining = r; t.textContent = String(Math.floor(r / 60)).padStart(2, '0') + ':' + String(r % 60).padStart(2, '0'); }
        });
    }, 1000);
    majBarre();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
