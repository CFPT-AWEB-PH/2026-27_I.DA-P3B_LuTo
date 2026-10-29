<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$ouvert = getParametreInt($pdo, 'inscriptions_ouvertes', 1) === 1;
$error = null;
$old = [];

if ($ouvert && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = ['username' => texteBorne($_POST['username'] ?? '', 30) ?? '', 'pseudo' => texteBorne($_POST['pseudo'] ?? '', 60) ?? ''];
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } elseif (!limiteDebit($pdo, 'inscription', ipClient(), 5, 3600)) {
        $error = 'Trop de comptes créés depuis cette connexion. Réessayez plus tard.';
    } else {
        $mdp = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        if (!hash_equals($mdp, $confirm)) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } else {
            // Rôle imposé côté serveur : une inscription publique ne crée jamais que des comptes « joueur »
            $result = registerUser($pdo, $old['username'], $mdp, '', '', 'joueur', ['pseudo' => $old['pseudo']]);
            if ($result['success']) {
                flash('success', 'Compte créé. Vous pouvez maintenant vous connecter.');
                redirect('login.php');
            }
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Créer un compte';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <div class="auth-mark" aria-hidden="true"><?= marqueHtml($pdo) ?></div>
        <h1>Créer un compte joueur</h1>
        <?php if (!$ouvert): ?>
            <p class="sub">Les inscriptions sont fermées pour ce tournoi. Adressez-vous à l'organisation.</p>
            <a class="btn btn-secondary btn-block" href="index.php">Suivre les combats en direct</a>
            <p class="auth-foot">Déjà un compte ? <a href="login.php">Se connecter</a></p>
        <?php else: ?>
        <p class="sub">Les comptes d'arbitre sont créés par l'organisation.</p>

        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert-triangle') ?><span class="flash-body"><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <div class="field">
                <label for="username">Nom d'utilisateur</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username"
                       autocapitalize="none" spellcheck="false" minlength="3" maxlength="30"
                       pattern="[A-Za-z0-9_.\-]{3,30}" data-pattern-msg="Lettres sans accent, chiffres, « _ », « . » ou « - » uniquement."
                       aria-describedby="username-hint" value="<?= e($old['username'] ?? '') ?>">
                <small class="hint" id="username-hint">3 à 30 caractères : lettres, chiffres, « _ », « . », « - ».</small>
            </div>

            <div class="field">
                <label for="pseudo">Nom de combat <span class="hint">(facultatif)</span></label>
                <input type="text" id="pseudo" name="pseudo" maxlength="60" aria-describedby="pseudo-hint" value="<?= e($old['pseudo'] ?? '') ?>">
                <small class="hint" id="pseudo-hint">Affiché sur les écrans et dans le classement.</small>
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <div class="input-with-btn">
                    <input type="password" id="password" name="password" required minlength="8" maxlength="200" autocomplete="new-password" aria-describedby="password-hint">
                    <button type="button" class="password-toggle-btn" data-target="password" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
                </div>
                <small class="hint" id="password-hint">8 caractères minimum.</small>
            </div>

            <div class="field">
                <label for="password_confirm">Confirmer le mot de passe</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8" maxlength="200" autocomplete="new-password" data-match="password">
            </div>

            <button type="submit" class="btn btn-lg btn-block">Créer mon compte</button>
        </form>

        <p class="auth-foot">Déjà un compte ? <a href="login.php">Se connecter</a></p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
