<?php
/**
 * compte.php : mon compte (changement de mon mot de passe).
 * Obligatoire à la première connexion avec un mot de passe par défaut
 * ou après une réinitialisation par un administrateur.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();

$user = currentUser();
$error = null;
$force = !empty($_SESSION['doit_changer_mdp']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actuel = is_string($_POST['mdp_actuel'] ?? null) ? $_POST['mdp_actuel'] : '';
    $nouveau = is_string($_POST['mdp_nouveau'] ?? null) ? $_POST['mdp_nouveau'] : '';
    $confirm = is_string($_POST['mdp_confirm'] ?? null) ? $_POST['mdp_confirm'] : '';

    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } elseif (!limiteDebit($pdo, 'changement_mdp', (string)$user['id'], 10, 900)) {
        $error = 'Trop de tentatives. Patientez 15 minutes.';
    } else {
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $hash = (string)$st->fetchColumn();
        if (!password_verify($actuel, $hash)) {
            $error = 'Le mot de passe actuel est incorrect.';
        } elseif (!hash_equals($nouveau, $confirm)) {
            $error = 'Les deux nouveaux mots de passe ne correspondent pas.';
        } elseif (password_verify($nouveau, $hash)) {
            $error = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        } elseif ($err = erreurMotDePasse($nouveau, $user['username'])) {
            $error = $err;
        } else {
            // Nouveau hash + révocation de toutes les autres sessions de ce compte
            $pdo->prepare('UPDATE users SET password_hash = ?, doit_changer_mdp = 0, session_version = session_version + 1 WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $user['id']]);
            $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$user['id']]);
            ouvrirSessionUtilisateur($st->fetch());
            error_log('[Sabre][auth] mot de passe changé user=' . $user['username'] . ' ip=' . ipClient());
            flash('success', 'Mot de passe changé. Vos autres sessions ont été déconnectées.');
            redirect(peutAccederAdmin() ? 'admin/index.php' : 'index.php');
        }
    }
}

$pageTitle = 'Mon compte';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <div class="auth-mark" aria-hidden="true"><span class="avatar avatar-lg"><?= e(mb_substr($user['username'], 0, 1)) ?></span></div>
        <h1>Changer de mot de passe</h1>
        <p class="sub">Connecté en tant que <strong><?= e($user['username']) ?></strong> · <?= e(nomRole($pdo, $user['role'])) ?></p>

        <?php if ($force): ?>
            <div class="flash flash-warning" role="alert"><?= icon('lock') ?><span class="flash-body">Par sécurité, choisissez un nouveau mot de passe avant de continuer.</span></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert-triangle') ?><span class="flash-body"><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="post" action="compte.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <div class="field">
                <label for="mdp_actuel">Mot de passe actuel</label>
                <div class="input-with-btn">
                    <input type="password" id="mdp_actuel" name="mdp_actuel" required autocomplete="current-password" maxlength="200" autofocus>
                    <button type="button" class="password-toggle-btn" data-target="mdp_actuel" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
                </div>
            </div>

            <div class="field">
                <label for="mdp_nouveau">Nouveau mot de passe</label>
                <div class="input-with-btn">
                    <input type="password" id="mdp_nouveau" name="mdp_nouveau" required minlength="8" maxlength="200" autocomplete="new-password" aria-describedby="mdp-hint">
                    <button type="button" class="password-toggle-btn" data-target="mdp_nouveau" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
                </div>
                <small class="hint" id="mdp-hint">8 caractères minimum, sans votre nom d'utilisateur.</small>
            </div>

            <div class="field">
                <label for="mdp_confirm">Confirmer le nouveau mot de passe</label>
                <input type="password" id="mdp_confirm" name="mdp_confirm" required minlength="8" maxlength="200" autocomplete="new-password" data-match="mdp_nouveau">
            </div>

            <button type="submit" class="btn btn-lg btn-block">Enregistrer le nouveau mot de passe</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
