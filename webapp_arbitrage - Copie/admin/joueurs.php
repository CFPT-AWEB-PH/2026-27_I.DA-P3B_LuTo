<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$sexesValides = ['M', 'F', 'Autre'];

// ---- EXPORT CSV ---- (avant tout envoi de HTML : ce sont des en-têtes de téléchargement)
if ($action === 'export' && checkCsrf($_GET['csrf'] ?? null)) {
    $csv = joueursVersCSV($pdo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="joueurs_' . date('Y-m-d_His') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// ---- IMPORT CSV ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'import_csv') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/joueurs.php');
    }

    if (empty($_FILES['fichier_csv']) || $_FILES['fichier_csv']['error'] !== UPLOAD_ERR_OK) {
        $messagesErreurUpload = [
            UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
            UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement envoyé, réessaie.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné.',
        ];
        $code = $_FILES['fichier_csv']['error'] ?? UPLOAD_ERR_NO_FILE;
        flash('error', $messagesErreurUpload[$code] ?? 'Échec de l\'envoi du fichier.');
        redirect('admin/joueurs.php');
    }

    $nomFichier = $_FILES['fichier_csv']['name'];
    if (!preg_match('/\.csv$/i', $nomFichier)) {
        flash('error', 'Le fichier doit être au format .csv (exporté depuis Excel : "Enregistrer sous" → CSV).');
        redirect('admin/joueurs.php');
    }

    $resume = importerJoueursCSV($pdo, $_FILES['fichier_csv']['tmp_name']);

    $aucuneLigneValide = $resume['crees'] === 0 && $resume['mis_a_jour'] === 0 && $resume['erreurs'];
    if ($aucuneLigneValide) {
        flash('error', 'Import échoué : aucune ligne valide. ' . implode(' ', array_slice($resume['erreurs'], 0, 5)));
    } else {
        $message = "Import terminé : {$resume['crees']} joueur(s) créé(s), {$resume['mis_a_jour']} mis à jour.";
        if ($resume['erreurs']) {
            $message .= ' ' . count($resume['erreurs']) . ' ligne(s) ignorée(s) : ' . implode(' | ', array_slice($resume['erreurs'], 0, 5));
            if (count($resume['erreurs']) > 5) $message .= ' …';
        }
        // 'info' plutôt que 'success' quand l'import a réussi mais avec des
        // lignes ignorées : ce n'est ni un échec total, ni un succès complet.
        flash($resume['erreurs'] ? 'info' : 'success', $message);
    }
    redirect('admin/joueurs.php');
}

// ---- CREATE / UPDATE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_action'] ?? '', ['create','update'])) {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/joueurs.php');
    }

    $pseudo = trim($_POST['pseudo'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $sexe = in_array($_POST['sexe'] ?? '', $sexesValides, true) ? $_POST['sexe'] : null;
    $dateNaissance = $_POST['date_naissance'] ?: null;
    $poids = ($_POST['poids'] ?? '') !== '' ? (float)$_POST['poids'] : null;
    $gradeId = ($_POST['grade_id'] ?? '') !== '' ? (int)$_POST['grade_id'] : null;

    if ($pseudo === '') {
        flash('error', 'Le pseudo est obligatoire.');
        stashOldInput($_POST);
        redirect('admin/joueurs.php?action=' . ($_POST['form_action'] === 'update' ? 'edit&id=' . (int)$_POST['id'] : 'new'));
    }

    if ($dateNaissance && strtotime($dateNaissance) > time()) {
        flash('error', 'La date de naissance ne peut pas être dans le futur.');
        stashOldInput($_POST);
        redirect('admin/joueurs.php?action=' . ($_POST['form_action'] === 'update' ? 'edit&id=' . (int)$_POST['id'] : 'new'));
    }

    if ($_POST['form_action'] === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO joueurs (pseudo, nom, prenom, sexe, date_naissance, poids, grade_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$pseudo, $nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId]);
        flash('success', 'Joueur créé avec succès.');
    } else {
        $editId = (int)$_POST['id'];
        $stmt = $pdo->prepare(
            'UPDATE joueurs SET pseudo = ?, nom = ?, prenom = ?, sexe = ?, date_naissance = ?, poids = ?, grade_id = ? WHERE id = ?'
        );
        $stmt->execute([$pseudo, $nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId, $editId]);
        flash('success', 'Joueur mis à jour.');
    }
    redirect('admin/joueurs.php');
}

