<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ---- Mise à jour des paramètres généraux ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_parametres') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/parametres.php');
    }

    // Clés dont la valeur est un booléen 0/1, affichées comme case à cocher :
    // une case décochée n'apparaît pas du tout dans $_POST, donc on les traite
    // à part plutôt que via la boucle générique ci-dessous.
    $clesBooleennes = ['appariement_meme_sexe'];

    $lignes = $pdo->query('SELECT cle FROM parametres')->fetchAll();
    foreach ($lignes as $ligne) {
        $cle = $ligne['cle'];

        if (in_array($cle, $clesBooleennes, true)) {
            setParametre($pdo, $cle, isset($_POST['param'][$cle]) ? '1' : '0');
            continue;
        }

        if (isset($_POST['param'][$cle])) {
            $valeur = trim($_POST['param'][$cle]);
            if (in_array($cle, ['duree_combat_defaut', 'ecart_age_max_ans', 'ecart_grade_max', 'intervalle_actualisation_ms', 'intervalle_rotation_affichage_ms'], true)) {
                // Valeurs en nombre entier uniquement (secondes, années, ms...).
                $valeur = (string) max(0, (int) $valeur);
            } elseif ($cle === 'points_max') {
                // Note maximale : les arbitres saisissent par pas de 0.5
                // (voir arbitrage/saisie.php), donc on conserve les décimales
                // au lieu de tronquer en entier.
                $valeur = (string) max(0.5, round((float) $valeur * 2) / 2);
            } elseif ($cle === 'ecart_poids_max_kg') {
                $valeur = (string) max(0, round((float) str_replace(',', '.', $valeur), 1));
            } elseif ($cle === 'retard_minutes') {
                // Le retard cumulé se modifie via le bouton dédié
                // (appliquerRetard()), pas depuis ce formulaire générique.
                continue;
            }
            if ($valeur !== '') {
                setParametre($pdo, $cle, $valeur);
            }
        }
    }

    flash('success', 'Paramètres mis à jour.');
    redirect('admin/parametres.php');
}

// ---- CREATE / UPDATE grade ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_action'] ?? '', ['create_grade', 'update_grade'], true)) {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/parametres.php');
    }

    $nom = trim($_POST['nom'] ?? '');
    $ordre = (int)($_POST['ordre'] ?? 0);
    $couleur = trim($_POST['couleur'] ?? '') ?: null;

    if ($nom === '') {
        flash('error', 'Le nom du grade est obligatoire.');
        stashOldInput($_POST);
        redirect('admin/parametres.php?action=' . ($_POST['form_action'] === 'update_grade' ? 'edit_grade&id=' . (int)$_POST['id'] : 'new_grade'));
    }

    try {
        if ($_POST['form_action'] === 'create_grade') {
            $pdo->prepare('INSERT INTO grades (nom, ordre, couleur) VALUES (?, ?, ?)')->execute([$nom, $ordre, $couleur]);
            flash('success', 'Grade créé.');
        } else {
            $editGradeId = (int)$_POST['id'];
            $pdo->prepare('UPDATE grades SET nom = ?, ordre = ?, couleur = ? WHERE id = ?')->execute([$nom, $ordre, $couleur, $editGradeId]);
            flash('success', 'Grade mis à jour.');
        }
    } catch (PDOException $e) {
        flash('error', 'Un grade avec ce nom existe déjà.');
    }
    redirect('admin/parametres.php');
}

