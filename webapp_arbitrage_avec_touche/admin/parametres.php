<?php
require_once __DIR__ . '/../config/config.php';
requirePermission('parametres.voir', 'parametres.modifier', 'grades.gerer', 'joueurs.champs');

$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');

// ---- Mise à jour des paramètres généraux ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_parametres') {
    exigerPermissionAction('parametres.modifier', 'admin/parametres.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/parametres.php');
    }

    // Clés dont la valeur est un booléen 0/1, affichées comme case à cocher :
    // une case décochée n'apparaît pas du tout dans $_POST, donc on les traite
    // à part plutôt que via la boucle générique ci-dessous.
    $clesBooleennes = ['appariement_meme_sexe', 'demarrage_auto', 'inscriptions_ouvertes'];

    $lignes = $pdo->query('SELECT cle FROM parametres')->fetchAll();
    foreach ($lignes as $ligne) {
        $cle = $ligne['cle'];

        if (in_array($cle, $clesBooleennes, true)) {
            setParametre($pdo, $cle, isset($_POST['param'][$cle]) ? '1' : '0');
            continue;
        }

        if (isset($_POST['param'][$cle])) {
            if (!is_string($_POST['param'][$cle])) continue;
            $valeur = mb_substr(trim($_POST['param'][$cle]), 0, 200);
            // Les intervalles en millisecondes sont saisis en secondes dans le formulaire
            if (str_ends_with($cle, '_ms') && ($_POST['unite'][$cle] ?? '') === 's') {
                $valeur = (string)(int)round((float)str_replace(',', '.', $valeur) * 1000);
            }
            // Bornes : évitent des valeurs absurdes (ex. rafraîchissement à 0 ms qui saturerait le serveur)
            $bornes = [
                'duree_combat_defaut' => [30, 3600], 'ecart_age_max_ans' => [0, 100], 'ecart_grade_max' => [0, 100],
                'intervalle_actualisation_ms' => [1000, 60000], 'intervalle_rotation_affichage_ms' => [3000, 600000],
                'intervalle_combats_min' => [1, 240],
            ];
            if (in_array($cle, ['schema_tournoi'], true)) {
                continue; // réglage interne, jamais modifiable depuis le formulaire
            } elseif (isset($bornes[$cle])) {
                [$min, $max] = $bornes[$cle];
                $valeur = (string) max($min, min($max, (int) $valeur));
            } elseif ($cle === 'points_max') {
                // Note maximale : les arbitres saisissent par pas de 0.5
                // (voir arbitrage/saisie.php), donc on conserve les décimales
                // au lieu de tronquer en entier.
                $valeur = (string) min(1000, max(0.5, round((float) $valeur * 2) / 2));
            } elseif ($cle === 'ecart_poids_max_kg') {
                $valeur = (string) min(200, max(0, round((float) str_replace(',', '.', $valeur), 1)));
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
    exigerPermissionAction('grades.gerer', 'admin/parametres.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/parametres.php');
    }

    $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';
    $ordre = max(-1000, min(1000, (int)($_POST['ordre'] ?? 0)));
    $couleur = couleurHex($_POST['couleur'] ?? '');

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
            $editGradeId = idPositif($_POST['id'] ?? '');
            $pdo->prepare('UPDATE grades SET nom = ?, ordre = ?, couleur = ? WHERE id = ?')->execute([$nom, $ordre, $couleur, $editGradeId]);
            flash('success', 'Grade mis à jour.');
        }
    } catch (PDOException $e) {
        flash('error', 'Un grade avec ce nom existe déjà.');
    }
    redirect('admin/parametres.php');
}

// ---- DELETE grade ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_grade') {
    exigerPermissionAction('grades.gerer', 'admin/parametres.php');
    $id = idPositif($_POST['id'] ?? '');
    if (checkCsrf($_POST['csrf'] ?? null)) {
        $pdo->prepare('DELETE FROM grades WHERE id = ?')->execute([$id]);
        flash('success', 'Grade supprimé. Les joueurs qui l\'avaient perdent simplement leur grade (aucune donnée de combat affectée).');
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/parametres.php');
}

