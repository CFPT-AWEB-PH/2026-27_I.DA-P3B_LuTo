<?php
require_once __DIR__ . '/../config/config.php';
requirePermission('users.voir');

$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');

$rolesDispo = getRoles($pdo);
$slugsRoles = array_column($rolesDispo, 'slug');

/** Rôles qu'un utilisateur connecté a le droit d'attribuer (seul un admin peut donner « admin »). */
$rolesAttribuables = array_values(array_filter($rolesDispo, fn($r) => roleSubordonne($pdo, $r['slug'])));
$slugsAttribuables = array_column($rolesAttribuables, 'slug');

/** Un non-admin ne peut pas toucher à un compte administrateur. */
function peutGererCompte(?array $cible): bool
{
    global $pdo;
    if (!$cible) return true;
    return roleSubordonne($pdo, $cible['role']);
}

function chargerUser(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ---- CREATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create') {
    exigerPermissionAction('users.creer', 'admin/users.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée.');
        redirect('admin/users.php');
    }
    $username = texteBorne($_POST['username'] ?? '', 30) ?? '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $defaut = in_array('joueur', $slugsAttribuables, true) ? 'joueur' : ($slugsAttribuables[0] ?? 'public');
    $role = (can('users.changer_role') && in_array($_POST['role'] ?? '', $slugsAttribuables, true)) ? $_POST['role'] : $defaut;
    $nom = texteBorne($_POST['nom'] ?? '', 100) ?? '';
    $prenom = texteBorne($_POST['prenom'] ?? '', 100) ?? '';

    // Mot de passe provisoire : aléatoire (12 caractères), à changer à la 1re connexion
    $motDePasseGenere = $password === '' ? substr(strtr(base64_encode(random_bytes(12)), '+/', 'kx'), 0, 12) : null;
    $result = registerUser($pdo, $username, $password ?: $motDePasseGenere, $nom, $prenom, $role);
    if ($result['success']) {
        // Tout mot de passe fixé par un tiers doit être changé par son titulaire
        $pdo->prepare('UPDATE users SET doit_changer_mdp = 1 WHERE id = ?')->execute([$result['user_id']]);
        $msg = 'Utilisateur "' . $username . '" créé (rôle : ' . nomRole($pdo, $role) . ').';
        if ($motDePasseGenere) $msg .= ' Mot de passe provisoire : ' . $motDePasseGenere . ' (à changer à la première connexion).';
        flash('success', $msg, (bool)$motDePasseGenere);
        redirect('admin/users.php');
    } else {
        flash('error', $result['error']);
        stashOldInput($_POST);
        redirect('admin/users.php?action=new');
    }
}

// ---- UPDATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update') {
    exigerPermissionAction('users.modifier', 'admin/users.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée.');
        redirect('admin/users.php');
    }
    $editId = idPositif($_POST['id'] ?? '');
    $cible = chargerUser($pdo, $editId);
    if (!$cible) { flash('error', 'Utilisateur introuvable.'); redirect('admin/users.php'); }
    if (!peutGererCompte($cible)) {
        flash('error', 'Seul un administrateur peut modifier un compte administrateur.');
        redirect('admin/users.php');
    }

    $role = $cible['role'];
    $estMoi = $editId === (int)currentUser()['id'];
    // On ne change pas son propre rôle (sauf administrateur) : empêche de s'auto-promouvoir
    if (can('users.changer_role') && (!$estMoi || estSuperAdmin()) && in_array($_POST['role'] ?? '', $slugsAttribuables, true)) {
        $role = $_POST['role'];
    }
    $nom = texteBorne($_POST['nom'] ?? '', 100) ?? '';
    $prenom = texteBorne($_POST['prenom'] ?? '', 100) ?? '';
    $actif = isset($_POST['actif']) ? 1 : 0;

    // Garde-fous : ne pas se retirer soi-même le rôle admin / se désactiver
    if ($editId === (int)currentUser()['id']) {
        if ($cible['role'] === ROLE_SUPER_ADMIN && $role !== ROLE_SUPER_ADMIN) {
            $nbAdmins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'admin' AND actif = 1")->fetch()['c'];
            if ($nbAdmins <= 1) {
                flash('error', 'Vous êtes le dernier administrateur : impossible de retirer votre propre rôle admin.');
                redirect('admin/users.php?action=edit&id=' . $editId);
            }
        }
        $actif = 1;
    }

    // Changement de rôle ou désactivation : les sessions ouvertes de ce compte sont révoquées
    $revoquer = ($role !== $cible['role'] || ($actif === 0 && (int)$cible['actif'] === 1)) && !$estMoi;
    $pdo->prepare('UPDATE users SET role = ?, nom = ?, prenom = ?, actif = ?, session_version = session_version + ? WHERE id = ?')
        ->execute([$role, $nom ?: null, $prenom ?: null, $actif, $revoquer ? 1 : 0, $editId]);
    if ($role !== $cible['role']) {
        error_log('[Sabre][admin] rôle de ' . $cible['username'] . ' : ' . $cible['role'] . ' -> ' . $role . ' par ' . currentUser()['username']);
    }

    // Passage au rôle joueur : on crée la fiche joueur si elle n'existe pas encore
    if ($role === 'joueur') {
        $st = $pdo->prepare('SELECT id FROM joueurs WHERE user_id = ?');
        $st->execute([$editId]);
        if (!$st->fetch()) {
            try {
                $pdo->prepare('INSERT INTO joueurs (user_id, pseudo, nom, prenom) VALUES (?, ?, ?, ?)')
                    ->execute([$editId, $cible['username'], $nom ?: null, $prenom ?: null]);
            } catch (PDOException $e) { /* pseudo déjà pris : on ignore */ }
        }
    }

    $nouveauMdp = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    if ($nouveauMdp !== '') {
        if (!can('users.mot_de_passe')) {
            flash('error', 'Mot de passe non modifié : permission « Réinitialiser les mots de passe » manquante.');
        } elseif ($err = erreurMotDePasse($nouveauMdp, $cible['username'])) {
            flash('error', 'Mot de passe non modifié : ' . $err);
        } else {
            // Réinitialisation : sessions révoquées + changement obligatoire à la prochaine connexion
            $pdo->prepare('UPDATE users SET password_hash = ?, doit_changer_mdp = ?, session_version = session_version + 1 WHERE id = ?')
                ->execute([password_hash($nouveauMdp, PASSWORD_DEFAULT), $estMoi ? 0 : 1, $editId]);
            error_log('[Sabre][admin] mot de passe de ' . $cible['username'] . ' réinitialisé par ' . currentUser()['username']);
            if ($estMoi) {
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$editId]);
                ouvrirSessionUtilisateur($st->fetch());
            }
        }
    }

    flash('success', 'Utilisateur mis à jour.');
    redirect('admin/users.php');
}