// ---- DELETE grade ----
if ($action === 'delete_grade' && $id) {
    if (checkCsrf($_GET['csrf'] ?? null)) {
        $pdo->prepare('DELETE FROM grades WHERE id = ?')->execute([$id]);
        flash('success', 'Grade supprimé. Les joueurs qui l\'avaient perdent simplement leur grade (aucune donnée de combat affectée).');
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/parametres.php');
}

$editGrade = null;
if ($action === 'edit_grade' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM grades WHERE id = ?');
    $stmt->execute([$id]);
    $editGrade = $stmt->fetch();
}

$old = oldInput();
if ($old && in_array($action, ['new_grade', 'edit_grade'], true)) {
    $editGrade = array_merge($editGrade ?? [], $old);
}

$grades = getGrades($pdo);
$parametres = $pdo->query('SELECT * FROM parametres ORDER BY cle ASC')->fetchAll();

$pageTitle = 'Paramètres';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<h1><?= icon('settings') ?> Paramètres</h1>

<?php if (in_array($action, ['new_grade', 'edit_grade'])): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-grade-title">
    <form method="post" action="parametres.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new_grade' ? 'create_grade' : 'update_grade' ?>">
        <?php if ($action === 'edit_grade' && !empty($editGrade['id'])): ?>
            <input type="hidden" name="id" value="<?= $editGrade['id'] ?>">
        <?php endif; ?>
        <div class="modal-header">
            <h3 id="modal-grade-title"><?= icon($action === 'new_grade' ? 'plus' : 'edit') ?> <?= $action === 'new_grade' ? 'Nouveau grade' : 'Modifier le grade' ?></h3>
            <a href="parametres.php" class="modal-close-btn" aria-label="Fermer">&#x2715;</a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="nom">Nom du grade *</label>
                <input type="text" id="nom" name="nom" required value="<?= e($editGrade['nom'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="ordre">Ordre d'affichage</label>
                <input type="number" id="ordre" name="ordre" step="1" value="<?= $editGrade['ordre'] ?? 0 ?>">
                <small class="hint">Croissant dans les listes déroulantes.</small>
            </div>
            <div class="field">
                <label for="couleur">Couleur du badge</label>
                <input type="color" id="couleur" name="couleur" value="<?= e($editGrade['couleur'] ?? '#00e5ff') ?>" style="height:44px;padding:4px;">
            </div>
        </div>
        <div class="modal-footer">
            <a href="parametres.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= icon('check-circle') ?> Enregistrer</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="grid grid-2">
    <div>
        <div class="section-title"><h2 id="heading-params"><?= icon('sliders') ?> Paramètres généraux</h2></div>
        <div class="card">
            <form method="post" action="parametres.php" aria-labelledby="heading-params">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="form_action" value="update_parametres">
                <?php
                $clesBooleennes = ['appariement_meme_sexe'];
                $clesMasquees   = ['retard_minutes'];
                ?>
                <?php foreach ($parametres as $p): if (in_array($p['cle'], $clesMasquees, true)) continue; ?>
                    <div class="field">
                        <?php if (in_array($p['cle'], $clesBooleennes, true)): ?>
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                                <input type="checkbox" name="param[<?= e($p['cle']) ?>]" value="1" <?= $p['valeur'] === '1' ? 'checked' : '' ?>>
                                <?= e($p['description'] ?: $p['cle']) ?>
                            </label>
                        <?php else: ?>
                            <label for="param_<?= e($p['cle']) ?>"><?= e($p['description'] ?: $p['cle']) ?></label>
                            <input type="text" id="param_<?= e($p['cle']) ?>" name="param[<?= e($p['cle']) ?>]" value="<?= e($p['valeur']) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit"><?= icon('check-circle') ?> Enregistrer les paramètres</button>
            </form>
        </div>
    </div>

    <div>
        <div class="section-title" style="margin-bottom:var(--space-3);">
            <h2 id="heading-grades"><?= icon('medal') ?> Grades</h2>
            <a href="parametres.php?action=new_grade" class="btn btn-sm"><?= icon('plus') ?> Nouveau grade</a>
        </div>
        <div class="table-wrap">
            <table aria-labelledby="heading-grades">
                <thead><tr><th scope="col">Grade</th><th scope="col">Ordre</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($grades as $g): ?>
                    <tr>
                        <td><span class="badge" style="background:<?= e($g['couleur'] ?: '#2a3358') ?>22;color:<?= e($g['couleur'] ?: 'var(--text)') ?>;border-color:<?= e($g['couleur'] ?: 'var(--border)') ?>66;"><?= e($g['nom']) ?></span></td>
                        <td><?= (int)$g['ordre'] ?></td>
                        <td class="actions-inline">
                            <a href="parametres.php?action=edit_grade&id=<?= $g['id'] ?>" class="btn btn-secondary btn-sm"><?= icon('edit') ?> Éditer</a>
                            <a href="parametres.php?action=delete_grade&id=<?= $g['id'] ?>&csrf=<?= e(csrfToken()) ?>"
                               class="btn btn-danger btn-sm"
                               data-confirm="Supprimer le grade «<?= e($g['nom']) ?>» ? Les joueurs qui l'ont perdront ce grade."
                               data-confirm-label="Supprimer" data-confirm-icon="🗑️"><?= icon('trash') ?> Supprimer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$grades): ?>
                    <tr><td colspan="3"><div class="empty-state" style="padding:var(--space-4);"><?= icon('medal') ?><p>Aucun grade configuré.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
