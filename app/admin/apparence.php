<?php
/**
 * admin/apparence.php : thème de couleurs et logo de l'application.
 *  - Thème : Basique (sombre), Clair, Orange · blanc · bleu, ou un thème
 *    personnalisé (8 couleurs de base, le reste est calculé : includes/apparence.php).
 *  - Logos : on en envoie autant qu'on veut, ils restent enregistrés ;
 *    « Utiliser » change le logo de la barre, le favicon et l'icône de l'app.
 */
require_once __DIR__ . '/../config/config.php';
requirePermission('parametres.voir', 'parametres.modifier');
ensureLogosSchema($pdo);
ensureThemesSchema($pdo);

$peutModifier = can('parametres.modifier');
$themes = themesDisponibles($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigerPermissionAction('parametres.modifier', 'admin/apparence.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, merci de réessayer.');
        redirect('admin/apparence.php');
    }
    $fa = $_POST['form_action'] ?? '';
    $moi = currentUser()['username'];

    if ($fa === 'theme') {
        $t = (string)($_POST['theme'] ?? '');
        if (array_key_exists($t, $themes)) {
            setParametre($pdo, 'theme', $t);
            flash('success', 'Thème « ' . $themes[$t][0] . ' » appliqué à toute l\'application.');
        }
        redirect('admin/apparence.php');
    }

    // ---- THÈME PERSONNALISÉ : créer / modifier ----
    if ($fa === 'theme_enregistrer') {
        $idTheme = idPositif($_POST['id'] ?? '');
        $nom = texteBorne($_POST['nom_theme'] ?? '', 40) ?? '';
        $mode = ($_POST['mode'] ?? '') === 'clair' ? 'clair' : 'sombre';
        $couleurs = [];
        $erreur = $nom === '' ? 'Donnez un nom au thème.' : null;
        foreach (array_keys(COULEURS_THEME) as $cle) {
            $v = strtolower(trim((string)($_POST['c_' . $cle] ?? '')));
            if (!preg_match('/^#[0-9a-f]{6}$/', $v)) { $erreur = 'Couleur invalide : ' . COULEURS_THEME[$cle][0] . '.'; break; }
            $couleurs[$cle] = $v;
        }
        if ($erreur) {
            flash('error', $erreur);
            redirect('admin/apparence.php?theme=' . ($idTheme ?: 'nouveau') . '#h-editeur');
        }
        $json = json_encode($couleurs);
        if ($idTheme) {
            $pdo->prepare('UPDATE themes_perso SET nom = ?, mode = ?, couleurs = ? WHERE id = ?')
                ->execute([$nom, $mode, $json, $idTheme]);
        } else {
            $pdo->prepare('INSERT INTO themes_perso (nom, mode, couleurs, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$nom, $mode, $json, (int)currentUser()['id']]);
            $idTheme = (int)$pdo->lastInsertId();
        }
        $appliquer = isset($_POST['appliquer']);
        if ($appliquer) setParametre($pdo, 'theme', 'perso-' . $idTheme);
        $msg = 'Thème « ' . $nom . ' » enregistré' . ($appliquer ? ' et appliqué à toute l\'application.' : '.');
        if (contrasteCouleurs($couleurs['texte'], $couleurs['surface']) < 4.5) {
            flash('warning', $msg . ' Attention : le texte est peu lisible sur les cartes (contraste faible).', true);
        } else {
            flash('success', $msg);
        }
        error_log('[Sabre][apparence] thème perso ' . $idTheme . ' enregistré par ' . $moi);
        redirect('admin/apparence.php');
    }

    if ($fa === 'theme_supprimer') {
        $idTheme = idPositif($_POST['id'] ?? '');
        if ($idTheme) {
            if (getParametre($pdo, 'theme', '') === 'perso-' . $idTheme) setParametre($pdo, 'theme', 'basique');
            $pdo->prepare('DELETE FROM themes_perso WHERE id = ?')->execute([$idTheme]);
            flash('success', 'Thème supprimé.');
        }
        redirect('admin/apparence.php');
    }

    if ($fa === 'envoyer') {
        $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';
        $r = preparerLogo($_FILES['logo'] ?? []);
        if (!$r['ok']) {
            flash('error', $r['erreur']);
            redirect('admin/apparence.php');
        }
        if ($nom === '') $nom = pathinfo((string)($_FILES['logo']['name'] ?? 'Logo'), PATHINFO_FILENAME) ?: 'Logo';
        $nom = mb_substr($nom, 0, 60);
        $f = $r['formats'];
        $pdo->prepare('INSERT INTO logos (nom, largeur, hauteur, marque, f32, f180, f192, f512, maskable, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nom, $r['l'], $r['h'], $f['marque'], $f['f32'], $f['f180'], $f['f192'], $f['f512'], $f['maskable'], (int)currentUser()['id']]);
        $nouvelId = (int)$pdo->lastInsertId();
        if (isset($_POST['activer'])) {
            setParametre($pdo, 'logo_actif', (string)$nouvelId);
            flash('success', 'Logo « ' . $nom . ' » enregistré et utilisé partout (barre, onglet du navigateur, icône de l\'app).');
        } else {
            flash('success', 'Logo « ' . $nom . ' » enregistré. Cliquez sur « Utiliser » pour l\'activer.');
        }
        error_log('[Sabre][apparence] logo ' . $nouvelId . ' envoyé par ' . $moi);
        redirect('admin/apparence.php');
    }

    if ($fa === 'utiliser') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === 0) {
            setParametre($pdo, 'logo_actif', '0');
            flash('success', 'Logo d\'origine rétabli.');
        } else {
            $st = $pdo->prepare('SELECT nom FROM logos WHERE id = ?');
            $st->execute([$id]);
            if ($nom = $st->fetchColumn()) {
                setParametre($pdo, 'logo_actif', (string)$id);
                flash('success', 'Logo « ' . $nom . ' » utilisé partout.');
            }
        }
        redirect('admin/apparence.php');
    }

    if ($fa === 'supprimer') {
        $id = idPositif($_POST['id'] ?? '');
        if ($id) {
            if (getParametreInt($pdo, 'logo_actif', 0) === $id) setParametre($pdo, 'logo_actif', '0');
            $pdo->prepare('DELETE FROM logos WHERE id = ?')->execute([$id]);
            flash('success', 'Logo supprimé.');
        }
        redirect('admin/apparence.php');
    }
    redirect('admin/apparence.php');
}

