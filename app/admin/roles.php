<?php
/**
 * admin/roles.php : Rôles & permissions.
 *
 *   - Créer des rôles personnalisés (ex. « Arbitre chef », « Bénévole sabres », « Organisateur »)
 *   - Cocher / décocher chaque permission, section par section
 *   - Dupliquer les permissions d'un rôle existant
 *   - Supprimer un rôle (les utilisateurs sont réaffectés à un autre rôle)
 *
 * Le rôle « admin » a toujours toutes les permissions (verrouillé).
 * Un non-admin qui gère les rôles ne peut accorder que des permissions qu'il possède lui-même.
 */
require_once __DIR__ . '/../config/config.php';
requirePermission('roles.voir', 'roles.gerer');

$action = $_GET['action'] ?? 'list';
$slugParam = (texteBorne($_GET['slug'] ?? '', 50) ?? '');
$catalogue = permissionsCatalogue();
$toutes = toutesLesPermissions();

function slugifierRole(string $txt): string
{
    $txt = trim($txt);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($t === false || $t === '') $t = $txt;
    $t = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $t));
    return substr(trim($t, '_'), 0, 50);
}

function couleurValide(string $c): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#7c8cff';
}

/** Permissions que l'utilisateur connecté a le droit d'accorder / retirer. */
function permissionsDelegables(): array
{
    return estSuperAdmin() ? toutesLesPermissions() : mesPermissions();
}

/** Enregistre les permissions d'un rôle en respectant ce que l'utilisateur peut déléguer. */
function enregistrerPermissions(PDO $pdo, string $slug, array $cochees): void
{
    $delegables = permissionsDelegables();
    $cochees = array_values(array_intersect($cochees, toutesLesPermissions()));
    $actuelles = getRolePermissions($pdo, $slug);

    // Les permissions non délégables restent telles quelles
    $conservees = array_diff($actuelles, $delegables);
    $finales = array_unique(array_merge($conservees, array_intersect($cochees, $delegables)));

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM role_permissions WHERE role_slug = ?')->execute([$slug]);
    $ins = $pdo->prepare('INSERT INTO role_permissions (role_slug, permission) VALUES (?, ?)');
    foreach ($finales as $p) $ins->execute([$slug, $p]);
    $pdo->commit();
}

