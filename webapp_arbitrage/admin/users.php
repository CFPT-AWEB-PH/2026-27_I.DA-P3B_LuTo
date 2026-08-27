<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$roles = ['admin', 'arbitre', 'joueur', 'public'];

// ---- CREATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée.');
        redirect('admin/users.php');
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'joueur';
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');

    $result = registerUser($pdo, $username, $password ?: bin2hex(random_bytes(4)), $nom, $prenom, $role);
    if ($result['success']) {
        flash('success', 'Utilisateur "' . $username . '" créé (rôle : ' . $role . ').');
        redirect('admin/users.php');
    } else {
        flash('error', $result['error']);
        stashOldInput($_POST);
        redirect('admin/users.php?action=new');
    }
}

// ---- UPDATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée.');
        redirect('admin/users.php');
    }
    $editId = (int)$_POST['id'];
    $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'joueur';
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $actif = isset($_POST['actif']) ? 1 : 0;

    $pdo->prepare('UPDATE users SET role = ?, nom = ?, prenom = ?, actif = ? WHERE id = ?')
        ->execute([$role, $nom ?: null, $prenom ?: null, $actif, $editId]);

    if (!empty($_POST['new_password'])) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $editId]);
    }

    flash('success', 'Utilisateur mis à jour.');
    redirect('admin/users.php');
}

// ---- DELETE ----
if ($action === 'delete' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        if ($id === (int)currentUser()['id']) {
            flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
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

$editUser = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $editUser = $stmt->fetch();
}

$old = oldInput();
if ($old && in_array($action, ['new', 'edit'], true)) {
    $editUser = array_merge($editUser ?? [], $old);
}

$users = $pdo->query('SELECT * FROM users ORDER BY FIELD(role,"admin","arbitre","joueur","public"), username ASC')->fetchAll();

$pageTitle = 'Gestion des utilisateurs';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<h1>Utilisateurs &amp; Arbitres</h1>

<?php if (in_array($action, ['new', 'edit'])): ?>
    <div class="card" style="max-width:480px;">
        <h3><?= $action === 'new' ? 'Nouvel utilisateur' : 'Modifier l\'utilisateur' ?></h3>
        <form method="post" action="users.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
            <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>

            <?php if ($action === 'new'): ?>
                <div class="field">
                    <label for="username">Nom d'utilisateur (unique) *</label>
                    <input type="text" id="username" name="username" required minlength="3" autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="password">Mot de passe (laisser vide pour générer automatiquement)</label>
                    <input type="text" id="password" name="password" minlength="6" autocomplete="new-password">
                </div>
            <?php else: ?>
                <div class="field">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" value="<?= e($editUser['username']) ?>" disabled>
                </div>
                <div class="field">
                    <label for="new_password">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                    <input type="text" id="new_password" name="new_password" minlength="6" autocomplete="new-password">
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="role">Rôle *</label>
                <select id="role" name="role">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>" <?= (($editUser['role'] ?? '') === $r) ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="hint">central / coin1 / coin2 sont assignés lors de la création d'un combat.</small>
            </div>
            <div class="field">
                <label for="prenom">Prénom</label>
                <input type="text" id="prenom" name="prenom" autocomplete="given-name" value="<?= e($editUser['prenom'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" autocomplete="family-name" value="<?= e($editUser['nom'] ?? '') ?>">
            </div>
            <?php if ($editUser): ?>
                <div class="field">
                    <label class="checkbox-label" for="actif"><input type="checkbox" id="actif" name="actif" <?= $editUser['actif'] ? 'checked' : '' ?>> Compte actif</label>
                </div>
            <?php endif; ?>
            <div class="btn-row">
                <button type="submit">Enregistrer</button>
                <a href="users.php" class="btn btn-secondary">Annuler</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <p class="btn-row"><a href="users.php?action=new" class="btn">+ Nouvel utilisateur</a></p>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Liste de tous les utilisateurs et leur rôle</caption>
        <thead><tr><th scope="col">Utilisateur</th><th scope="col">Rôle</th><th scope="col">Nom complet</th><th scope="col">Actif</th><th scope="col">Créé le</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge" style="background:rgba(255,255,255,0.08); color:var(--text-dim); border-color:var(--border);"><?= e($u['role']) ?></span></td>
                <td><?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?: '—' ?></td>
                <td><?= $u['actif'] ? icon('check-circle', 'text-success-icon') : icon('x-circle', 'text-danger-icon') ?><span class="visually-hidden"><?= $u['actif'] ? 'Actif' : 'Inactif' ?></span></td>
                <td><?= formatDateFr($u['created_at']) ?></td>
                <td class="actions-inline">
                    <a href="users.php?action=edit&id=<?= $u['id'] ?>" class="btn btn-secondary">Éditer<span class="visually-hidden"> <?= e($u['username']) ?></span></a>
                    <a href="users.php?action=delete&id=<?= $u['id'] ?>&csrf=<?= e(csrfToken()) ?>"
                       class="btn btn-danger"
                       onclick="return confirm('Supprimer l\'utilisateur <?= e(addslashes($u['username'])) ?> ?');">Supprimer<span class="visually-hidden"> <?= e($u['username']) ?></span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