$themeActuel = themeActif($pdo);
$persos = themesPerso($pdo);

// Éditeur de thème : ?theme=nouveau[&depart=clé] ou ?theme=ID
$editeur = null;
if ($peutModifier && isset($_GET['theme'])) {
    if ($_GET['theme'] === 'nouveau') {
        $depart = (string)($_GET['depart'] ?? $themeActuel);
        $couleursDepart = $persos[$depart]['couleurs'] ?? PALETTES_FOURNIES[$depart] ?? PALETTES_FOURNIES['basique'];
        $modeDepart = $persos[$depart]['mode'] ?? THEMES_FOURNIS[$depart][2] ?? 'sombre';
        $editeur = ['id' => 0, 'nom' => '', 'mode' => $modeDepart, 'couleurs' => $couleursDepart];
    } elseif ($idEdit = idPositif($_GET['theme'])) {
        $editeur = $persos['perso-' . $idEdit] ?? null;
    }
}
$logoActuel = logoActifId($pdo);
$logos = $pdo->query('SELECT id, nom, largeur, hauteur, created_at FROM logos ORDER BY created_at DESC')->fetchAll();
$gdOk = extension_loaded('gd');

$pageTitle = 'Apparence';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Apparence</h1>
        <p class="sub">Couleurs et logo de l'application, pour tous les visiteurs.</p>
    </div>
</div>