/* ─────────────────────────── ACTIONS POST ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigerPermissionAction('roles.gerer', 'admin/roles.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, veuillez réessayer.');
        redirect('admin/roles.php');
    }
    $fa = $_POST['form_action'] ?? '';

    // ---- Créer un rôle ----
    if ($fa === 'create') {
        $nom = texteBorne($_POST['nom'] ?? '', 100) ?? '';
        $slugSaisi = texteBorne($_POST['slug'] ?? '', 50) ?? '';
        $slug = slugifierRole($slugSaisi !== '' ? $slugSaisi : $nom);
        $desc = texteBorne($_POST['description'] ?? '', 255) ?? '';
        $couleur = couleurValide($_POST['couleur'] ?? '');
        $copie = (texteBorne($_POST['copier_depuis'] ?? '', 50) ?? '');

        if ($nom === '' || $slug === '') {
            flash('error', 'Le nom du rôle est obligatoire.');
            stashOldInput($_POST);
            redirect('admin/roles.php?action=new');
        }
        if (getRole($pdo, $slug)) {
            flash('error', 'Un rôle avec l\'identifiant « ' . $slug . ' » existe déjà.');
            stashOldInput($_POST);
            redirect('admin/roles.php?action=new');
        }
        $ordre = (int)$pdo->query('SELECT COALESCE(MAX(ordre),0)+1 FROM roles')->fetchColumn();
        $pdo->prepare('INSERT INTO roles (slug, nom, description, couleur, systeme, ordre) VALUES (?,?,?,?,0,?)')
            ->execute([$slug, $nom, $desc ?: null, $couleur, $ordre]);

        if ($copie !== '' && getRole($pdo, $copie)) {
            enregistrerPermissions($pdo, $slug, getRolePermissions($pdo, $copie));
        }
        flash('success', 'Rôle « ' . $nom . ' » créé. Coche maintenant ses permissions.');
        redirect('admin/roles.php?action=edit&slug=' . urlencode($slug));
    }

    // ---- Modifier un rôle (infos + permissions) ----
    if ($fa === 'update') {
        $slug = (texteBorne($_POST['slug'] ?? '', 50) ?? '');
        $role = getRole($pdo, $slug);
        if (!$role) { flash('error', 'Rôle introuvable.'); redirect('admin/roles.php'); }

        $nom = texteBorne($_POST['nom'] ?? '', 100) ?? $role['nom'];
        $desc = texteBorne($_POST['description'] ?? '', 255) ?? '';
        $couleur = couleurValide($_POST['couleur'] ?? $role['couleur']);
        $ordre = max(-1000, min(1000, (int)($_POST['ordre'] ?? $role['ordre'])));

        if ($slug === ROLE_SUPER_ADMIN && !estSuperAdmin()) {
            flash('error', 'Seul un administrateur peut modifier le rôle administrateur.');
            redirect('admin/roles.php');
        }

        $pdo->prepare('UPDATE roles SET nom = ?, description = ?, couleur = ?, ordre = ? WHERE slug = ?')
            ->execute([$nom, $desc ?: null, $couleur, $ordre, $slug]);

        if ($slug !== ROLE_SUPER_ADMIN) {
            enregistrerPermissions($pdo, $slug, array_filter((array)($_POST['permissions'] ?? []), 'is_string'));
        }
        flash('success', 'Rôle « ' . $nom . ' » enregistré. Les changements s\'appliquent immédiatement aux utilisateurs concernés.');
        redirect('admin/roles.php?action=edit&slug=' . urlencode($slug));
    }

    // ---- Supprimer un rôle ----
    if ($fa === 'delete') {
        $slug = (texteBorne($_POST['slug'] ?? '', 50) ?? '');
        $role = getRole($pdo, $slug);
        $cible = (texteBorne($_POST['reaffecter'] ?? '', 50) ?? 'public');
        if (!$role) { flash('error', 'Rôle introuvable.'); redirect('admin/roles.php'); }
        if ($role['systeme']) { flash('error', 'Les rôles système ne peuvent pas être supprimés (seulement modifiés).'); redirect('admin/roles.php'); }
        if ($cible === $slug || !getRole($pdo, $cible) || ($cible === ROLE_SUPER_ADMIN && !estSuperAdmin())) $cible = 'public';

        $pdo->beginTransaction();
        $st = $pdo->prepare('UPDATE users SET role = ? WHERE role = ?');
        $st->execute([$cible, $slug]);
        $nb = $st->rowCount();
        $pdo->prepare('DELETE FROM roles WHERE slug = ?')->execute([$slug]);
        $pdo->commit();
        flash('success', 'Rôle « ' . $role['nom'] . ' » supprimé.' . ($nb ? " $nb utilisateur(s) réaffecté(s) au rôle « " . nomRole($pdo, $cible) . ' ».' : ''));
        redirect('admin/roles.php');
    }

    redirect('admin/roles.php');
}

/* ─────────────────────────── DONNÉES ─────────────────────────── */
$roles = getRoles($pdo);
$permsParRole = [];
foreach ($roles as $r) $permsParRole[$r['slug']] = getRolePermissions($pdo, $r['slug']);

$editRole = null;
if ($action === 'edit' && $slugParam !== '') {
    $editRole = getRole($pdo, $slugParam);
    if (!$editRole) { flash('error', 'Rôle introuvable.'); redirect('admin/roles.php'); }
}
if ($action === 'new' && !can('roles.gerer')) $action = 'list';

$old = oldInput();
$peutGerer = can('roles.gerer');
$delegables = permissionsDelegables();

$pageTitle = 'Rôles & permissions';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1><?= $action === 'edit' && $editRole ? 'Rôle : ' . e($editRole['nom']) : 'Rôles et permissions' ?></h1>
        <p class="sub"><?= $action === 'edit' && $editRole ? 'Ce que les comptes ayant ce rôle peuvent voir et faire.' : 'Chaque compte a un rôle ; chaque rôle ouvre un ensemble de permissions.' ?></p>
    </div>
    <?php if (!($action === 'edit' && $editRole)): ?>
    <div class="btn-row">
        <?php if (can('users.voir')): ?><a href="users.php" class="btn btn-secondary"><?= icon('users') ?>Comptes</a><?php endif; ?>
        <?php if ($peutGerer): ?><a href="roles.php?action=new" class="btn"><?= icon('plus') ?>Nouveau rôle</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($action === 'edit' && $editRole):
    $slug = $editRole['slug'];
    $estAdmin = $slug === ROLE_SUPER_ADMIN;
    $mesCoches = $permsParRole[$slug];
    $lectureSeule = !$peutGerer || ($estAdmin && !estSuperAdmin());
