<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $result = attemptLogin($pdo, $username, $password);
        if ($result['success']) {
            flash('success', 'Bienvenue ' . currentUser()['username'] . '.');
            // Chaque profil arrive directement là où il travaille
            if (can('arbitrage.acces') && !estSuperAdmin()) redirect('arbitrage/index.php');
            if (peutAccederAdmin()) redirect('admin/index.php');
            redirect('index.php');
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Connexion';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <div class="auth-mark" aria-hidden="true"><?= marqueHtml($pdo) ?></div>
        <h1>Connexion</h1>
        <p class="sub">Pour les arbitres et l'organisation.<br>Aucun compte n'est nécessaire pour suivre les combats.</p>

        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert-triangle') ?><span class="flash-body"><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <div class="field">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required <?= $error ? '' : 'autofocus' ?>
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       value="<?= e(is_string($_POST['username'] ?? null) ? mb_substr($_POST['username'], 0, 50) : '') ?>">
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="input-with-btn">
                    <input type="password" id="password" name="password" required autocomplete="current-password" <?= $error ? 'autofocus' : '' ?>>
                    <button type="button" class="password-toggle-btn" data-target="password" aria-label="Afficher le mot de passe">
                        <?= icon('eye') ?>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-lg btn-block">Se connecter</button>
        </form>

        <p class="auth-foot">
            <?php if (getParametreInt($pdo, 'inscriptions_ouvertes', 1) === 1): ?>Vous combattez ? <a href="register.php">Créer un compte joueur</a><?php else: ?><a href="index.php">Suivre les combats en direct</a><?php endif; ?>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