<section class="card mb-5" aria-labelledby="h-theme">
    <h2 id="h-theme">Thème</h2>
    <p class="hint">S'applique à tout le site, à l'écran de salle et à l'application installée.</p>
    <form method="post" action="apparence.php">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="theme">
        <div class="theme-choix" role="radiogroup" aria-label="Thème">
            <?php foreach ($themes as $cle => [$nom, $desc, $mode, $barre, $apercu]): ?>
                <label class="theme-carte">
                    <input type="radio" name="theme" value="<?= e($cle) ?>" <?= $cle === $themeActuel ? 'checked' : '' ?> <?= $peutModifier ? '' : 'disabled' ?>>
                    <span class="theme-apercu" aria-hidden="true">
                        <?php foreach ($apercu as $c): ?><span style="background:<?= e($c) ?>"></span><?php endforeach; ?>
                    </span>
                    <strong><?= e($nom) ?></strong>
                    <p class="hint"><?= e($desc) ?></p>
                    <?php if ($cle === $themeActuel): ?><span class="badge badge-success badge-dot">Actuel</span><?php endif; ?>
                    <?php if ($peutModifier): ?>
                        <span class="theme-actions">
                            <?php if (isset($persos[$cle])): ?>
                                <a class="btn btn-ghost btn-sm" href="apparence.php?theme=<?= (int)$persos[$cle]['id'] ?>#h-editeur"><?= icon('edit') ?>Modifier</a>
                                <button type="submit" form="suppr-theme-<?= (int)$persos[$cle]['id'] ?>" class="btn btn-ghost btn-sm" aria-label="Supprimer le thème <?= e($nom) ?>"
                                        data-confirm="Supprimer le thème « <?= e($nom) ?> » ?"
                                        data-confirm-text="<?= $cle === $themeActuel ? 'C\'est le thème utilisé : le thème Basique reviendra.' : 'Il sera retiré de la liste.' ?>"
                                        data-confirm-label="Supprimer"><?= icon('trash') ?></button>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-sm" href="apparence.php?theme=nouveau&amp;depart=<?= e($cle) ?>#h-editeur" title="Créer un thème personnalisé à partir de celui-ci"><?= icon('plus') ?>Copier</a>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if ($peutModifier): ?>
            <div class="btn-row mt-5">
                <button type="submit" class="btn">Appliquer le thème</button>
                <a class="btn btn-secondary" href="apparence.php?theme=nouveau#h-editeur"><?= icon('plus') ?>Créer un thème</a>
            </div>
        <?php endif; ?>
    </form>
</section>

<?php foreach ($persos as $t): ?>
    <form method="post" action="apparence.php" id="suppr-theme-<?= (int)$t['id'] ?>" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="theme_supprimer">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
    </form>
<?php endforeach; ?>