?>
<?php /* ═════════════ ÉDITION D'UN RÔLE ═════════════ */ ?>
<a href="roles.php" class="back-nav"><?= icon('chevron-left') ?> Tous les rôles</a>
<form method="post" action="roles.php" id="role-form" class="wide">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="form_action" value="update">
    <input type="hidden" name="slug" value="<?= e($slug) ?>">

    <div class="card mb-5">
        <div class="card-head">
            <h2 class="h-inline"><span class="js-role-badge"><?= badgeRole($pdo, $slug) ?></span> <code><?= e($slug) ?></code></h2>
            <span class="hint"><?= (int)$editRole['nb_users'] ?> compte<?= (int)$editRole['nb_users'] > 1 ? 's' : '' ?>
                <?php if (can('users.voir') && (int)$editRole['nb_users'] > 0): ?>· <a href="users.php?role=<?= e($slug) ?>">voir la liste</a><?php endif; ?></span>
        </div>
        <div class="grid grid-2">
            <div class="field">
                <label for="nom">Nom affiché</label>
                <input type="text" id="nom" name="nom" value="<?= e($editRole['nom']) ?>" <?= $lectureSeule ? 'disabled' : '' ?> required>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="couleur">Couleur</label>
                    <input type="color" id="couleur" name="couleur" value="<?= e($editRole['couleur']) ?>" <?= $lectureSeule ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label for="ordre">Ordre d'affichage</label>
                    <input type="number" id="ordre" name="ordre" value="<?= (int)$editRole['ordre'] ?>" <?= $lectureSeule ? 'disabled' : '' ?>>
                </div>
            </div>
        </div>
        <div class="field mb-0">
            <label for="description">Description</label>
            <input type="text" id="description" name="description" value="<?= e($editRole['description'] ?? '') ?>" <?= $lectureSeule ? 'disabled' : '' ?> maxlength="255" placeholder="À quoi sert ce rôle ? ex. Bénévoles qui préparent les sabres">
        </div>
    </div>

    <div class="section-title perm-title">
        <h2>Permissions</h2>
        <span class="perm-total"><strong class="js-total"><?= $estAdmin ? count($toutes) : count(array_intersect($toutes, $mesCoches)) ?></strong> / <?= count($toutes) ?> activées</span>
    </div>
    <div class="perm-toolbar">
        <div class="search input-icon">
            <?= icon('search') ?>
            <input type="search" id="perm-filtre" placeholder="Chercher une permission…" aria-label="Chercher une permission" autocomplete="off">
        </div>
        <?php if (!$estAdmin && !$lectureSeule): ?>
            <div class="perm-toolbar-actions">
                <label class="visually-hidden" for="copier-role">Reprendre les permissions d'un autre rôle</label>
                <select id="copier-role" class="perm-copier">
                    <option value="">Reprendre les permissions de…</option>
                    <?php foreach ($roles as $r): if ($r['slug'] === $slug) continue; ?>
                        <option value="<?= e($r['slug']) ?>"><?= e($r['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" data-tout="1"><?= icon('check') ?>Tout cocher</button>
                <button type="button" class="btn btn-ghost btn-sm" data-tout="0"><?= icon('x') ?>Tout décocher</button>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($estAdmin): ?>
        <div class="flash flash-info" role="note"><?= icon('lock') ?> Le rôle Administrateur possède <strong>toutes</strong> les permissions et ne peut pas être restreint (pour éviter de se retrouver bloqué dehors).</div>
    <?php elseif (!estSuperAdmin() && $peutGerer): ?>
        <p class="hint">Les permissions grisées sont celles que votre propre rôle ne possède pas : vous ne pouvez pas les accorder ni les retirer.</p>
    <?php endif; ?>

    <div class="perm-groups">
    <?php foreach ($catalogue as $groupe => $perms):
        $gid = 'g' . substr(md5($groupe), 0, 6);
        $nbCoches = $estAdmin ? count($perms) : count(array_intersect(array_keys($perms), $mesCoches));
    ?>
        <div class="card perm-group" data-group="<?= $gid ?>">
            <div class="perm-group-head">
                <h4><?= e($groupe) ?></h4>
                <span class="perm-count"><span class="js-count"><?= $nbCoches ?></span>/<?= count($perms) ?></span>
                <?php if (!$estAdmin && !$lectureSeule): ?>
                    <label class="perm-item perm-all" title="Tout cocher ou décocher dans cette section">
                        <input type="checkbox" class="js-group-toggle" <?= $nbCoches === count($perms) ? 'checked' : '' ?>> <span class="hint">Tout</span>
                    </label>
                <?php endif; ?>
            </div>
            <?php foreach ($perms as $cle => [$libelle, $desc]):
                $coche = in_array($cle, $mesCoches, true);
                $desactive = $estAdmin || $lectureSeule || !in_array($cle, $delegables, true);
            ?>
                <label class="perm-item<?= $desactive && !$estAdmin ? ' disabled' : '' ?>" data-recherche="<?= e(mb_strtolower($groupe . ' ' . $libelle . ' ' . $desc . ' ' . $cle)) ?>"
                    <?= $desactive && !$estAdmin && !$lectureSeule ? 'title="Votre rôle ne possède pas cette permission : vous ne pouvez pas la modifier."' : '' ?>>
                    <input type="checkbox" name="permissions[]" value="<?= e($cle) ?>" class="js-perm"
                        <?= $coche || $estAdmin ? 'checked' : '' ?> <?= $desactive ? 'disabled' : '' ?>>
                    <span><?= e($libelle) ?><small><?= e($desc) ?></small></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="perm-aucune hint" hidden>Aucune permission ne correspond à cette recherche.</p>

    <?php if (!$lectureSeule): ?>
    <div class="sticky-save" data-etat="propre">
        <span class="sticky-save-etat grow" aria-live="polite">
            <span class="js-etat-propre">Aucune modification</span>
            <span class="js-etat-modifie" hidden><?= icon('alert-triangle') ?>Modifications non enregistrées</span>
        </span>
        <a href="roles.php" class="btn btn-secondary">Annuler</a>
        <button type="submit" class="btn" title="Enregistrer (Ctrl+S)">Enregistrer le rôle</button>
    </div>
    <?php endif; ?>
</form>

<?php if ($peutGerer && !$editRole['systeme']): ?>
<div class="card card-danger mt-6">
    <h3>Supprimer ce rôle</h3>
    <p class="hint">Ses comptes passent sur le rôle choisi ci-dessous.</p>
    <form method="post" action="roles.php" class="wide inline-form">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="delete">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <div class="field">
            <label for="reaffecter">Réaffecter ses <?= (int)$editRole['nb_users'] ?> utilisateur(s) au rôle</label>
            <select id="reaffecter" name="reaffecter">
                <?php foreach ($roles as $r): if ($r['slug'] === $slug || ($r['slug'] === ROLE_SUPER_ADMIN && !estSuperAdmin())) continue; ?>
                    <option value="<?= e($r['slug']) ?>" <?= $r['slug'] === 'public' ? 'selected' : '' ?>><?= e($r['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-danger" data-confirm="Supprimer le rôle « <?= e($editRole['nom']) ?> » ?" data-confirm-text="Action définitive. Ses comptes changent de rôle." data-confirm-label="Supprimer"><?= icon('trash') ?>Supprimer le rôle</button>
    </form>
</div>
<?php endif; ?>

<script nonce="<?= e(cspNonce()) ?>">
(function () {
    const form = document.getElementById('role-form');
    const permsDesRoles = <?= json_encode((object)array_map('array_values', [ROLE_SUPER_ADMIN => $toutes] + $permsParRole), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const boxes = [...document.querySelectorAll('.js-perm')];
    // État initial : « Modifications non enregistrées » seulement s'il y a une vraie différence
    const initial = new Map(boxes.map(b => [b, b.checked]));
    const champs = [...form.querySelectorAll('input[name=nom], input[name=description], input[name=couleur], input[name=ordre]')];
    const initialChamps = new Map(champs.map(i => [i, i.value]));

    function majCompteurs() {
        document.querySelectorAll('.perm-group').forEach(g => {
            const bs = g.querySelectorAll('.js-perm');
            const n = [...bs].filter(b => b.checked).length;
            g.querySelector('.js-count').textContent = n;
            g.classList.toggle('is-vide', n === 0);
            const t = g.querySelector('.js-group-toggle');
            if (t) { t.checked = n === bs.length; t.indeterminate = n > 0 && n < bs.length; }
        });
        const total = document.querySelector('.js-total');
        if (total) total.textContent = boxes.filter(b => b.checked).length;
    }

    let dirty = false;
    function majEtat() {
        dirty = boxes.some(b => b.checked !== initial.get(b)) || champs.some(i => i.value !== initialChamps.get(i));
        const barre = document.querySelector('.sticky-save');
        if (!barre) return;
        barre.dataset.etat = dirty ? 'modifie' : 'propre';
        barre.querySelector('.js-etat-propre').hidden = dirty;
        barre.querySelector('.js-etat-modifie').hidden = !dirty;
    }
    const changement = () => { majCompteurs(); majEtat(); };

    window.toutCocher = function (v) {
        boxes.filter(b => !b.disabled && !b.closest('.perm-item').hidden).forEach(b => b.checked = v);
        changement();
    };
    document.querySelectorAll('[data-tout]').forEach(b => b.addEventListener('click', () => window.toutCocher(b.dataset.tout === '1')));
    document.querySelectorAll('.js-group-toggle').forEach(t => t.addEventListener('change', () => {
        t.closest('.perm-group').querySelectorAll('.js-perm:not(:disabled)').forEach(b => b.checked = t.checked);
        changement();
    }));
    boxes.forEach(b => b.addEventListener('change', changement));
    champs.forEach(i => i.addEventListener('input', majEtat));

    // Reprendre les permissions d'un autre rôle (seulement celles qu'on peut modifier)
    const copier = document.getElementById('copier-role');
    if (copier) copier.addEventListener('change', () => {
        const source = permsDesRoles[copier.value];
        if (source) {
            boxes.filter(b => !b.disabled).forEach(b => b.checked = source.includes(b.value));
            changement();
            window.Toast && window.Toast.info('Permissions de « ' + copier.selectedOptions[0].textContent + ' » reprises. Vérifiez puis enregistrez.');
        }
        copier.value = '';
    });

    // Aperçu du badge pendant la saisie du nom et de la couleur
    const nom = document.getElementById('nom'), couleur = document.getElementById('couleur');
    const badge = document.querySelector('.js-role-badge .badge');
    function majBadge() {
        if (!badge) return;
        if (nom && nom.value.trim()) badge.textContent = nom.value.trim();
        if (couleur && /^#[0-9a-f]{6}$/i.test(couleur.value)) {
            badge.style.background = couleur.value + '22';
            badge.style.color = couleur.value;
            badge.style.borderColor = couleur.value + '66';
        }
    }
    [nom, couleur].forEach(i => i && i.addEventListener('input', majBadge));

    // Recherche instantanée dans les permissions
    const filtre = document.getElementById('perm-filtre');
    const aucune = document.querySelector('.perm-aucune');
    if (filtre) filtre.addEventListener('input', () => {
        const q = filtre.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        let visibles = 0;
        document.querySelectorAll('.perm-group').forEach(g => {
            let n = 0;
            g.querySelectorAll('.perm-item[data-recherche]').forEach(it => {
                const txt = it.dataset.recherche.normalize('NFD').replace(/[̀-ͯ]/g, '');
                it.hidden = q !== '' && !txt.includes(q);
                if (!it.hidden) n++;
            });
            g.hidden = n === 0;
            visibles += n;
        });
        if (aucune) aucune.hidden = visibles > 0;
    });

    // Ctrl+S : enregistrer
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && document.querySelector('.sticky-save')) {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    majCompteurs();
})();
</script>

<?php else: ?>
<?php /* ═════════════ LISTE DES RÔLES ═════════════ */ ?>

<?php if ($action === 'new'): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-role-title">
    <form method="post" action="roles.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="create">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-role-title"><?= icon('plus') ?> Nouveau rôle</h3>
            <a href="roles.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="nom">Nom du rôle</label>
                <input type="text" id="nom" name="nom" required maxlength="100" placeholder="ex. Arbitre chef, Organisateur, Bénévole sabres" value="<?= e($old['nom'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="slug">Identifiant technique <span class="req">(facultatif)</span></label>
                <input type="text" id="slug" name="slug" maxlength="50" pattern="[a-z0-9_]+" placeholder="arbitre_chef" value="<?= e($old['slug'] ?? '') ?>" class="mono" data-pattern-msg="Minuscules sans accent, chiffres et « _ » uniquement.">
                <small class="hint">Généré depuis le nom si vide.</small>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" maxlength="255" value="<?= e($old['description'] ?? '') ?>">
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="couleur">Couleur</label>
                    <input type="color" id="couleur" name="couleur" value="<?= e($old['couleur'] ?? '#f5b942') ?>">
                </div>
                <div class="field">
                    <label for="copier_depuis">Partir des permissions de</label>
                    <select id="copier_depuis" name="copier_depuis">
                        <option value="">Aucune (rôle vide)</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= e($r['slug']) ?>"><?= e($r['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <a href="roles.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn">Créer et configurer</button>
        </div>
    </form>
</dialog>
<script nonce="<?= e(cspNonce()) ?>">
(function(){
    const nom = document.getElementById('nom'), slug = document.getElementById('slug');
    let touched = slug.value !== '';
    slug.addEventListener('input', () => touched = true);
    nom.addEventListener('input', () => {
        if (touched) return;
        slug.value = nom.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 50);
    });
})();
</script>
<?php endif; ?>

<div class="roles-grid">
<?php foreach ($roles as $r): $nbP = count($permsParRole[$r['slug']]); ?>
    <div class="card role-card">
        <h3><?= badgeRole($pdo, $r['slug']) ?>
            <?php if ($r['systeme']): ?><span class="hint" title="Rôle système : modifiable mais non supprimable"><?= icon('lock') ?></span><?php endif; ?>
        </h3>
        <p><?= e($r['description'] ?: 'Pas de description.') ?></p>
        <p class="meta"><?= (int)$r['nb_users'] ?> compte<?= (int)$r['nb_users'] > 1 ? 's' : '' ?> · <?= $r['slug'] === ROLE_SUPER_ADMIN ? 'toutes les' : $nbP . '/' . count($toutes) ?> permissions</p>
        <div class="actions-inline">
            <a href="roles.php?action=edit&slug=<?= urlencode($r['slug']) ?>" class="btn btn-sm btn-secondary">
                <?= icon($peutGerer ? 'edit' : 'eye') ?><?= $peutGerer ? 'Configurer' : 'Voir' ?>
            </a>
            <?php if (can('users.voir')): ?><a href="users.php?role=<?= e($r['slug']) ?>" class="btn btn-ghost btn-sm"><?= icon('users') ?>Comptes</a><?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php /* ── Vue d'ensemble : matrice rôles × permissions ── */ ?>
<div class="section-title"><h2>Qui peut faire quoi</h2></div>
<div class="table-wrap matrix-wrap">
    <table class="matrix">
        <thead><tr><th scope="col">Permission</th>
            <?php foreach ($roles as $r): ?><th scope="col"><a href="roles.php?action=edit&slug=<?= urlencode($r['slug']) ?>" class="matrix-role"><span class="role-dot" style="background:<?= e($r['couleur']) ?>"></span><?= e($r['nom']) ?></a></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($catalogue as $groupe => $perms): ?>
            <tr class="grp"><td colspan="<?= count($roles) + 1 ?>"><?= e($groupe) ?></td></tr>
            <?php foreach ($perms as $cle => [$libelle]): ?>
                <tr>
                    <td title="<?= e($cle) ?>"><?= e($libelle) ?></td>
                    <?php foreach ($roles as $r): $ok = in_array($cle, $permsParRole[$r['slug']], true); ?>
                        <td class="<?= $ok ? 'yes' : 'no' ?>"><?= $ok ? icon('check') . '<span class="visually-hidden">oui</span>' : '<span aria-hidden="true">·</span><span class="visually-hidden">non</span>' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