// ---- DELETE (POST uniquement) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete') {
    exigerPermissionAction('users.supprimer', 'admin/users.php');
    $id = idPositif($_POST['id'] ?? '');
    if (checkCsrf($_POST['csrf'] ?? null)) {
        $cible = chargerUser($pdo, $id);
        if ($id === (int)currentUser()['id']) {
            flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif (!peutGererCompte($cible)) {
            flash('error', 'Seul un administrateur peut supprimer un compte administrateur.');
        } else {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('success', 'Utilisateur supprimé.');
            } catch (PDOException $e) {
                flash('error', 'Impossible de supprimer : cet utilisateur est référencé ailleurs (ex : arbitre assigné à un combat).');
            }
        }
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/users.php');
}

if ($action === 'new' && !can('users.creer')) $action = 'list';
if ($action === 'edit' && !can('users.modifier')) $action = 'list';

$editUser = null;
if ($action === 'edit' && $id) {
    $editUser = chargerUser($pdo, $id);
    if (!$editUser || !peutGererCompte($editUser)) {
        if ($editUser) flash('error', 'Seul un administrateur peut modifier un compte administrateur.');
        redirect('admin/users.php');
    }
}

$old = oldInput();
if ($old && in_array($action, ['new', 'edit'], true)) {
    $editUser = array_merge($editUser ?? [], $old);
}

// Filtre par rôle
$filtreRole = $_GET['role'] ?? '';
if ($filtreRole !== '' && in_array($filtreRole, $slugsRoles, true)) {
    $st = $pdo->prepare('SELECT u.* FROM users u LEFT JOIN roles r ON r.slug = u.role WHERE u.role = ? ORDER BY u.username ASC');
    $st->execute([$filtreRole]);
    $users = $st->fetchAll();
} else {
    $filtreRole = '';
    $users = $pdo->query('SELECT u.* FROM users u LEFT JOIN roles r ON r.slug = u.role ORDER BY COALESCE(r.ordre, 999), u.username ASC')->fetchAll();
}