<?php if ($editeur): ?>
<section class="card mb-5" aria-labelledby="h-editeur">
    <div class="card-head">
        <h2 id="h-editeur"><?= $editeur['id'] ? 'Modifier le thème « ' . e($editeur['nom']) . ' »' : 'Nouveau thème' ?></h2>
        <a class="btn btn-ghost btn-sm" href="apparence.php">Fermer</a>
    </div>
    <p class="hint">Choisissez 8 couleurs : les nuances (survols, bordures, textes secondaires, fonds teintés) sont calculées
        automatiquement, avec un texte toujours lisible sur les boutons.</p>
    <form method="post" action="apparence.php" class="theme-editeur" data-theme-editeur>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="theme_enregistrer">
        <input type="hidden" name="id" value="<?= (int)$editeur['id'] ?>">
        <div class="theme-editeur-champs">
            <div class="grid grid-2">
                <div class="field">
                    <label for="nom_theme">Nom du thème</label>
                    <input type="text" id="nom_theme" name="nom_theme" required maxlength="40" value="<?= e($editeur['nom']) ?>" placeholder="ex. Couleurs du club">
                </div>
                <div class="field">
                    <label for="mode">Ambiance</label>
                    <select id="mode" name="mode">
                        <option value="sombre" <?= $editeur['mode'] !== 'clair' ? 'selected' : '' ?>>Sombre (fond foncé)</option>
                        <option value="clair" <?= $editeur['mode'] === 'clair' ? 'selected' : '' ?>>Clair (fond blanc)</option>
                    </select>
                </div>
            </div>
            <div class="theme-couleurs">
                <?php foreach (COULEURS_THEME as $cle => [$lib, $aide]): ?>
                    <label class="theme-couleur">
                        <input type="color" name="c_<?= e($cle) ?>" value="<?= e($editeur['couleurs'][$cle]) ?>" data-couleur="<?= e($cle) ?>">
                        <span><strong><?= e($lib) ?></strong><small class="hint"><?= e($aide) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="field">
                <label class="switch">
                    <span class="switch-text"><strong>L'appliquer tout de suite</strong><span class="hint">À tout le site, à l'écran de salle et au portail du Wi-Fi.</span></span>
                    <input type="checkbox" name="appliquer" role="switch" checked>
                </label>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn">Enregistrer le thème</button>
                <a class="btn btn-secondary" href="apparence.php">Annuler</a>
            </div>
        </div>
        <div class="theme-previsu" data-theme-previsu role="img" aria-label="Aperçu du thème">
            <div class="tp-barre"><span class="tp-logo"></span><span class="tp-nom">Aperçu</span><span class="tp-lien">Direct</span></div>
            <div class="tp-page">
                <div class="tp-carte">
                    <div class="tp-titre">Combat en cours</div>
                    <div class="tp-texte2">Texte secondaire</div>
                    <div class="tp-duel"><span class="tp-p1">Aka 3</span><span class="tp-p2">2 Ao</span></div>
                    <div class="tp-btns"><span class="tp-btn">Action</span><span class="tp-badge">En direct</span></div>
                </div>
            </div>
        </div>
    </form>
