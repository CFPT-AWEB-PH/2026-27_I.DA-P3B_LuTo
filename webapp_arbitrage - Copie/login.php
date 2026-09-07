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
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $result = attemptLogin($pdo, $username, $password);
        if ($result['success']) {
            flash('success', 'Bienvenue ' . $username . ' !');
            redirect('index.php');
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Connexion';
require __DIR__ . '/includes/header.php';
?>

<div style="max-width:420px;margin-inline:auto;">
    <h1 style="text-align:center;border:none;margin-bottom:var(--space-2);"><?= icon('login') ?> Connexion</h1>
    <p style="text-align:center;margin-bottom:var(--space-5);color:var(--text-dim);">Accédez à votre espace arbitrage.</p>

    <div class="card">
        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert') ?> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <div class="field">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" inputmode="text"
                       value="<?= e($_POST['username'] ?? '') ?>"
                       placeholder="votre_pseudo">
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="input-with-btn">
                    <input type="password" id="password" name="password" required
                           autocomplete="current-password" placeholder="••••••••">
                    <button type="button" class="password-toggle-btn"
                            data-target="password"
                            aria-label="Afficher le mot de passe">
                        <svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-eye"></use></svg>
                    </button>
                </div>
            </div>

            <button type="submit" style="width:100%;min-height:3rem;margin-top:var(--space-2);"><?= icon('login') ?> Se connecter</button>
        </form>

        <p style="margin-top:var(--space-4);text-align:center;font-family:var(--font-ui);font-size:var(--fs-sm);">
            Pas encore de compte ? <a href="register.php" style="font-weight:700;color:var(--accent);">Créer un compte</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
