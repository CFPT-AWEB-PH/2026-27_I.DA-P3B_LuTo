<?php
require_once __DIR__ . '/../config/config.php';
requirePermission('joueurs.voir');

$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');
$sexesValides = ['M', 'F', 'Autre'];

// ---- EXPORT CSV ---- (avant tout envoi de HTML : ce sont des en-têtes de téléchargement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'export' && checkCsrf($_POST['csrf'] ?? null)) {
    exigerPermissionAction('joueurs.exporter', 'admin/joueurs.php');
    $csv = joueursVersCSV($pdo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="joueurs_' . date('Y-m-d_His') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// ---- IMPORT CSV ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'import_csv') {
    exigerPermissionAction('joueurs.importer', 'admin/joueurs.php');
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

    // Fichier : vraiment envoyé par ce formulaire, 1 Mo max, texte brut (pas l'extension seule)
    $tmp = $_FILES['fichier_csv']['tmp_name'] ?? '';
    $mime = is_uploaded_file($tmp) ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
    if (!is_uploaded_file($tmp) || filesize($tmp) > 1024 * 1024) {
        flash('error', 'Fichier refusé : 1 Mo maximum.');
        redirect('admin/joueurs.php');
    }
    if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'], true)) {
        flash('error', 'Fichier refusé : ce n\'est pas un fichier texte CSV.');
        redirect('admin/joueurs.php');
    }
    $nomFichier = (string)($_FILES['fichier_csv']['name'] ?? '');
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
    exigerPermissionAction($_POST['form_action'] === 'create' ? 'joueurs.creer' : 'joueurs.modifier', 'admin/joueurs.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/joueurs.php');
    }

    $pseudo = texteBorne($_POST['pseudo'] ?? '', 60) ?? '';
    $nom = texteBorne($_POST['nom'] ?? '', 100) ?? '';
    $prenom = texteBorne($_POST['prenom'] ?? '', 100) ?? '';
    $sexe = in_array($_POST['sexe'] ?? '', $sexesValides, true) ? $_POST['sexe'] : null;
    $dateBrute = is_string($_POST['date_naissance'] ?? null) ? $_POST['date_naissance'] : '';
    $dateObj = $dateBrute !== '' ? DateTime::createFromFormat('!Y-m-d', $dateBrute) : null;
    $dateNaissance = ($dateObj && $dateObj->format('Y-m-d') === $dateBrute && (int)$dateObj->format('Y') >= 1900) ? $dateBrute : null;
    $poidsBrut = is_string($_POST['poids'] ?? null) ? str_replace(',', '.', $_POST['poids']) : '';
    $poids = is_numeric($poidsBrut) && (float)$poidsBrut > 0 && (float)$poidsBrut < 400 ? round((float)$poidsBrut, 1) : null;
    $gradeId = idPositif($_POST['grade_id'] ?? '') ?: null;
    if ($gradeId && !getGradeById($pdo, $gradeId)) $gradeId = null;
    $equipe = texteBorne($_POST['equipe'] ?? '', 60);
    [$valeursChamps, $erreursChamps] = validerChampsJoueur($pdo, $_POST['champ'] ?? []);

    if ($erreursChamps) {
        flash('error', implode(' ', $erreursChamps));
        stashOldInput($_POST);
        redirect('admin/joueurs.php?action=' . ($_POST['form_action'] === 'update' ? 'edit&id=' . (int)$_POST['id'] : 'new'));
    }

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
            'INSERT INTO joueurs (pseudo, nom, prenom, sexe, date_naissance, poids, grade_id, equipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$pseudo, $nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId, $equipe]);
        enregistrerValeursChamps($pdo, (int)$pdo->lastInsertId(), $valeursChamps);
        flash('success', 'Joueur créé avec succès.');
    } else {
        $editId = idPositif($_POST['id'] ?? '');
        $stmt = $pdo->prepare(
            'UPDATE joueurs SET pseudo = ?, nom = ?, prenom = ?, sexe = ?, date_naissance = ?, poids = ?, grade_id = ?, equipe = ? WHERE id = ?'
        );
        $stmt->execute([$pseudo, $nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId, $equipe, $editId]);
        if ($pdo->query('SELECT 1 FROM joueurs WHERE id = ' . (int)$editId)->fetch()) {
            enregistrerValeursChamps($pdo, $editId, $valeursChamps);
        }
        flash('success', 'Joueur mis à jour.');
    }
    redirect('admin/joueurs.php');
}