// ---- DELETE ----
if ($action === 'delete' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        try {
            $pdo->prepare('DELETE FROM joueurs WHERE id = ?')->execute([$id]);
            flash('success', 'Joueur supprimé.');
        } catch (PDOException $e) {
            flash('error', 'Impossible de supprimer ce joueur : il est lié à des combats existants.');
        }
    } else {
        flash('error', 'Session expirée.');
    }
    redirect('admin/joueurs.php');
}

$editJoueur = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM joueurs WHERE id = ?');
    $stmt->execute([$id]);
    $editJoueur = $stmt->fetch();
}

$old = oldInput();
if ($old && in_array($action, ['new', 'edit'], true)) {
    $editJoueur = array_merge($editJoueur ?? [], $old);
}

$grades = getGrades($pdo);
$joueurs = $pdo->query(
    'SELECT j.*, g.nom AS grade_nom, g.couleur AS grade_couleur
     FROM joueurs j
     LEFT JOIN grades g ON g.id = j.grade_id
     ORDER BY j.pseudo ASC'
)->fetchAll();

$pageTitle = 'Gestion des joueurs';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="section-title" style="margin-bottom:var(--space-5);">
    <h1><?= icon('users') ?> Joueurs</h1>
    <div class="btn-row" style="margin:0;">
        <a href="joueurs.php?action=new" class="btn"><?= icon('plus') ?> Nouveau joueur</a>
        <a href="joueurs.php?action=import" class="btn btn-secondary"><?= icon('upload') ?> Importer CSV</a>
        <a href="joueurs.php?action=export&csrf=<?= e(csrfToken()) ?>" class="btn btn-secondary"><?= icon('download') ?> Exporter CSV</a>
    </div>
</div>