$pageTitle = 'Gestion des utilisateurs';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Comptes</h1>
        <p class="sub">Arbitres, organisateurs et joueurs qui peuvent se connecter.</p>
    </div>
    <div class="btn-row">
        <?php if (can('roles.voir', 'roles.gerer')): ?><a href="roles.php" class="btn btn-secondary"><?= icon('lock') ?>Gérer les rôles</a><?php endif; ?>
        <?php if (can('users.creer')): ?><a href="users.php?action=new" class="btn"><?= icon('user-plus') ?>Nouveau compte</a><?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <nav class="segmented" aria-label="Filtrer par rôle">
        <a href="users.php" <?= $filtreRole === '' ? 'aria-current="true"' : '' ?>>Tous <span class="count"><?= array_sum(array_column($rolesDispo, 'nb_users')) ?></span></a>
        <?php foreach ($rolesDispo as $r): ?>
            <a href="users.php?role=<?= e($r['slug']) ?>" <?= $filtreRole === $r['slug'] ? 'aria-current="true"' : '' ?>><span class="dot" style="background:<?= e($r['couleur']) ?>"></span><?= e($r['nom']) ?> <span class="count"><?= (int)$r['nb_users'] ?></span></a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if (in_array($action, ['new', 'edit'])): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-form-title">
    <form method="post" action="users.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
        <?php if ($editUser && !empty($editUser['id'])): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>
        <div class="modal-header">
            <h3 class="modal-title" id="modal-form-title">
                <?= icon($action === 'new' ? 'user-plus' : 'edit') ?>
                <?= $action === 'new' ? 'Nouvel utilisateur' : 'Modifier l\'utilisateur' ?>
            </h3>
            <a href="users.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <?php if ($action === 'new'): ?>
                <div class="field">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" required minlength="3" autocomplete="username" value="<?= e($editUser['username'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="password">Mot de passe <span class="req">(facultatif)</span></label>
                    <input type="text" id="password" name="password" minlength="8" maxlength="200" autocomplete="new-password" aria-describedby="password-hint">
                    <small class="hint" id="password-hint">Laissez vide pour en générer un : il s'affichera une fois après l'enregistrement.</small>
                </div>
            <?php else: ?>
                <div class="field">
                    <label>Nom d'utilisateur</label>
                    <input type="text" value="<?= e($editUser['username']) ?>" disabled>
                </div>
                <?php if (can('users.mot_de_passe')): ?>
                <div class="field">
                    <label for="new_password">Nouveau mot de passe <span class="req">(vide = inchangé)</span></label>
                    <input type="text" id="new_password" name="new_password" minlength="8" maxlength="200" autocomplete="new-password">
                </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="field">
                <label for="role">Rôle</label>
                <?php if (can('users.changer_role')): ?>
                    <select id="role" name="role">
                        <?php foreach ($rolesAttribuables as $r): ?>
                            <option value="<?= e($r['slug']) ?>" <?= (($editUser['role'] ?? 'joueur') === $r['slug']) ? 'selected' : '' ?>><?= e($r['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="hint">Les permissions de chaque rôle se configurent dans <a href="roles.php">Rôles &amp; permissions</a>.</small>
                <?php else: ?>
                    <p><?= badgeRole($pdo, $editUser['role'] ?? 'joueur') ?> <small class="hint">(vous n'avez pas la permission de changer le rôle)</small></p>
                <?php endif; ?>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" value="<?= e($editUser['prenom'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="<?= e($editUser['nom'] ?? '') ?>">
                </div>
            </div>
            <?php if ($action === 'edit' && $editUser): ?>
                <div class="field">
                    <label class="switch">
                        <span class="switch-text"><strong>Compte actif</strong><span class="hint">Désactivé, le compte ne peut plus se connecter.</span></span>
                        <input type="checkbox" name="actif" role="switch" <?= !empty($editUser['actif']) ? 'checked' : '' ?>>
                    </label>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="users.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new' ? 'Créer le compte' : 'Enregistrer les modifications' ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="table-wrap">
    <table class="table-cards">
        <caption class="visually-hidden">Comptes utilisateurs et leur rôle</caption>
        <thead>
            <tr>
                <th scope="col">Compte</th><th scope="col">Rôle</th><th scope="col">État</th>
                <th scope="col">Dernière connexion</th><th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): $gerable = peutGererCompte($u); $nomComplet = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
            $menu = '';
            if ($gerable && can('users.modifier')) $menu .= '<a role="menuitem" href="users.php?action=edit&id=' . (int)$u['id'] . '">' . icon('edit') . 'Modifier</a>';
            if ($gerable && can('users.supprimer') && (int)$u['id'] !== (int)currentUser()['id']) {
                $menu .= ($menu ? '<div class="menu-sep" role="separator"></div>' : '') . boutonPost('users.php', 'delete', ['id' => (int)$u['id']], 'Supprimer le compte', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer le compte ' . $u['username'] . ' ?', 'data-confirm-text' => 'Cette personne ne pourra plus se connecter. Action définitive.', 'data-confirm-label' => 'Supprimer']);
            }
        ?>
            <tr class="<?= $u['actif'] ? '' : 'row-inactive' ?>">
                <td class="cell-primary"><div class="player-cell"><strong><?= e($u['username']) ?></strong><span class="hint"><?= $nomComplet !== '' ? e($nomComplet) : 'Nom non renseigné' ?></span></div></td>
                <td data-label=""><?= badgeRole($pdo, $u['role']) ?></td>
                <td data-label=""><?= $u['actif'] ? '<span class="hint">Actif</span>' : '<span class="badge badge-neutral">Désactivé</span>' ?><?= !empty($u['doit_changer_mdp']) ? ' <span class="badge badge-warning" title="Devra choisir un nouveau mot de passe à la prochaine connexion">Mot de passe à changer</span>' : '' ?></td>
                <td data-label="Dernière connexion :" class="tabular"><?= !empty($u['derniere_connexion']) ? formatDateFr($u['derniere_connexion']) : '<span class="muted">jamais</span>' ?></td>
                <td class="cell-actions">
                    <?php if ($menu): ?><?= menuActions($menu, 'Actions pour ' . $u['username']) ?>
                    <?php elseif (!$gerable): ?><span class="hint" title="Compte protégé : rôle supérieur au vôtre"><?= icon('lock') ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
            <tr><td colspan="5"><?= etatVide('Aucun compte avec ce rôle', '', '<a class="btn btn-secondary" href="users.php">Voir tous les comptes</a>', 'users', 'compact') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