</section>
<script nonce="<?= e(cspNonce()) ?>">
(() => {
    // Aperçu en direct : recopie les couleurs choisies dans le petit écran d'aperçu
    const form = document.querySelector('[data-theme-editeur]');
    const apercu = form && form.querySelector('[data-theme-previsu]');
    if (!apercu) return;
    const lum = (hex) => {
        const c = [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16) / 255)
            .map(v => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    // Texte posé sur une couleur : blanc ou presque noir, le plus contrasté
    const sur = (hex) => 1.05 / (lum(hex) + 0.05) >= (lum(hex) + 0.05) / 0.0546 ? '#ffffff' : '#0b0d10';
    const maj = () => {
        form.querySelectorAll('[data-couleur]').forEach(i => {
            apercu.style.setProperty('--tp-' + i.dataset.couleur, i.value);
            apercu.style.setProperty('--tp-sur-' + i.dataset.couleur, sur(i.value));
        });
    };
    form.addEventListener('input', maj);
    maj();
})();
</script>
<?php endif; ?>

<section class="card mb-5" aria-labelledby="h-logo">
    <div class="card-head">
        <h2 id="h-logo">Logo</h2>
        <div class="logo-icones" aria-label="Aperçu des icônes actuelles">
            <?php if ($logoActuel): ?>
                <img src="<?= e(urlLogo($pdo, 'f32')) ?>" width="16" height="16" alt="Favicon" title="Onglet du navigateur">
                <img src="<?= e(urlLogo($pdo, 'f192')) ?>" width="40" height="40" alt="Icône de l'app" title="Icône de l'application">
            <?php else: ?>
                <img src="<?= BASE_URL ?>assets/pwa/favicon-32.png" width="16" height="16" alt="Favicon" title="Onglet du navigateur">
                <img src="<?= BASE_URL ?>assets/pwa/icon-192.png" width="40" height="40" alt="Icône de l'app" title="Icône de l'application">
            <?php endif; ?>
        </div>
    </div>
    <p class="hint">Le logo choisi remplace la lame dans la barre du site, le pied de page, la page de connexion,
        l'écran de salle, l'icône de l'onglet du navigateur et l'icône de l'application installée.
        Une app déjà installée peut mettre jusqu'à un jour à changer d'icône.</p>

    <?php if ($peutModifier): ?>
        <?php if (!$gdOk): ?>
            <div class="flash flash-warning mb-5" role="status"><?= icon('alert-triangle') ?><span class="flash-body">Le module d'images (GD) n'est pas installé : relancez <code>docker compose up -d --build</code> pour pouvoir envoyer des logos.</span></div>
        <?php endif; ?>
        <form method="post" action="apparence.php" enctype="multipart/form-data" class="mb-5">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="envoyer">
            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
            <div class="grid grid-2">
                <div class="field">
                    <label for="logo">Nouveau logo</label>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" required <?= $gdOk ? '' : 'disabled' ?> aria-describedby="logo-hint">
                    <small class="hint" id="logo-hint">PNG (idéalement avec fond transparent), JPEG ou WebP, 2 Mo maximum. Un logo carré donne les plus belles icônes.</small>
                </div>
                <div class="field">
                    <label for="nom">Nom <span class="req">(facultatif)</span></label>
                    <input type="text" id="nom" name="nom" maxlength="60" placeholder="ex. Logo du club" <?= $gdOk ? '' : 'disabled' ?>>
                </div>
            </div>
            <div class="field">
                <label class="switch">
                    <span class="switch-text"><strong>L'utiliser tout de suite</strong><span class="hint">Sinon il est seulement ajouté à la liste ci-dessous.</span></span>
                    <input type="checkbox" name="activer" role="switch" checked <?= $gdOk ? '' : 'disabled' ?>>
                </label>
            </div>
            <button type="submit" class="btn" <?= $gdOk ? '' : 'disabled' ?>><?= icon('upload') ?>Envoyer le logo</button>
        </form>
    <?php endif; ?>

    <div class="logos-grille">
        <div class="logo-carte <?= $logoActuel === 0 ? 'is-actif' : '' ?>">
            <div class="logo-apercu"><span class="brand-blade" aria-hidden="true"></span></div>
            <span class="nom">Lame d'origine</span>
            <div class="logo-carte-pied">
                <?php if ($logoActuel === 0): ?>
                    <span class="badge badge-success badge-dot">Utilisé</span>
                <?php elseif ($peutModifier): ?>
                    <form method="post" action="apparence.php">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="form_action" value="utiliser">
                        <input type="hidden" name="id" value="0">
                        <button type="submit" class="btn btn-secondary btn-sm">Utiliser</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php foreach ($logos as $l): $actif = (int)$l['id'] === $logoActuel; ?>
            <div class="logo-carte <?= $actif ? 'is-actif' : '' ?>">
                <div class="logo-apercu"><img src="<?= BASE_URL ?>logo.php?id=<?= (int)$l['id'] ?>&amp;f=marque" alt="<?= e($l['nom']) ?>" loading="lazy"></div>
                <span class="nom" title="<?= e($l['nom']) ?>"><?= e($l['nom']) ?></span>
                <span class="hint"><?= (int)$l['largeur'] ?> × <?= (int)$l['hauteur'] ?> px · <?= formatDateFr($l['created_at']) ?></span>
                <div class="logo-carte-pied">
                    <?php if ($actif): ?>
                        <span class="badge badge-success badge-dot">Utilisé</span>
                    <?php elseif ($peutModifier): ?>
                        <form method="post" action="apparence.php">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="form_action" value="utiliser">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Utiliser</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($peutModifier): ?>
                        <form method="post" action="apparence.php">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="form_action" value="supprimer">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" aria-label="Supprimer le logo <?= e($l['nom']) ?>"
                                    data-confirm="Supprimer le logo « <?= e($l['nom']) ?> » ?"
                                    data-confirm-text="<?= $actif ? 'C\'est le logo utilisé : la lame d\'origine reviendra.' : 'Il sera retiré de la liste.' ?>"
                                    data-confirm-label="Supprimer"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
