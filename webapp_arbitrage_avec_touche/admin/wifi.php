<?php
/**
 * admin/wifi.php : Wi-Fi de la salle (Raspberry Pi, portail de connexion).
 *  - Nom du Wi-Fi (SSID) : paramètre « wifi_ssid », appliqué par le Raspberry.
 *  - Comptes : un identifiant + un mot de passe demandés par la page de connexion
 *    qui s'ouvre en rejoignant le Wi-Fi. Le Raspberry se met à jour tout seul (≈ 15 s).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/wifi.php';
requirePermission('wifi.voir', 'wifi.gerer');
ensureWifiSchema($pdo);

$peutGerer = can('wifi.gerer');
$action = $_GET['action'] ?? 'list';
$id = idPositif($_GET['id'] ?? '');

function chargerCompteWifi(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM wifi_comptes WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Message affiché une seule fois : le mot de passe n'est jamais relisible ensuite. */
function flashMotDePasseWifi(string $identifiant, string $mdp, string $intro): void
{
    flash('success', $intro . ' Identifiant : ' . $identifiant . ' · Mot de passe : ' . $mdp
        . ' (notez-le maintenant, il ne sera plus affiché).', true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigerPermissionAction('wifi.gerer', 'admin/wifi.php');
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, merci de réessayer.');
        redirect('admin/wifi.php');
    }
    $fa = $_POST['form_action'] ?? '';
    $moi = currentUser()['username'];

    // ---- NOM DU WI-FI ----
    if ($fa === 'ssid') {
        $ssid = trim((string)($_POST['ssid'] ?? ''));
        if (!preg_match(WIFI_SSID_REGEX, $ssid)) {
            flash('error', 'Nom de Wi-Fi invalide : 1 à 32 caractères, lettres sans accent, chiffres, espace, point, tiret ou tiret bas.');
            redirect('admin/wifi.php');
        }
        setParametre($pdo, 'wifi_ssid', $ssid);
        error_log('[Sabre][wifi] nom du Wi-Fi changé en « ' . $ssid . ' » par ' . $moi);
        flash('success', 'Nom du Wi-Fi enregistré : « ' . $ssid . ' ». Le Raspberry l\'applique d\'ici 15 secondes ; '
            . 'les appareils devront ensuite rejoindre ce nouveau Wi-Fi (sans se réidentifier).', true);
        redirect('admin/wifi.php');
    }

    // ---- CRÉER ----
    if ($fa === 'create') {
        $identifiant = strtolower(trim((string)($_POST['identifiant'] ?? '')));
        $description = texteBorne($_POST['description'] ?? '', 100) ?? '';
        $mdp = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $genere = $mdp === '';
        if ($genere) $mdp = wifiGenererMotDePasse();

        $erreur = null;
        if (!preg_match(WIFI_IDENTIFIANT_REGEX, $identifiant)) {
            $erreur = 'Identifiant invalide : 3 à 32 caractères, minuscules, chiffres, point, tiret ou tiret bas.';
        } elseif ($e = wifiErreurMotDePasse($mdp)) {
            $erreur = $e;
        } else {
            $st = $pdo->prepare('SELECT 1 FROM wifi_comptes WHERE identifiant = ?');
            $st->execute([$identifiant]);
            if ($st->fetch()) $erreur = 'Cet identifiant Wi-Fi existe déjà.';
        }
        if ($erreur) {
            flash('error', $erreur);
            stashOldInput(['identifiant' => $identifiant, 'description' => $description]);
            redirect('admin/wifi.php?action=new');
        }
        $pdo->prepare('INSERT INTO wifi_comptes (identifiant, nt_hash, description, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$identifiant, wifiNtHash($mdp), $description ?: null, (int)currentUser()['id']]);
        if (isset($_POST['ecran'])) wifiAfficherSurEcran($pdo, (int)$pdo->lastInsertId(), $mdp);
        error_log('[Sabre][wifi] compte ' . $identifiant . ' créé par ' . $moi);
        if ($genere) flashMotDePasseWifi($identifiant, $mdp, 'Accès Wi-Fi créé.');
        else flash('success', 'Accès Wi-Fi « ' . $identifiant . ' » créé. Il sera actif sur le Raspberry d\'ici quelques secondes.');
        redirect('admin/wifi.php');
    }

    $cible = chargerCompteWifi($pdo, idPositif($_POST['id'] ?? ''));
    if (!$cible) { flash('error', 'Compte Wi-Fi introuvable.'); redirect('admin/wifi.php'); }

    // ---- MODIFIER ----
    if ($fa === 'update') {
        $description = texteBorne($_POST['description'] ?? '', 100) ?? '';
        $actif = isset($_POST['actif']) ? 1 : 0;
        $mdp = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        if ($mdp !== '' && ($e = wifiErreurMotDePasse($mdp))) {
            flash('error', 'Mot de passe non modifié : ' . $e);
            redirect('admin/wifi.php?action=edit&id=' . (int)$cible['id']);
        }
        $pdo->prepare('UPDATE wifi_comptes SET description = ?, actif = ? WHERE id = ?')
            ->execute([$description ?: null, $actif, $cible['id']]);
        if ($mdp !== '') {
            $pdo->prepare('UPDATE wifi_comptes SET nt_hash = ? WHERE id = ?')->execute([wifiNtHash($mdp), $cible['id']]);
            error_log('[Sabre][wifi] mot de passe de ' . $cible['identifiant'] . ' changé par ' . $moi);
        }
        // Écran du Raspberry : il faut le mot de passe en clair. Sans nouveau mot de passe,
        // on garde celui déjà affiché, sinon on en génère un (l'ancien n'est pas relisible).
        $genere = false;
        if (isset($_POST['ecran']) && $actif) {
            if ($mdp === '' && $cible['ecran_mdp'] === null) { $mdp = wifiGenererMotDePasse(); $genere = true; }
            if ($mdp !== '') wifiAfficherSurEcran($pdo, (int)$cible['id'], $mdp);
        } else {
            wifiRetirerDeEcran($pdo, (int)$cible['id']);
        }
        if ($genere) flashMotDePasseWifi($cible['identifiant'], $mdp, 'Accès affiché sur l\'écran du Raspberry, avec un nouveau mot de passe.');
        else flash('success', 'Accès Wi-Fi mis à jour.');
        redirect('admin/wifi.php');
    }

    // ---- NOUVEAU MOT DE PASSE GÉNÉRÉ ----
    if ($fa === 'regen') {
        $mdp = wifiGenererMotDePasse();
        $pdo->prepare('UPDATE wifi_comptes SET nt_hash = ? WHERE id = ?')->execute([wifiNtHash($mdp), $cible['id']]);
        if ($cible['ecran_mdp'] !== null) wifiAfficherSurEcran($pdo, (int)$cible['id'], $mdp);
        error_log('[Sabre][wifi] mot de passe de ' . $cible['identifiant'] . ' régénéré par ' . $moi);
        flashMotDePasseWifi($cible['identifiant'], $mdp, 'Nouveau mot de passe Wi-Fi.');
        redirect('admin/wifi.php');
    }

    // ---- ACTIVER / DÉSACTIVER ----
    if ($fa === 'toggle') {
        $pdo->prepare('UPDATE wifi_comptes SET actif = 1 - actif WHERE id = ?')->execute([$cible['id']]);
        if ($cible['actif']) wifiRetirerDeEcran($pdo, (int)$cible['id']);
        flash('success', $cible['actif'] ? 'Accès Wi-Fi désactivé.' : 'Accès Wi-Fi réactivé.');
        redirect('admin/wifi.php');
    }

    // ---- SUPPRIMER ----
    if ($fa === 'delete') {
        $pdo->prepare('DELETE FROM wifi_comptes WHERE id = ?')->execute([$cible['id']]);
        error_log('[Sabre][wifi] compte ' . $cible['identifiant'] . ' supprimé par ' . $moi);
        flash('success', 'Accès Wi-Fi supprimé.');
        redirect('admin/wifi.php');
    }

    // ---- RETIRER DE L'ÉCRAN DU RASPBERRY ----
    if ($fa === 'ecran_retirer') {
        wifiRetirerDeEcran($pdo, (int)$cible['id']);
        flash('success', 'Plus aucun accès affiché sur l\'écran du Raspberry.');
        redirect('admin/wifi.php');
    }
    redirect('admin/wifi.php');
}

if (!$peutGerer) $action = 'list';
$editCompte = null;
if ($action === 'edit') {
    $editCompte = $id ? chargerCompteWifi($pdo, $id) : null;
    if (!$editCompte) redirect('admin/wifi.php');
}
$old = oldInput();
if ($old && $action === 'new') $editCompte = $old;

$comptes = $pdo->query(
    'SELECT w.*, u.username AS createur FROM wifi_comptes w
     LEFT JOIN users u ON u.id = w.created_by
     ORDER BY w.actif DESC, w.identifiant ASC'
)->fetchAll();
$nbActifs = count(array_filter($comptes, fn($c) => (int)$c['actif'] === 1));
$compteEcran = current(array_filter($comptes, fn($c) => $c['ecran_mdp'] !== null && (int)$c['actif'] === 1)) ?: null;

$etat = wifiEtat($pdo);
$enLigne = $etat && $etat['age_s'] !== null && (int)$etat['age_s'] <= 120;
$ssid = $etat['ssid'] ?? 'Sabre-Arbitrage';
$ssidVoulu = getParametre($pdo, 'wifi_ssid', '');
$ssidEnAttente = $ssidVoulu !== '' && $ssidVoulu !== $ssid;
$domaine = $etat['domaine'] ?? 'sabre.lan';

$pageTitle = 'Wi-Fi';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/_tabs.php';
?>

<div class="page-head">
    <div>
        <h1>Wi-Fi de la salle</h1>
        <p class="sub">Comptes autorisés sur le Wi-Fi du Raspberry : en le rejoignant, une page demande l'identifiant et le mot de passe.</p>
    </div>
    <?php if ($peutGerer): ?>
    <div class="btn-row">
        <a href="wifi.php?action=new" class="btn"><?= icon('plus') ?>Nouvel accès</a>
    </div>
    <?php endif; ?>
</div>

<section class="card mb-5 wifi-status" aria-labelledby="h-wifi-etat">
    <div class="card-head">
        <h2 id="h-wifi-etat" class="h-inline"><?= icon('wifi') ?> Point d'accès <code><?= e($ssid) ?></code></h2>
        <?php if ($enLigne): ?>
            <span class="badge badge-success badge-dot">Raspberry synchronisé</span>
        <?php elseif ($etat): ?>
            <span class="badge badge-warning">Raspberry muet depuis <?= max(1, intdiv((int)$etat['age_s'], 60)) ?> min</span>
        <?php else: ?>
            <span class="badge badge-neutral">Raspberry jamais détecté</span>
        <?php endif; ?>
    </div>
    <?php if ($etat): ?>
        <dl class="wifi-kv">
            <div><dt>Comptes actifs</dt><dd class="tabular"><?= $nbActifs ?></dd></div>
            <div><dt>Appareils identifiés</dt><dd class="tabular"><?= $enLigne ? (int)$etat['nb_connectes'] : '–' ?></dd></div>
            <div><dt>Adresse de l'app</dt><dd><code><?= e($domaine) ?></code></dd></div>
            <div><dt>Sur l'écran du Raspberry</dt><dd><?= $compteEcran ? '<code>' . e($compteEcran['identifiant']) . '</code>' : '<span class="muted">aucun accès (compte de secours)</span>' ?></dd></div>
        </dl>
    <?php else: ?>
        <p class="hint">Les comptes créés ici seront appliqués dès que le point d'accès sera installé sur le Raspberry
            (script <code>raspberry/install_wifi.sh</code>, voir le README).</p>
    <?php endif; ?>

    <?php if ($peutGerer): ?>
    <form method="post" action="wifi.php" class="wifi-ssid" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="ssid">
        <div class="field">
            <label for="ssid">Nom du Wi-Fi</label>
            <div class="wifi-ssid-ligne">
                <input type="text" id="ssid" name="ssid" required maxlength="32" autocomplete="off" spellcheck="false"
                       pattern="[A-Za-z0-9._\-](?:[A-Za-z0-9 ._\-]{0,30}[A-Za-z0-9._\-])?"
                       data-pattern-msg="Lettres sans accent, chiffres, espace, point, tiret ou tiret bas (32 au maximum)."
                       value="<?= e($ssidEnAttente ? $ssidVoulu : $ssid) ?>" aria-describedby="ssid-hint">
                <button type="submit" class="btn btn-secondary"
                        data-confirm="Renommer le Wi-Fi ?"
                        data-confirm-text="Le point d'accès redémarre (quelques secondes) : tous les appareils sont déconnectés et doivent rejoindre le Wi-Fi sous son nouveau nom. Ils n'ont pas à se réidentifier."
                        data-confirm-label="Renommer">Renommer</button>
            </div>
            <small class="hint" id="ssid-hint">
                <?php if ($ssidEnAttente): ?>
                    <span class="badge badge-warning">En attente</span> le Raspberry va passer de « <?= e($ssid) ?> » à « <?= e($ssidVoulu) ?> »<?= $enLigne ? ' (≈ 15 s)' : ' dès qu\'il sera allumé' ?>.
                <?php else: ?>
                    Nom affiché dans la liste des Wi-Fi des téléphones. Lettres sans accent, chiffres, espace . _ -
                <?php endif; ?>
            </small>
        </div>
    </form>
    <?php endif; ?>

    <details class="wifi-guide">
        <summary>Comment se connecter (à donner aux utilisateurs)</summary>
        <ol>
            <li>Rejoindre le Wi-Fi <strong><?= e($ssid) ?></strong><?php if (!empty($etat['mdp_wifi'])): ?> (mot de passe du Wi-Fi : <code><?= e($etat['mdp_wifi']) ?></code>)<?php endif; ?>.</li>
            <li>La page de connexion s'ouvre toute seule (Android, iPhone, iPad, Mac, Windows ; sinon, ouvrir
                <code>http://<?= e($etat['adresse'] ?? '10.42.0.1') ?></code>) : saisir son <strong>identifiant</strong>
                et son <strong>mot de passe</strong> Wi-Fi. Le site du tournoi s'affiche ensuite.</li>
            <li>Dans n'importe quel navigateur (Chrome, Safari, Firefox, Samsung Internet, Edge…), taper
                <code><?= e($etat['adresse'] ?? '10.42.0.1') ?></code> ou <code>http://<?= e($domaine) ?></code> : le site s'ouvre directement, rien à installer.
                Pour l'ajouter à l'écran d'accueil : bouton « Installer l'application » en bas de page.</li>
            <li>Facultatif (app en plein écran sur Android) : installer le certificat depuis <code>http://<?= e($domaine) ?>/certificat</code>.</li>
            <li>Ce Wi-Fi n'a pas accès à internet : les autres applications du téléphone ne fonctionneront pas tant qu'il y est connecté.</li>
        </ol>
        <p class="hint">Un compte désactivé ou supprimé ici est déconnecté du Wi-Fi en 15 secondes environ. Une connexion dure 12 h.</p>
    </details>
</section>

<?php if ($peutGerer && in_array($action, ['new', 'edit'], true)): ?>
<dialog id="form-modal" class="modal modal-form" aria-labelledby="modal-form-title">
    <form method="post" action="wifi.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="form_action" value="<?= $action === 'new' ? 'create' : 'update' ?>">
        <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= (int)$editCompte['id'] ?>"><?php endif; ?>
        <div class="modal-header">
            <h3 class="modal-title" id="modal-form-title"><?= icon($action === 'new' ? 'wifi' : 'edit') ?> <?= $action === 'new' ? 'Nouvel accès Wi-Fi' : 'Modifier l\'accès Wi-Fi' ?></h3>
            <a href="wifi.php" class="modal-close-btn" aria-label="Fermer"><?= icon('x') ?></a>
        </div>
        <div class="modal-body">
            <?php if ($action === 'new'): ?>
                <div class="field">
                    <label for="identifiant">Identifiant</label>
                    <input type="text" id="identifiant" name="identifiant" required minlength="3" maxlength="32"
                           pattern="[a-z0-9][a-z0-9._\-]{2,31}" autocomplete="off" autocapitalize="none" spellcheck="false"
                           value="<?= e($editCompte['identifiant'] ?? '') ?>" aria-describedby="identifiant-hint">
                    <small class="hint" id="identifiant-hint">En minuscules (ex. <code>arbitre1</code>, <code>table-a</code>). Attention aux majuscules automatiques du téléphone.</small>
                </div>
                <div class="field">
                    <label for="password">Mot de passe <span class="req">(facultatif)</span></label>
                    <input type="text" id="password" name="password" minlength="8" maxlength="64" autocomplete="new-password" aria-describedby="password-hint">
                    <small class="hint" id="password-hint">Laissez vide pour en générer un (facile à taper) : il s'affichera une seule fois.</small>
                </div>
            <?php else: ?>
                <div class="field">
                    <label>Identifiant</label>
                    <input type="text" value="<?= e($editCompte['identifiant']) ?>" disabled>
                </div>
                <div class="field">
                    <label for="new_password">Nouveau mot de passe <span class="req">(vide = inchangé)</span></label>
                    <input type="text" id="new_password" name="new_password" minlength="8" maxlength="64" autocomplete="new-password">
                </div>
            <?php endif; ?>
            <div class="field">
                <label for="description">Description <span class="req">(facultatif)</span></label>
                <input type="text" id="description" name="description" maxlength="100" placeholder="ex. Tablette arbitre table A"
                       value="<?= e($editCompte['description'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="switch">
                    <span class="switch-text"><strong>Afficher sur l'écran du Raspberry</strong><span class="hint">L'identifiant et le mot de passe apparaissent sur le fond d'écran (avec le nom du Wi-Fi et les QR codes), à la place de l'accès affiché jusqu'ici.<?= $action === 'edit' && ($editCompte['ecran_mdp'] ?? null) === null ? ' Sans nouveau mot de passe ci-dessus, un mot de passe est généré.' : '' ?></span></span>
                    <input type="checkbox" name="ecran" role="switch" <?= ($editCompte['ecran_mdp'] ?? null) !== null ? 'checked' : '' ?>>
                </label>
            </div>
            <?php if ($action === 'edit'): ?>
                <div class="field">
                    <label class="switch">
                        <span class="switch-text"><strong>Accès actif</strong><span class="hint">Désactivé, ce compte ne peut plus rejoindre le Wi-Fi.</span></span>
                        <input type="checkbox" name="actif" role="switch" <?= !empty($editCompte['actif']) ? 'checked' : '' ?>>
                    </label>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <a href="wifi.php" class="btn btn-secondary">Annuler</a>
            <button type="submit" class="btn"><?= $action === 'new' ? 'Créer l\'accès' : 'Enregistrer' ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<div class="table-wrap">
    <table class="table-cards">
        <caption class="visually-hidden">Comptes autorisés sur le Wi-Fi</caption>
        <thead>
            <tr><th scope="col">Identifiant</th><th scope="col">État</th><th scope="col">Créé</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $c):
            $menu = '';
            if ($peutGerer) {
                $menu .= '<a role="menuitem" href="wifi.php?action=edit&id=' . (int)$c['id'] . '">' . icon('edit') . 'Modifier</a>';
                $menu .= boutonPost('wifi.php', 'regen', ['id' => (int)$c['id']], 'Générer un nouveau mot de passe', 'refresh', ['data-confirm' => 'Nouveau mot de passe pour ' . $c['identifiant'] . ' ?', 'data-confirm-text' => 'Les appareils déjà configurés avec l\'ancien mot de passe devront le ressaisir.', 'data-confirm-label' => 'Générer', 'data-confirm-type' => 'warning']);
                if ($c['ecran_mdp'] !== null) {
                    $menu .= boutonPost('wifi.php', 'ecran_retirer', ['id' => (int)$c['id']], 'Retirer de l\'écran du Raspberry', 'x');
                }
                $menu .= boutonPost('wifi.php', 'toggle', ['id' => (int)$c['id']], $c['actif'] ? 'Désactiver' : 'Réactiver', $c['actif'] ? 'lock' : 'unlock');
                $menu .= '<div class="menu-sep" role="separator"></div>' . boutonPost('wifi.php', 'delete', ['id' => (int)$c['id']], 'Supprimer', 'trash', ['class' => 'is-danger', 'data-confirm' => 'Supprimer l\'accès Wi-Fi ' . $c['identifiant'] . ' ?', 'data-confirm-text' => 'Ce compte ne pourra plus rejoindre le Wi-Fi.', 'data-confirm-label' => 'Supprimer']);
            }
        ?>
            <tr class="<?= $c['actif'] ? '' : 'row-inactive' ?>">
                <td class="cell-primary"><div class="player-cell"><strong class="mono"><?= e($c['identifiant']) ?></strong><span class="hint"><?= $c['description'] ? e($c['description']) : 'Sans description' ?></span></div></td>
                <td data-label=""><?= $c['actif'] ? '<span class="badge badge-success badge-dot">Actif</span>' : '<span class="badge badge-neutral">Désactivé</span>' ?><?= $c['ecran_mdp'] !== null && $c['actif'] ? ' <span class="badge badge-neutral">Sur l\'écran</span>' : '' ?></td>
                <td data-label="Créé :" class="tabular"><?= formatDateFr($c['created_at']) ?><?= $c['createur'] ? ' <span class="muted">par ' . e($c['createur']) . '</span>' : '' ?></td>
                <td class="cell-actions"><?= menuActions($menu, 'Actions pour ' . $c['identifiant']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$comptes): ?>
            <tr><td colspan="4"><?= etatVide('Aucun accès Wi-Fi', 'Personne ne peut encore rejoindre le Wi-Fi de la salle.', $peutGerer ? '<a class="btn" href="wifi.php?action=new">' . icon('plus') . 'Créer le premier accès</a>' : '', 'wifi', 'compact') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
