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
        // Rôle imposé côté serveur : une inscription publique ne crée jamais que des comptes « joueur »
        $mdp = genererMotDePasse($old['username']);
        $result = registerUser($pdo, $old['username'], $mdp, '', '', 'joueur', ['pseudo' => $old['pseudo']]);
        if ($result['success']) {
            flashIdentifiants('Compte créé.', $old['username'], $mdp);
            redirect('login.php');
        }
        $error = $result['error'];
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

            <p class="hint">Votre mot de passe est généré automatiquement et s'affiche après la création du compte : notez-le.</p>

            <button type="submit" class="btn btn-lg btn-block">Créer mon compte</button>
        </form>

        <p class="auth-foot">Déjà un compte ? <a href="login.php">Se connecter</a></p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
