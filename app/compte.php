<?php
/**
 * compte.php : mon compte (identifiant, rôle). Les mots de passe sont générés par
 * l'application et ne se changent pas ici : en cas d'oubli, un administrateur le
 * retrouve dans le guide. Seul un compte encore sur un mot de passe par défaut, ou
 * marqué « à renouveler », passe par cette page pour en recevoir un nouveau.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();

$user = currentUser();
$error = null;
$force = !empty($_SESSION['doit_changer_mdp']);

if ($force && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expirée, veuillez réessayer.';
    } elseif (!limiteDebit($pdo, 'changement_mdp', (string)$user['id'], 10, 900)) {
        $error = 'Trop de tentatives. Patientez 15 minutes.';
    } else {
        $mdp = definirMotDePasseCompte($pdo, (int)$user['id'], $user['username']);
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        ouvrirSessionUtilisateur($st->fetch());
        error_log('[Sabre][auth] mot de passe généré user=' . $user['username'] . ' ip=' . ipClient());
        flashIdentifiants('Votre nouveau mot de passe est prêt.', $user['username'], $mdp);
        redirect(peutAccederAdmin() ? 'admin/index.php' : 'index.php');
    }
}

$pageTitle = 'Mon compte';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
    <div class="card auth-card">
        <div class="auth-mark" aria-hidden="true"><span class="avatar avatar-lg"><?= e(mb_substr($user['username'], 0, 1)) ?></span></div>
        <h1>Mon compte</h1>
        <p class="sub">Identifiant : <strong><?= e($user['username']) ?></strong> · <?= e(nomRole($pdo, $user['role'])) ?></p>

        <?php if ($error): ?>
            <div class="flash flash-error" role="alert"><?= icon('alert-triangle') ?><span class="flash-body"><?= e($error) ?></span></div>
        <?php endif; ?>

        <?php if ($force): ?>
            <div class="flash flash-warning" role="alert"><?= icon('lock') ?><span class="flash-body">Par sécurité, votre mot de passe doit être renouvelé : l'application va en générer un et vous l'afficher.</span></div>
            <form method="post" action="compte.php" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="btn btn-lg btn-block">Générer mon mot de passe</button>
            </form>
        <?php else: ?>
            <p class="hint">Votre mot de passe est géré par l'organisation et ne peut pas être changé. Oublié ? Demandez-le à un administrateur.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
