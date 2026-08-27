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
            flash('success', 'Connexion réussie. Bienvenue ' . $username . ' !');
            redirect('index.php');
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Connexion';
require __DIR__ . '/includes/header.php';
?>

<h1>Connexion</h1>

<div class="card" style="max-width: 420px;">
    <?php if ($error): ?>
        <div class="flash flash-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

        <div class="field">
            <label for="username">Nom d'utilisateur</label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit">Se connecter</button>
    </form>

    <p style="margin-top: var(--space-4);">Pas encore de compte ? <a href="register.php">Créer un compte</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