<?php if (in_array($action, ['new', 'edit'])): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-form-title">
    <form method="post" action="joueurs.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
        <?php if ($action === 'edit' && !empty($editJoueur['id'])): ?>
            <input type="hidden" name="id" value="<?= $editJoueur['id'] ?>">
        <?php endif; ?>
        <div class="modal-header">
            <h3 id="modal-form-title"><?= icon($action === 'new' ? 'user-plus' : 'edit') ?> <?= $action === 'new' ? 'Nouveau joueur' : 'Modifier le joueur' ?></h3>
            <a href="joueurs.php" class="modal-close-btn" aria-label="Fermer">&#x2715;</a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="pseudo">Pseudo (nom de combat) *</label>
                <input type="text" id="pseudo" name="pseudo" required value="<?= e($editJoueur['pseudo'] ?? '') ?>">
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" value="<?= e($editJoueur['prenom'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" value="<?= e($editJoueur['nom'] ?? '') ?>">
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="sexe">Sexe</label>
                    <select id="sexe" name="sexe">
                        <option value="">— Non renseigné —</option>
                        <option value="M" <?= (($editJoueur['sexe'] ?? '') === 'M') ? 'selected' : '' ?>>Homme</option>
                        <option value="F" <?= (($editJoueur['sexe'] ?? '') === 'F') ? 'selected' : '' ?>>Femme</option>
                        <option value="Autre" <?= (($editJoueur['sexe'] ?? '') === 'Autre') ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>
                <div class="field">
                    <label for="date_naissance">Date de naissance</label>
                    <input type="date" id="date_naissance" name="date_naissance" max="<?= date('Y-m-d') ?>" value="<?= e($editJoueur['date_naissance'] ?? '') ?>">
                    <?php $age = calculerAge($editJoueur['date_naissance'] ?? null); ?>
                    <?php if ($age !== null): ?><small class="hint"><?= $age ?> ans</small><?php endif; ?>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="poids">Poids (kg)</label>
                    <input type="number" id="poids" name="poids" min="0" step="0.1" inputmode="decimal" value="<?= $editJoueur['poids'] ?? '' ?>">
                </div>
                <div class="field">
                    <label for="grade_id">Grade</label>
                    <select id="grade_id" name="grade_id">
                        <option value="">— Non renseigné —</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= (($editJoueur['grade_id'] ?? null) == $g['id']) ? 'selected' : '' ?>><?= e($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="joueurs.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('check-circle') ?> Enregistrer</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($action === 'import'): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-import-title">
    <form method="post" action="joueurs.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="import_csv">
        <div class="modal-header">
            <h3 id="modal-import-title"><?= icon('upload') ?> Importer des joueurs (CSV)</h3>
            <a href="joueurs.php" class="modal-close-btn" aria-label="Fermer">&#x2715;</a>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:var(--space-3);font-size:var(--fs-sm);">
                Colonnes attendues : <code>pseudo</code>, <code>nom</code>, <code>prenom</code>, <code>sexe</code>,
                <code>date_naissance</code>, <code>poids</code>, <code>grade</code>.<br>
                Le <strong>pseudo sert de clé</strong> : fiche mise à jour si elle existe déjà.
            </p>
            <div class="field">
                <label for="fichier_csv">Fichier CSV *</label>
                <input type="file" id="fichier_csv" name="fichier_csv" accept=".csv,text/csv" required>
            </div>
        </div>
        <div class="modal-footer">
            <a href="joueurs.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('upload') ?> Importer</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <caption class="visually-hidden">Liste de tous les joueurs enregistrés</caption>
        <thead>
            <tr>
                <th scope="col">Pseudo</th><th scope="col">Nom complet</th><th scope="col">Sexe</th>
                <th scope="col">Âge</th><th scope="col">Poids</th><th scope="col">Grade</th>
                <th scope="col">M</th><th scope="col">V</th><th scope="col">D</th>
                <th scope="col">Pts</th><th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($joueurs as $j): $age = calculerAge($j['date_naissance']); ?>
            <tr>
                <td><strong><?= e($j['pseudo']) ?></strong></td>
                <td><?= e(trim(($j['prenom'] ?? '') . ' ' . ($j['nom'] ?? ''))) ?: '—' ?></td>
                <td><?= e(libelleSexe($j['sexe'])) ?></td>
                <td><?= $age !== null ? $age . ' ans' : '—' ?></td>
                <td><?= $j['poids'] !== null ? number_format($j['poids'], 1) . ' kg' : '—' ?></td>
                <td><?php if ($j['grade_nom']): ?>
                    <span class="badge" style="background:<?= e($j['grade_couleur'] ?: '#2a3358') ?>22;color:<?= e($j['grade_couleur'] ?: 'var(--text)') ?>;border-color:<?= e($j['grade_couleur'] ?: 'var(--border)') ?>66;"><?= e($j['grade_nom']) ?></span>
                <?php else: ?>—<?php endif; ?></td>
                <td><?= (int)$j['matchs_joues'] ?></td>
                <td><?= (int)$j['victoires'] ?></td>
                <td><?= (int)$j['defaites'] ?></td>
                <td><?= number_format($j['points_cumules'],1) ?></td>
                <td class="actions-inline">
                    <a href="joueurs.php?action=edit&id=<?= $j['id'] ?>" class="btn btn-secondary btn-sm"><?= icon('edit') ?> Éditer</a>
                    <a href="joueurs.php?action=delete&id=<?= $j['id'] ?>&csrf=<?= e(csrfToken()) ?>"
                       class="btn btn-danger btn-sm"
                       data-confirm="Supprimer <?= e($j['pseudo']) ?> définitivement ?" data-confirm-label="Supprimer" data-confirm-icon="🗑️">
                       <?= icon('trash') ?> Supprimer</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$joueurs): ?>
            <tr><td colspan="11">
                <div class="empty-state"><?= icon('users') ?><p>Aucun joueur enregistré.</p>
                <a href="joueurs.php?action=new" class="btn" style="margin-top:var(--space-3);"><?= icon('plus') ?> Ajouter le premier joueur</a></div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
