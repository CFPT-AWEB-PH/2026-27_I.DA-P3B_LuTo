<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$sexesValides = ['M', 'F', 'Autre'];
$grades = getGrades($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $errors[] = 'Session expirée, veuillez réessayer.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');

        $profil = [
            'sexe' => in_array($_POST['sexe'] ?? '', $sexesValides, true) ? $_POST['sexe'] : null,
            'date_naissance' => $_POST['date_naissance'] ?: null,
            'poids' => ($_POST['poids'] ?? '') !== '' ? (float)$_POST['poids'] : null,
            'grade_id' => ($_POST['grade_id'] ?? '') !== '' ? (int)$_POST['grade_id'] : null,
        ];

        if ($password !== $password2) {
            $errors[] = 'Les deux mots de passe ne correspondent pas.';
        } elseif ($profil['date_naissance'] && strtotime($profil['date_naissance']) > time()) {
            $errors[] = 'La date de naissance ne peut pas être dans le futur.';
        } else {
            // Un compte créé publiquement est toujours de type "joueur". Le profil
            // (sexe, date de naissance, poids, grade) est optionnel mais, s'il est
            // renseigné ici, l'administrateur n'aura pas besoin de le ressaisir.
            $result = registerUser($pdo, $username, $password, $nom, $prenom, 'joueur', $profil);
            if ($result['success']) {
                $loginResult = attemptLogin($pdo, $username, $password);
                if ($loginResult['success']) {
                    flash('success', 'Compte créé avec succès, bienvenue ' . $username . ' !');
                    redirect('index.php');
                } else {
                    // Le compte a bien été créé, mais la connexion automatique a
                    // échoué (cas rare) : on l'indique clairement plutôt que de
                    // renvoyer vers l'accueil avec une nav qui affiche "Connexion"
                    // sans explication.
                    flash('success', 'Compte créé avec succès. Merci de te connecter.');
                    redirect('login.php');
                }
            } else {
                $errors[] = $result['error'];
            }
        }
    }
}

$pageTitle = 'Créer un compte';
require __DIR__ . '/includes/header.php';
?>

<h1>Créer un compte</h1>

<div class="card" style="max-width: 560px;">
    <?php if ($errors): ?>
        <div class="flash flash-error" role="alert">
            <?= implode('<br>', array_map('e', $errors)) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php" class="wide" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

        <div class="field">
            <label for="username">Nom d'utilisateur (unique) *</label>
            <input type="text" id="username" name="username" required minlength="3" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
            <small class="hint">Lettres, chiffres, "_", "." et "-" uniquement.</small>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="prenom">Prénom</label>
                <input type="text" id="prenom" name="prenom" autocomplete="given-name" value="<?= e($_POST['prenom'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" autocomplete="family-name" value="<?= e($_POST['nom'] ?? '') ?>">
            </div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="password">Mot de passe *</label>
                <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="password2">Confirmer le mot de passe *</label>
                <input type="password" id="password2" name="password2" required minlength="6" autocomplete="new-password">
            </div>
        </div>

        <h3 style="margin-top: var(--space-2);">Profil de combattant (optionnel)</h3>
        <p style="margin: 0 0 var(--space-2); font-size: var(--fs-sm);">Ces informations évitent à l'administrateur de devoir les ressaisir pour toi.</p>

        <div class="grid grid-2">
            <div class="field">
                <label for="sexe">Sexe</label>
                <select id="sexe" name="sexe">
                    <option value="">-- Ne se prononce pas --</option>
                    <option value="M" <?= (($_POST['sexe'] ?? '') === 'M') ? 'selected' : '' ?>>Homme</option>
                    <option value="F" <?= (($_POST['sexe'] ?? '') === 'F') ? 'selected' : '' ?>>Femme</option>
                    <option value="Autre" <?= (($_POST['sexe'] ?? '') === 'Autre') ? 'selected' : '' ?>>Autre</option>
                </select>
            </div>
            <div class="field">
                <label for="date_naissance">Date de naissance</label>
                <input type="date" id="date_naissance" name="date_naissance" max="<?= date('Y-m-d') ?>" value="<?= e($_POST['date_naissance'] ?? '') ?>">
                <small class="hint">Ton âge sera calculé automatiquement.</small>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="field">
                <label for="poids">Poids (kg)</label>
                <input type="number" id="poids" name="poids" min="0" step="0.1" inputmode="decimal" value="<?= e($_POST['poids'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="grade_id">Grade actuel</label>
                <select id="grade_id" name="grade_id">
                    <option value="">-- Non renseigné --</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= (($_POST['grade_id'] ?? '') == $g['id']) ? 'selected' : '' ?>><?= e($g['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit">Créer mon compte</button>
    </form>

    <p style="margin-top: var(--space-4);">Déjà un compte ? <a href="login.php">Se connecter</a></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
