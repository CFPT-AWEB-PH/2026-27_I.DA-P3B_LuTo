<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = null;
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } else {
        $old = $_POST;
        $result = registerUser($pdo, $_POST);
        if ($result['success']) {
            flash('success', 'Compte créé ! Vous pouvez maintenant vous connecter.');
            redirect('login.php');
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Créer un compte';
require __DIR__ . '/includes/header.php';
?>

<div style="max-width:480px;margin-inline:auto;">
    <h1 style="text-align:center;border:none;margin-bottom:var(--space-2);"><?= icon('user') ?> Créer un compte</h1>
    <p style="text-align:center;margin-bottom:var(--space-5);color:var(--text-dim);">Rejoignez le tournoi en quelques secondes.</p>

    <div class="card">
        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert') ?> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

            <?php /* ── Identifiants ── */ ?>
            <div class="field">
                <label for="username">Nom d'utilisateur <span aria-hidden="true" style="color:var(--danger);">*</span></label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" minlength="3" maxlength="30"
                       value="<?= e($old['username'] ?? '') ?>"
                       placeholder="ex. DarkVader42">
                <div class="field-error-msg" role="alert"></div>
                <small class="hint">3 à 30 caractères</small>
            </div>

            <div class="field">
                <label for="password">Mot de passe <span aria-hidden="true" style="color:var(--danger);">*</span></label>
                <div class="input-with-btn">
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password" minlength="8"
                           placeholder="••••••••">
                    <button type="button" class="password-toggle-btn"
                            data-target="password"
                            aria-label="Afficher le mot de passe">
                        <svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-eye"></use></svg>
                    </button>
                </div>
                <div class="field-error-msg" role="alert"></div>
                <small class="hint">8 caractères minimum</small>
            </div>

            <div class="field">
                <label for="password_confirm">Confirmer le mot de passe <span aria-hidden="true" style="color:var(--danger);">*</span></label>
                <div class="input-with-btn">
                    <input type="password" id="password_confirm" name="password_confirm" required
                           autocomplete="new-password" minlength="8"
                           data-match="password"
                           placeholder="••••••••">
                    <button type="button" class="password-toggle-btn"
                            data-target="password_confirm"
                            aria-label="Afficher la confirmation">
                        <svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-eye"></use></svg>
                    </button>
                </div>
                <div class="field-error-msg" role="alert"></div>
            </div>

            <?php /* ── Profil optionnel ── */ ?>
            <details class="form-section">
                <summary>
                    <?= icon('user') ?>
                    <span>Profil de combattant <em>(optionnel)</em></span>
                    <svg class="icon summary-arrow" aria-hidden="true" focusable="false"><use href="#icon-chevron-right"></use></svg>
                </summary>
                <div class="form-section-body">
                    <div class="field">
                        <label for="pseudo">Pseudo de combat</label>
                        <input type="text" id="pseudo" name="pseudo" maxlength="40"
                               value="<?= e($old['pseudo'] ?? '') ?>"
                               placeholder="ex. Le Maître des Sabres">
                    </div>
                    <div class="field">
                        <label for="club">Club</label>
                        <input type="text" id="club" name="club" maxlength="60"
                               value="<?= e($old['club'] ?? '') ?>"
                               placeholder="Nom de votre club">
                    </div>
                    <div class="field">
                        <label for="categorie">Catégorie</label>
                        <select id="categorie" name="categorie">
                            <option value="">— Sélectionner —</option>
                            <option value="benjamin" <?= ($old['categorie'] ?? '') === 'benjamin' ? 'selected' : '' ?>>Benjamin</option>
                            <option value="minime"   <?= ($old['categorie'] ?? '') === 'minime'   ? 'selected' : '' ?>>Minime</option>
                            <option value="cadet"    <?= ($old['categorie'] ?? '') === 'cadet'    ? 'selected' : '' ?>>Cadet</option>
                            <option value="junior"   <?= ($old['categorie'] ?? '') === 'junior'   ? 'selected' : '' ?>>Junior</option>
                            <option value="senior"   <?= ($old['categorie'] ?? '') === 'senior'   ? 'selected' : '' ?>>Senior</option>
                            <option value="veteran"  <?= ($old['categorie'] ?? '') === 'veteran'  ? 'selected' : '' ?>>Vétéran</option>
                        </select>
                    </div>
                </div>
            </details>

            <button type="submit" style="width:100%;min-height:3rem;margin-top:var(--space-3);"><?= icon('user') ?> Créer mon compte</button>
        </form>

        <p style="margin-top:var(--space-4);text-align:center;font-family:var(--font-ui);font-size:var(--fs-sm);">
            Déjà un compte ? <a href="login.php" style="font-weight:700;color:var(--accent);">Se connecter</a>
        </p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