// ---- CREATE / UPDATE champ personnalisé des joueurs ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form_action'] ?? '', ['create_champ', 'update_champ'], true)) {
    exigerPermissionAction('joueurs.champs', 'admin/parametres.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/parametres.php');
    }

    $editChampId = idPositif($_POST['id'] ?? '');
    $retour = 'admin/parametres.php?action=' . ($_POST['form_action'] === 'update_champ' ? 'edit_champ&id=' . $editChampId : 'new_champ');
    $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';
    $type = array_key_exists($_POST['type'] ?? '', typesChampJoueur()) ? $_POST['type'] : 'texte';
    $options = $type === 'liste' ? implode("\n", optionsChamp(['options' => texteBorne($_POST['options'] ?? '', 5000) ?? ''])) : null;
    $obligatoire = isset($_POST['obligatoire']) ? 1 : 0;
    $ordre = max(-1000, min(1000, (int)($_POST['ordre'] ?? 0)));

    $erreur = null;
    if ($nom === '') $erreur = 'Le nom du champ est obligatoire.';
    elseif (in_array(mb_strtolower($nom), nomsChampsReserves(), true)) $erreur = "« {$nom} » est déjà un champ standard de la fiche joueur.";
    elseif ($type === 'liste' && $options === '') $erreur = 'Indiquez au moins un choix pour une liste (un par ligne).';
    if ($erreur) {
        flash('error', $erreur);
        stashOldInput($_POST);
        redirect($retour);
    }

    try {
        if ($_POST['form_action'] === 'create_champ') {
            $pdo->prepare('INSERT INTO champs_joueur (nom, type, options, obligatoire, ordre) VALUES (?, ?, ?, ?, ?)')->execute([$nom, $type, $options, $obligatoire, $ordre]);
            flash('success', 'Champ « ' . $nom . ' » ajouté aux fiches joueurs.');
        } else {
            $pdo->prepare('UPDATE champs_joueur SET nom = ?, type = ?, options = ?, obligatoire = ?, ordre = ? WHERE id = ?')->execute([$nom, $type, $options, $obligatoire, $ordre, $editChampId]);
            flash('success', 'Champ mis à jour.');
        }
    } catch (PDOException $e) {
        flash('error', 'Un champ avec ce nom existe déjà.');
        stashOldInput($_POST);
        redirect($retour);
    }
    redirect('admin/parametres.php');
}

// ---- DELETE champ personnalisé ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_champ') {
    exigerPermissionAction('joueurs.champs', 'admin/parametres.php');
    $id = idPositif($_POST['id'] ?? '');
    if (checkCsrf($_POST['csrf'] ?? null)) {
        $pdo->prepare('DELETE FROM champs_joueur WHERE id = ?')->execute([$id]);
        flash('success', 'Champ supprimé, ainsi que les valeurs saisies sur les fiches joueurs.');
    } else {
        flash('error', 'Session expirée, merci de réessayer.');
    }
    redirect('admin/parametres.php');
}

$editChamp = null;
if (in_array($action, ['new_champ', 'edit_champ'], true) && !can('joueurs.champs')) $action = 'list';
if ($action === 'edit_champ' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM champs_joueur WHERE id = ?');
    $stmt->execute([$id]);
    $editChamp = $stmt->fetch() ?: null;
}

$editGrade = null;
if (in_array($action, ['new_grade', 'edit_grade'], true) && !can('grades.gerer')) $action = 'list';
if ($action === 'edit_grade' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM grades WHERE id = ?');
    $stmt->execute([$id]);
    $editGrade = $stmt->fetch();
}

$old = oldInput();
if ($old && in_array($action, ['new_grade', 'edit_grade'], true)) {
    $editGrade = array_merge($editGrade ?? [], $old);
}
if ($old && in_array($action, ['new_champ', 'edit_champ'], true)) {
    $editChamp = array_merge($editChamp ?? [], $old, ['obligatoire' => isset($old['obligatoire']) ? 1 : 0]);
}
$champsJoueur = getChampsJoueur($pdo);