// ---- DELETE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete') {
    exigerPermissionAction('joueurs.supprimer', 'admin/joueurs.php');
    $id = idPositif($_POST['id'] ?? '');
    if (checkCsrf($_POST['csrf'] ?? null)) {
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
if ($action === 'new' && !can('joueurs.creer')) $action = 'list';
if ($action === 'edit' && !can('joueurs.modifier')) $action = 'list';
if ($action === 'import' && !can('joueurs.importer')) $action = 'list';
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM joueurs WHERE id = ?');
    $stmt->execute([$id]);
    $editJoueur = $stmt->fetch();
    if ($editJoueur) $editJoueur['champ'] = valeursChampsJoueurs($pdo, $id)[$id] ?? [];
}

$old = oldInput();
if ($old && in_array($action, ['new', 'edit'], true)) {
    $editJoueur = array_merge($editJoueur ?? [], $old);
}
$champsJoueur = getChampsJoueur($pdo);
$valeursChamps = valeursChampsJoueurs($pdo);

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

<div class="page-head">
    <div>
        <h1>Joueurs</h1>
        <p class="sub"><?= count($joueurs) ?> joueur<?= count($joueurs) > 1 ? 's' : '' ?> inscrit<?= count($joueurs) > 1 ? 's' : '' ?>.</p>
    </div>
    <div class="btn-row">
        <?php if (can('joueurs.importer')): ?><a href="joueurs.php?action=import" class="btn btn-secondary"><?= icon('upload') ?>Importer un CSV</a><?php endif; ?>
        <?php if (can('joueurs.exporter')): ?><form method="post" action="joueurs.php" class="actions-inline" data-no-busy><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="form_action" value="export"><button type="submit" class="btn btn-secondary"><?= icon('download') ?>Exporter</button></form><?php endif; ?>
        <?php if (can('joueurs.creer')): ?><a href="joueurs.php?action=new" class="btn"><?= icon('plus') ?>Nouveau joueur</a><?php endif; ?>
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
            <a href="joueurs.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="pseudo">Nom de combat <span class="req">(obligatoire)</span></label>
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
                        <option value="">Non renseigné</option>
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
                        <option value="">Non renseigné</option>
                        <?php foreach ($grades as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= (($editJoueur['grade_id'] ?? null) == $g['id']) ? 'selected' : '' ?>><?= e($g['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="equipe">Équipe / Club</label>
                <input type="text" id="equipe" name="equipe" maxlength="100" value="<?= e($editJoueur['equipe'] ?? '') ?>">
            </div>
            <?php if ($champsJoueur): ?>
                <div class="grid grid-2">
                    <?php foreach ($champsJoueur as $c): ?>
                        <?= inputChampJoueur($c, is_array($editJoueur['champ'] ?? null) && is_string($editJoueur['champ'][$c['id']] ?? null) ? $editJoueur['champ'][$c['id']] : null) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (can('joueurs.champs')): ?>
                <p class="hint"><a href="parametres.php?action=new_champ"><?= icon('plus') ?>Ajouter un champ aux fiches joueurs</a></p>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="joueurs.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new' ? 'Ajouter le joueur' : 'Enregistrer les modifications' ?></button>
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
            <a href="joueurs.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <p class="hint">
                Colonnes attendues : <code>pseudo</code>, <code>nom</code>, <code>prenom</code>, <code>sexe</code>,
                <code>date_naissance</code>, <code>poids</code>, <code>grade</code>, <code>equipe</code><?php foreach ($champsJoueur as $c): ?>, <code><?= e($c['nom']) ?></code><?php endforeach; ?>.<br>
                Le <strong>pseudo sert de clé</strong> : fiche mise à jour si elle existe déjà.
            </p>
            <div class="field">
                <label for="fichier_csv">Fichier CSV</label>
                <input type="file" id="fichier_csv" name="fichier_csv" accept=".csv,text/csv" required>
            </div>
        </div>
        <div class="modal-footer">
            <a href="joueurs.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('upload') ?>Importer les joueurs</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($joueurs): ?>
<div class="toolbar">
    <div class="search input-icon">
        <?= icon('search') ?>
        <input type="search" placeholder="Rechercher un joueur, une équipe, un grade" aria-label="Rechercher un joueur" data-filter="#joueurs-body" data-filter-empty="#joueurs-vide" data-filter-count="#joueurs-count">
    </div>
    <span class="hint" id="joueurs-count" aria-live="polite"></span>
</div>
<?php endif; ?>

<div class="table-wrap">
    <table class="table-cards">
        <caption class="visually-hidden">Liste des joueurs inscrits</caption>
        <thead>
            <tr>
                <th scope="col">Joueur</th>
                <th scope="col">Équipe</th>
                <th scope="col">Profil</th>
                <th scope="col">Grade</th>
                <th scope="col" class="num" title="Victoires · défaites">Bilan</th>
                <th scope="col" class="num" title="Points marqués au total">Points</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody id="joueurs-body">
        <?php foreach ($joueurs as $j):
            $age = calculerAge($j['date_naissance']);
            $nomComplet = trim(($j['prenom'] ?? '') . ' ' . ($j['nom'] ?? ''));
            $profil = array_filter([$j['sexe'] ? libelleSexe($j['sexe']) : null, $age !== null ? $age . ' ans' : null, $j['poids'] !== null ? rtrim(rtrim(number_format($j['poids'], 1, ',', ''), '0'), ',') . ' kg' : null]);
            $infos = [];
            foreach ($champsJoueur as $c) {
                $v = afficherValeurChamp($c, $valeursChamps[(int)$j['id']][(int)$c['id']] ?? null);
                if ($v !== '') $infos[] = $c['nom'] . ' : ' . $v;
            }
            $menu = '';
            if (can('joueurs.modifier')) $menu .= '<a role="menuitem" href="joueurs.php?action=edit&id=' . (int)$j['id'] . '">' . icon('edit') . 'Modifier</a>';
            if (can('joueurs.supprimer')) $menu .= ($menu ? '<div class="menu-sep" role="separator"></div>' : '') . boutonPost('joueurs.php', 'delete', ['id' => (int)$j['id']], 'Supprimer', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer ' . $j['pseudo'] . ' ?', 'data-confirm-text' => 'Sa fiche et ses statistiques seront effacées définitivement.', 'data-confirm-label' => 'Supprimer']);
        ?>
            <tr data-search="<?= e(mb_strtolower($j['pseudo'] . ' ' . $nomComplet . ' ' . ($j['equipe'] ?? '') . ' ' . ($j['grade_nom'] ?? '') . ' ' . implode(' ', $infos))) ?>">
                <td class="cell-primary"><div class="player-cell">
                    <?php if (can('joueurs.modifier')): ?><a href="joueurs.php?action=edit&id=<?= (int)$j['id'] ?>" class="duel-cell"><strong><?= e($j['pseudo']) ?></strong></a><?php else: ?><strong><?= e($j['pseudo']) ?></strong><?php endif; ?>
                    <span class="hint"><?= $nomComplet !== '' ? e($nomComplet) : 'Nom non renseigné' ?></span>
                </div></td>
                <td data-label="Équipe :"><?= e($j['equipe'] ?? '') ?: '<span class="muted">–</span>' ?></td>
                <td data-label="Profil :"><?= $profil ? e(implode(' · ', $profil)) : '<span class="muted">–</span>' ?><?php if ($infos): ?><span class="sub-line"><?= e(implode(' · ', $infos)) ?></span><?php endif; ?></td>
                <td data-label="Grade :"><?php if ($j['grade_nom']): ?>
                    <span class="badge" style="background:<?= e($j['grade_couleur'] ?: '#2a3358') ?>1f;color:<?= e($j['grade_couleur'] ?: 'var(--text)') ?>;border-color:<?= e($j['grade_couleur'] ?: 'var(--border)') ?>55;"><?= e($j['grade_nom']) ?></span>
                <?php else: ?><span class="muted">–</span><?php endif; ?></td>
                <td class="num tabular hide-sm"><strong><?= (int)$j['victoires'] ?></strong> V · <?= (int)$j['defaites'] ?> D<span class="sub-line"><?= (int)$j['matchs_joues'] ?> combat<?= (int)$j['matchs_joues'] > 1 ? 's' : '' ?></span></td>
                <td class="num tabular hide-sm"><?= fmtScore($j['points_cumules']) ?></td>
                <td class="cell-actions"><?= menuActions($menu, 'Actions pour ' . $j['pseudo']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$joueurs): ?>
            <tr><td colspan="7">
                <?= etatVide('Aucun joueur inscrit', 'Ajoutez les joueurs un par un, ou importez la liste d\'inscription au format CSV.', can('joueurs.creer') ? '<div class="btn-row" style="justify-content:center"><a href="joueurs.php?action=import" class="btn btn-secondary">' . icon('upload') . 'Importer un CSV</a><a href="joueurs.php?action=new" class="btn">' . icon('plus') . 'Ajouter un joueur</a></div>' : '', 'users', 'compact') ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div id="joueurs-vide" hidden><?= etatVide('Aucun joueur ne correspond', 'Essayez un autre nom, une équipe ou un grade.', '', 'search', 'compact') ?></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
