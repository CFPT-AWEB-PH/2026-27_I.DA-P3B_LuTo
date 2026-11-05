<?php
/**
 * admin/raspberrys.php : écrans des Raspberry. Pour chacun : le compte dont l'identifiant et
 * le mot de passe s'affichent sur son fond d'écran, et une image de fond facultative.
 * Réservé aux administrateurs (includes/raspberry.php explique le fonctionnement).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wifi.php';
require_once __DIR__ . '/../includes/raspberry.php';
requirePermission(PERM_RASPBERRY);
ensureWifiSchema($pdo);
ensureRaspberrySchema($pdo);

$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');

function chargerRaspberry(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nom, principal, user_id, wifi_compte_id, fond_version, derniere_vue FROM raspberrys WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Aperçu de l'image de fond
if ($action === 'apercu' && $id) {
    $st = $pdo->prepare('SELECT fond FROM raspberrys WHERE id = ?');
    $st->execute([$id]);
    $image = $st->fetchColumn();
    if (!$image) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    echo $image;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, merci de réessayer.');
        redirect('admin/raspberrys.php');
    }
    $fa = $_POST['form_action'] ?? '';
    $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';

    // ---- AJOUTER UN RASPBERRY (connecté au Wi-Fi) ----
    if ($fa === 'create') {
        if ($nom === '') {
            flash('error', 'Donnez un nom à ce Raspberry.');
            redirect('admin/raspberrys.php?action=new');
        }
        $pdo->prepare('INSERT INTO raspberrys (nom) VALUES (?)')->execute([$nom]);
        $nouveauId = (int)$pdo->lastInsertId();
        // Son propre accès Wi-Fi pour passer le portail
        $mdpWifi = genererMotDePasse();
        $pdo->prepare('INSERT INTO wifi_comptes (identifiant, nt_hash, mdp_chiffre, description, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute(['raspberry-' . $nouveauId, wifiNtHash($mdpWifi), chiffrerMotDePasse($mdpWifi), 'Raspberry « ' . $nom . ' »', (int)currentUser()['id']]);
        $pdo->prepare('UPDATE raspberrys SET wifi_compte_id = ? WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $nouveauId]);
        raspberryNouveauJeton($pdo, $nouveauId);
        flash('success', 'Raspberry « ' . $nom . ' » ajouté. Lancez sa commande d\'installation, affichée sur cette page.');
        redirect('admin/raspberrys.php');
    }

    $cible = chargerRaspberry($pdo, idPositif($_POST['id'] ?? ''));
    if (!$cible) { flash('error', 'Raspberry introuvable.'); redirect('admin/raspberrys.php'); }

    // ---- MODIFIER ----
    if ($fa === 'update') {
        if ($nom === '') {
            flash('error', 'Donnez un nom à ce Raspberry.');
            redirect('admin/raspberrys.php?action=edit&id=' . (int)$cible['id']);
        }
        $userId = idPositif($_POST['user_id'] ?? '') ?: null;
        $pdo->prepare('UPDATE raspberrys SET nom = ?, user_id = ? WHERE id = ?')->execute([$nom, $userId, $cible['id']]);
        if (isset($_POST['fond_retirer'])) {
            $pdo->prepare('UPDATE raspberrys SET fond = NULL, fond_version = NULL WHERE id = ?')->execute([$cible['id']]);
        } elseif (($_FILES['fond']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = preparerImageJpeg($_FILES['fond']);
            if (!$res['ok']) {
                flash('error', 'Image non enregistrée : ' . $res['erreur']);
                redirect('admin/raspberrys.php?action=edit&id=' . (int)$cible['id']);
            }
            $pdo->prepare('UPDATE raspberrys SET fond = ?, fond_version = ? WHERE id = ?')
                ->execute([$res['image'], substr(hash('sha256', $res['image']), 0, 16), $cible['id']]);
        }
        flash('success', 'Raspberry mis à jour : son écran change d\'ici 15 secondes environ.');
        if ($userId) {
            $st = $pdo->prepare('SELECT username, mdp_chiffre FROM users WHERE id = ?');
            $st->execute([$userId]);
            $u = $st->fetch();
            if ($u && dechiffrerMotDePasse($u['mdp_chiffre']) === null) {
                flash('warning', 'Le mot de passe de ' . $u['username'] . ' n\'est pas encore connu : il s\'affichera après sa prochaine connexion, ou générez-en un nouveau dans Admin › Comptes.', true);
            }
        }
        redirect('admin/raspberrys.php');
    }

    // ---- NOUVELLE COMMANDE D'INSTALLATION (nouveau jeton) ----
    if ($fa === 'jeton' && !$cible['principal']) {
        raspberryNouveauJeton($pdo, (int)$cible['id']);
        flash('success', 'Nouvelle commande créée : l\'ancienne ne marche plus.');
        redirect('admin/raspberrys.php');
    }

    // ---- SUPPRIMER ----
    if ($fa === 'delete' && !$cible['principal']) {
        $pdo->prepare('DELETE FROM wifi_comptes WHERE id = ?')->execute([$cible['wifi_compte_id']]);
        $pdo->prepare('DELETE FROM raspberrys WHERE id = ?')->execute([$cible['id']]);
        flash('success', 'Raspberry supprimé : son accès Wi-Fi est retiré.');
    }
    redirect('admin/raspberrys.php');
}

header('Cache-Control: no-store');

$editR = null;
if ($action === 'edit') {
    $editR = $id ? chargerRaspberry($pdo, $id) : null;
    if (!$editR) redirect('admin/raspberrys.php');
}

$raspberrys = $pdo->query(
    'SELECT r.id, r.nom, r.principal, r.user_id, r.fond_version, r.derniere_vue, r.jeton_chiffre, u.username, u.mdp_chiffre,
            TIMESTAMPDIFF(SECOND, r.derniere_vue, NOW()) AS age_s
     FROM raspberrys r LEFT JOIN users u ON u.id = r.user_id
     ORDER BY r.principal DESC, r.nom ASC'
)->fetchAll();
$comptes = $pdo->query('SELECT id, username, mdp_chiffre FROM users WHERE actif = 1 ORDER BY username ASC')->fetchAll();
$autres = array_filter($raspberrys, fn($r) => !$r['principal']);

$pageTitle = 'Raspberry';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Raspberry</h1>
        <p class="sub">Le compte affiché sur l'écran de chaque Raspberry, son image de fond, et la commande pour installer les autres. Le compte s'attribue aussi depuis sa fiche, dans <a href="users.php">Comptes</a>.</p>
    </div>
    <div class="btn-row">
        <a href="raspberrys.php?action=new" class="btn"><?= icon('plus') ?>Ajouter un Raspberry</a>
    </div>
</div>

<?php if ($autres): ?>
<section class="card mb-5" aria-labelledby="h-commande">
    <h2 id="h-commande" class="h-inline"><?= icon('monitor') ?> Installer les autres Raspberry</h2>
    <p class="hint">Sur le Raspberry à installer, relié à internet et capable de joindre ce site : ouvrez un terminal, collez sa commande et validez. Il rejoint ensuite le Wi-Fi tout seul, et il passe à « Connecté » dans la liste en dessous.</p>
    <?php foreach ($autres as $r): $commande = raspberryCommande($pdo, $r); ?>
        <p><strong><?= e($r['nom']) ?></strong></p>
        <?php if ($commande): ?>
            <code style="display:block;white-space:pre-wrap;word-break:break-all;user-select:all"><?= e($commande) ?></code>
        <?php else: ?>
            <p class="hint">Pas encore de commande : menu <strong>⋯</strong> › <strong>Nouvelle commande d'installation</strong>.</p>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($action === 'new' || $editR): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-form-title">
    <form method="post" action="raspberrys.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $editR ? 'update' : 'create' ?>">
        <?php if ($editR): ?><input type="hidden" name="id" value="<?= (int)$editR['id'] ?>"><?php endif; ?>
        <div class="modal-header">
            <h3 class="modal-title" id="modal-form-title"><?= icon($editR ? 'edit' : 'monitor') ?> <?= $editR ? 'Modifier le Raspberry' : 'Ajouter un Raspberry' ?></h3>
            <a href="raspberrys.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" required maxlength="60" placeholder="ex. Table A" value="<?= e($editR['nom'] ?? '') ?>">
            </div>
            <?php if ($editR): ?>
                <div class="field">
                    <label for="user_id">Compte affiché sur l'écran</label>
                    <select id="user_id" name="user_id" aria-describedby="user-hint">
                        <option value="">Aucun</option>
                        <?php foreach ($comptes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$editR['user_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['username']) ?><?= dechiffrerMotDePasse($c['mdp_chiffre']) === null ? ' (mot de passe pas encore connu)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="hint" id="user-hint">Son identifiant et son mot de passe seront lisibles par toute personne devant cet écran.</small>
                </div>
                <div class="field">
                    <label for="fond">Image de fond <span class="req">(facultatif)</span></label>
                    <?php if ($editR['fond_version']): ?><img src="raspberrys.php?action=apercu&amp;id=<?= (int)$editR['id'] ?>" alt="Image de fond actuelle" style="display:block;max-width:240px;border-radius:8px;margin-bottom:8px"><?php endif; ?>
                    <input type="file" id="fond" name="fond" accept="image/png,image/jpeg,image/webp" aria-describedby="fond-hint">
                    <small class="hint" id="fond-hint">PNG, JPEG ou WebP, 2 Mo maximum. Les infos de connexion restent affichées par-dessus.</small>
                </div>
                <?php if ($editR['fond_version']): ?>
                <div class="field">
                    <label class="switch">
                        <span class="switch-text"><strong>Retirer l'image</strong><span class="hint">Revient au fond uni.</span></span>
                        <input type="checkbox" name="fond_retirer" role="switch">
                    </label>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="hint">Après l'ajout, sa commande d'installation s'affiche sur cette page : lancée sur ce Raspberry, elle le connecte au Wi-Fi de la salle.</p>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="raspberrys.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $editR ? 'Enregistrer' : 'Ajouter' ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="table-wrap">
    <table class="table-cards">
        <caption class="visually-hidden">Raspberry et leur écran</caption>
        <thead>
            <tr><th scope="col">Raspberry</th><th scope="col">Compte affiché</th><th scope="col">Image de fond</th><th scope="col">État</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
        <?php foreach ($raspberrys as $r):
            $menu = '<a role="menuitem" href="raspberrys.php?action=edit&id=' . (int)$r['id'] . '">' . icon('edit') . 'Modifier</a>';
            if (!$r['principal']) {
                $menu .= boutonPost('raspberrys.php', 'jeton', ['id' => (int)$r['id']], 'Nouvelle commande d\'installation', 'refresh', ['data-confirm' => 'Créer un nouveau jeton pour ' . $r['nom'] . ' ?', 'data-confirm-text' => 'Ce Raspberry ne se mettra plus à jour tant que la nouvelle commande n\'y aura pas été lancée.', 'data-confirm-label' => 'Créer', 'data-confirm-type' => 'warning']);
                $menu .= '<div class="menu-sep" role="separator"></div>' . boutonPost('raspberrys.php', 'delete', ['id' => (int)$r['id']], 'Supprimer', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer ' . $r['nom'] . ' ?', 'data-confirm-text' => 'Son écran ne sera plus mis à jour et son accès Wi-Fi sera retiré.', 'data-confirm-label' => 'Supprimer']);
            }
        ?>
            <tr>
                <td class="cell-primary"><div class="player-cell"><strong><?= e($r['nom']) ?></strong><span class="hint"><?= $r['principal'] ? 'Point d\'accès, héberge le site' : 'Connecté au Wi-Fi' ?></span></div></td>
                <td data-label="Compte :"><?php if ($r['username']): ?><code><?= e($r['username']) ?></code><?= dechiffrerMotDePasse($r['mdp_chiffre']) === null ? ' <span class="badge badge-warning">Mot de passe pas encore connu</span>' : '' ?><?php else: ?><span class="muted">aucun</span><?php endif; ?></td>
                <td data-label="Fond :"><?= $r['fond_version'] ? 'Image personnalisée' : '<span class="muted">fond uni</span>' ?></td>
                <td data-label=""><?php if ($r['principal']): ?><span class="hint">En local</span>
                    <?php elseif ($r['age_s'] === null): ?><span class="badge badge-neutral">Jamais connecté</span>
                    <?php elseif ((int)$r['age_s'] <= 120): ?><span class="badge badge-success badge-dot">Connecté</span>
                    <?php else: ?><span class="badge badge-warning">Muet depuis <?= max(1, intdiv((int)$r['age_s'], 60)) ?> min</span><?php endif; ?></td>
                <td class="cell-actions"><?= menuActions($menu, 'Actions pour ' . $r['nom']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