$grades = getGrades($pdo);
$joueursParGrade = $pdo->query('SELECT grade_id, COUNT(*) FROM joueurs WHERE grade_id IS NOT NULL GROUP BY grade_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$parametres = $pdo->query('SELECT * FROM parametres ORDER BY cle ASC')->fetchAll();

$pageTitle = 'Paramètres';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Paramètres</h1>
        <p class="sub">Réglages du tournoi. Ils s'appliquent immédiatement à tous les écrans.</p>
    </div>
</div>


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
            <a href="parametres.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="nom">Nom du grade</label>
                <input type="text" id="nom" name="nom" required value="<?= e($editGrade['nom'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="ordre">Ordre d'affichage</label>
                <input type="number" id="ordre" name="ordre" step="1" value="<?= $editGrade['ordre'] ?? 0 ?>">
                <small class="hint">Croissant dans les listes déroulantes.</small>
            </div>
            <div class="field">
                <label for="couleur">Couleur du badge</label>
                <input type="color" id="couleur" name="couleur" value="<?= e($editGrade['couleur'] ?? '#7cb8ff') ?>">
            </div>
        </div>
        <div class="modal-footer">
            <a href="parametres.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new_grade' ? 'Créer le grade' : 'Enregistrer' ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if (in_array($action, ['new_champ', 'edit_champ'])): $typeChamp = $editChamp['type'] ?? 'texte'; ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-champ-title">
    <form method="post" action="parametres.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new_champ' ? 'create_champ' : 'update_champ' ?>">
        <?php if ($action === 'edit_champ' && !empty($editChamp['id'])): ?>
            <input type="hidden" name="id" value="<?= (int)$editChamp['id'] ?>">
        <?php endif; ?>
        <div class="modal-header">
            <h3 id="modal-champ-title"><?= icon($action === 'new_champ' ? 'plus' : 'edit') ?> <?= $action === 'new_champ' ? 'Nouveau champ joueur' : 'Modifier le champ' ?></h3>
            <a href="parametres.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="champ_nom">Nom du champ</label>
                <input type="text" id="champ_nom" name="nom" maxlength="60" required placeholder="ex. N° de licence, Taille, Main forte…" value="<?= e($editChamp['nom'] ?? '') ?>">
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="champ_type">Type</label>
                    <select id="champ_type" name="type">
                        <?php foreach (typesChampJoueur() as $cleType => $libType): ?>
                            <option value="<?= e($cleType) ?>" <?= $typeChamp === $cleType ? 'selected' : '' ?>><?= e($libType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="champ_ordre">Ordre d'affichage</label>
                    <input type="number" id="champ_ordre" name="ordre" step="1" value="<?= (int)($editChamp['ordre'] ?? (count($champsJoueur) + 1)) ?>">
                </div>
            </div>
            <div class="field">
                <label for="champ_options">Choix possibles <span class="hint">(type « Liste de choix » uniquement, un par ligne)</span></label>
                <textarea id="champ_options" name="options" rows="4" placeholder="Droitier&#10;Gaucher"><?= e($editChamp['options'] ?? '') ?></textarea>
            </div>
            <div class="setting-row is-switch">
                <label class="switch">
                    <span class="switch-text"><strong>Obligatoire</strong><span class="hint">Doit être rempli pour enregistrer une fiche joueur.</span></span>
                    <input type="checkbox" role="switch" name="obligatoire" value="1" <?= !empty($editChamp['obligatoire']) ? 'checked' : '' ?>>
                </label>
            </div>
            <?php if ($action === 'edit_champ'): ?><p class="hint">Changer le type ne modifie pas les valeurs déjà saisies.</p><?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="parametres.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new_champ' ? 'Créer le champ' : 'Enregistrer' ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php
/*
 * Présentation des réglages : groupés par intention, avec unité et aide.
 * Une clé inconnue (ajoutée plus tard en base) tombe dans « Avancé ».
 */
$iconesGroupes = ['Tournoi' => 'trophy', 'Planning' => 'calendar', 'Appariement automatique' => 'shuffle', 'Affichage en direct' => 'monitor', 'Avancé' => 'sliders'];
$schema = [
    'Tournoi' => ['Identité et inscriptions.', [
        'nom_application'       => ['text', 'Nom du tournoi', 'Affiché dans l\'en-tête, le titre des pages et l\'écran de salle.'],
        'inscriptions_ouvertes' => ['bool', 'Inscriptions des joueurs ouvertes', 'Permet de créer un compte joueur depuis la page publique.'],
        'duree_combat_defaut'   => ['int', 'Durée d\'un combat par défaut', 'Proposée à la création d\'un combat.', 's', 30, 3600],
    ]],
    'Planning' => ['Démarrage des combats et enchaînement.', [
        'demarrage_auto'         => ['bool', 'Démarrage automatique', 'Chaque combat démarre à son heure prévue s\'il a ses 3 arbitres.'],
        'intervalle_combats_min' => ['int', 'Temps entre deux combats', 'Utilisé par la génération automatique du planning.', 'min', 1, 240],
    ]],
    'Appariement automatique' => ['Règles pour former les paires lors de la génération. 0 = sans limite.', [
        'appariement_meme_sexe' => ['bool', 'Même sexe uniquement', 'N\'apparie que des joueurs de même sexe.'],
        'ecart_poids_max_kg'    => ['dec', 'Écart de poids maximum', '', 'kg', 0, 200],
        'ecart_age_max_ans'     => ['int', 'Écart d\'âge maximum', '', 'ans', 0, 100],
        'ecart_grade_max'       => ['int', 'Écart de grade maximum', 'En nombre de niveaux.', 'niv.', 0, 100],
    ]],
    'Affichage en direct' => ['Fréquences de mise à jour des pages publiques et de l\'écran de salle.', [
        'intervalle_actualisation_ms'      => ['ms', 'Mise à jour des scores', 'Plus court = plus réactif, mais plus de charge sur le serveur.', 's', 1, 60],
        'intervalle_rotation_affichage_ms' => ['ms', 'Rotation de l\'écran de salle', 'Temps passé sur chaque écran (le direct reste deux fois plus longtemps).', 's', 3, 600],
    ]],
];
$clesMasquees = ['retard_minutes', 'schema_tournoi'];
$valeurs = array_column($parametres, 'valeur', 'cle');
$descriptions = array_column($parametres, 'description', 'cle');
$connues = [];
foreach ($schema as [, $cles]) $connues = array_merge($connues, array_keys($cles));
$autres = array_diff(array_keys($valeurs), $connues, $clesMasquees);
if ($autres) {
    $schema['Avancé'] = ['Réglages techniques.', []];
    foreach ($autres as $cle) {
        // Libellé lisible : nom connu, sinon la description enregistrée en base, sinon la clé
        $libelle = $cle === 'points_max' ? 'Note maximale par manche' : (($descriptions[$cle] ?? '') ?: $cle);
        $schema['Avancé'][1][$cle] = ['text', $libelle, $libelle !== $cle ? '' : ($descriptions[$cle] ?? '')];
    }
}
$lecture = !can('parametres.modifier');
$ancre = fn(string $groupe): string => 'reglages-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $groupe) ?: $groupe)), '-');
$groupesAffiches = array_filter($schema, fn($g) => array_intersect(array_keys($g[1]), array_keys($valeurs)));
?>

<nav class="settings-nav" aria-label="Sections de la page">
    <?php foreach ($groupesAffiches as $groupe => $_): ?>
        <a href="#<?= e($ancre($groupe)) ?>"><?= icon($iconesGroupes[$groupe] ?? 'settings') ?><?= e($groupe === 'Appariement automatique' ? 'Appariement' : ($groupe === 'Affichage en direct' ? 'Affichage' : $groupe)) ?></a>
    <?php endforeach; ?>
    <a href="#heading-grades"><?= icon('medal') ?>Grades <span class="count"><?= count($grades) ?></span></a>
    <a href="#champs-joueurs"><?= icon('users') ?>Champs joueurs <span class="count"><?= count($champsJoueur) ?></span></a>
</nav>

<div class="grid grid-aside">
    <form method="post" action="parametres.php" class="wide stack" id="form-parametres" aria-label="Paramètres généraux" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="update_parametres">
        <?php if ($lecture): ?><div class="flash flash-info"><?= icon('lock') ?><span class="flash-body">Lecture seule : votre rôle ne permet pas de modifier les paramètres.</span></div><?php endif; ?>
        <?php foreach ($groupesAffiches as $groupe => [$intro, $cles]): ?>
            <section class="card settings-group" id="<?= e($ancre($groupe)) ?>">
                <header>
                    <span class="settings-group-icon" aria-hidden="true"><?= icon($iconesGroupes[$groupe] ?? 'settings') ?></span>
                    <div><h2><?= e($groupe) ?></h2><p><?= e($intro) ?></p></div>
                </header>
                <?php foreach ($cles as $cle => $def): if (!array_key_exists($cle, $valeurs)) continue;
                    [$type, $libelle, $aide] = $def;
                    $unite = $def[3] ?? ''; $min = $def[4] ?? null; $max = $def[5] ?? null;
                    $val = $valeurs[$cle];
                    $idc = 'param_' . $cle;
                ?>
                    <?php if ($type === 'bool'): ?>
                        <div class="setting-row is-switch">
                            <label class="switch">
                                <span class="switch-text"><strong><?= e($libelle) ?></strong><?php if ($aide): ?><span class="hint"><?= e($aide) ?></span><?php endif; ?></span>
                                <input type="checkbox" role="switch" name="param[<?= e($cle) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?> <?= $lecture ? 'disabled' : '' ?>>
                            </label>
                        </div>
                    <?php else:
                        $affiche = $type === 'ms' ? rtrim(rtrim(number_format((int)$val / 1000, 1, '.', ''), '0'), '.') : $val;
                    ?>
                        <div class="setting-row">
                            <label for="<?= e($idc) ?>" class="setting-label"><span><?= e($libelle) ?></span><?php if ($aide): ?><span class="hint"><?= e($aide) ?></span><?php endif; ?></label>
                            <div class="field">
                                <div class="<?= $unite ? 'input-suffix' : '' ?>">
                                    <input id="<?= e($idc) ?>" name="param[<?= e($cle) ?>]" value="<?= e($affiche) ?>" <?= $lecture ? 'disabled' : '' ?><?= $type !== 'text' && $min !== null ? ' aria-describedby="' . e($idc) . '_bornes"' : '' ?>
                                        <?php if ($type === 'text'): ?>type="text" maxlength="200"<?php else: ?>type="number" inputmode="decimal" step="<?= in_array($type, ['dec', 'ms'], true) ? '0.5' : '1' ?>"<?= $min !== null ? ' min="' . $min . '"' : '' ?><?= $max !== null ? ' max="' . $max . '"' : '' ?><?php endif; ?>>
                                    <?php if ($unite): ?><span class="suffix"><?= e($unite) ?></span><?php endif; ?>
                                </div>
                                <?php if ($type !== 'text' && $min !== null && $max !== null): ?>
                                    <small class="hint setting-bornes" id="<?= e($idc) ?>_bornes">De <?= e((string)$min) ?> à <?= e((string)$max) ?><?= $unite ? ' ' . e($unite) : '' ?></small>
                                <?php endif; ?>
                                <?php if ($type === 'ms'): ?><input type="hidden" name="unite[<?= e($cle) ?>]" value="s"><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <?php if (!$lecture): ?>
            <div class="sticky-save" data-etat="propre">
                <span class="sticky-save-etat grow" aria-live="polite">
                    <span class="js-etat-propre">Les changements s'appliquent dès l'enregistrement.</span>
                    <span class="js-etat-modifie" hidden><?= icon('alert-triangle') ?><span class="js-nb-modifs">Modifications</span> non enregistrées</span>
                </span>
                <button type="button" class="btn btn-ghost js-annuler" hidden>Annuler</button>
                <button type="submit" class="btn" title="Enregistrer (Ctrl+S)">Enregistrer les paramètres</button>
            </div>
        <?php endif; ?>
    </form>

    <section aria-labelledby="heading-grades">
        <div class="section-title">
            <h2 id="heading-grades">Grades</h2>
            <?php if (can('grades.gerer')): ?><a href="parametres.php?action=new_grade" class="btn btn-sm btn-secondary"><?= icon('plus') ?>Nouveau grade</a><?php endif; ?>
        </div>
        <p class="hint section-intro">Niveaux des joueurs, du plus bas au plus haut. Servent à l'appariement.</p>
        <div class="table-wrap">
            <table aria-labelledby="heading-grades">
                <thead><tr><th scope="col" class="col-ordre">Ordre</th><th scope="col">Grade</th><th scope="col" class="text-right">Joueurs</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($grades as $g):
                    $nbJ = (int)($joueursParGrade[$g['id']] ?? 0);
                    $menu = '';
                    if (can('grades.gerer')) {
                        $menu = '<a role="menuitem" href="parametres.php?action=edit_grade&id=' . (int)$g['id'] . '">' . icon('edit') . 'Modifier</a><div class="menu-sep" role="separator"></div>'
                            . boutonPost('parametres.php', 'delete_grade', ['id' => (int)$g['id']], 'Supprimer', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer le grade ' . $g['nom'] . ' ?', 'data-confirm-text' => ($nbJ ? $nbJ . ' joueur' . ($nbJ > 1 ? 's perdent' : ' perd') . ' simplement ce grade.' : 'Aucun joueur n\'a ce grade.') . ' Aucun combat n\'est affecté.', 'data-confirm-label' => 'Supprimer']);
                    }
                ?>
                    <tr>
                        <td class="tabular muted" data-label="Ordre"><?= (int)$g['ordre'] ?></td>
                        <td data-label="Grade"><span class="badge" style="background:<?= e($g['couleur'] ?: '#2a3358') ?>1f;color:<?= e($g['couleur'] ?: 'var(--text)') ?>;border-color:<?= e($g['couleur'] ?: 'var(--border)') ?>55;"><?= e($g['nom']) ?></span></td>
                        <td class="tabular text-right <?= $nbJ ? '' : 'muted' ?>" data-label="Joueurs"><?= $nbJ ?: '-' ?></td>
                        <td class="cell-actions"><?= menuActions($menu, 'Actions pour ' . $g['nom']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$grades): ?>
                    <tr><td colspan="4"><?= etatVide('Aucun grade', 'Ajoutez des grades pour les attribuer aux joueurs.', '', 'medal', 'compact') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section-title" id="champs-joueurs">
            <h2 id="heading-champs">Champs des joueurs</h2>
            <?php if (can('joueurs.champs')): ?><a href="parametres.php?action=new_champ" class="btn btn-sm btn-secondary"><?= icon('plus') ?>Nouveau champ</a><?php endif; ?>
        </div>
        <p class="hint section-intro">Informations en plus sur chaque fiche joueur (licence, taille, contact…). Elles apparaissent dans le formulaire, la liste et l'import / export CSV.</p>
        <div class="table-wrap">
            <table aria-labelledby="heading-champs">
                <thead><tr><th scope="col">Champ</th><th scope="col">Type</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($champsJoueur as $c):
                    $menu = '';
                    if (can('joueurs.champs')) {
                        $menu = '<a role="menuitem" href="parametres.php?action=edit_champ&id=' . (int)$c['id'] . '">' . icon('edit') . 'Modifier</a><div class="menu-sep" role="separator"></div>'
                            . boutonPost('parametres.php', 'delete_champ', ['id' => (int)$c['id']], 'Supprimer', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer le champ ' . $c['nom'] . ' ?', 'data-confirm-text' => 'Les valeurs saisies pour ce champ sur toutes les fiches joueurs seront effacées.', 'data-confirm-label' => 'Supprimer']);
                    }
                ?>
                    <tr>
                        <td><strong><?= e($c['nom']) ?></strong><?php if ($c['obligatoire']): ?> <span class="badge badge-obligatoire">Obligatoire</span><?php endif; ?>
                            <?php if ($c['type'] === 'liste'): ?><span class="sub-line"><?= e(implode(' · ', optionsChamp($c))) ?></span><?php endif; ?></td>
                        <td class="muted"><?= e(typesChampJoueur()[$c['type']] ?? $c['type']) ?></td>
                        <td class="cell-actions"><?= menuActions($menu, 'Actions pour ' . $c['nom']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$champsJoueur): ?>
                    <tr><td colspan="3"><?= etatVide('Aucun champ personnalisé', 'Ajoutez les informations dont vous avez besoin sur vos joueurs.', '', 'users', 'compact') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php if (!$lecture): ?>
<script nonce="<?= e(cspNonce()) ?>">
(function () {
    // Suivi des modifications : la ligne modifiée est signalée, la barre
    // d'enregistrement dit combien de réglages attendent, « Annuler » revient en arrière.
    const form = document.getElementById('form-parametres');
    if (!form) return;
    const barre = form.querySelector('.sticky-save');
    const champs = [...form.querySelectorAll('input[name^="param["]')];
    const valeur = i => (i.type === 'checkbox' ? i.checked : i.value);
    const initial = new Map(champs.map(i => [i, valeur(i)]));
    let dirty = false;

    function majEtat() {
        let n = 0;
        champs.forEach(i => {
            const modifie = valeur(i) !== initial.get(i);
            const ligne = i.closest('.setting-row');
            if (ligne) ligne.classList.toggle('is-modifie', modifie);
            if (modifie) n++;
        });
        dirty = n > 0;
        barre.dataset.etat = dirty ? 'modifie' : 'propre';
        barre.querySelector('.js-etat-propre').hidden = dirty;
        barre.querySelector('.js-etat-modifie').hidden = !dirty;
        barre.querySelector('.js-annuler').hidden = !dirty;
        barre.querySelector('.js-nb-modifs').textContent = n > 1 ? n + ' modifications' : '1 modification';
    }
    champs.forEach(i => i.addEventListener(i.type === 'checkbox' ? 'change' : 'input', majEtat));
    barre.querySelector('.js-annuler').addEventListener('click', () => {
        champs.forEach(i => { if (i.type === 'checkbox') i.checked = initial.get(i); else i.value = initial.get(i); });
        majEtat();
    });
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && !document.querySelector('dialog[open]')) {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
