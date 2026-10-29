<?php
/**
 * admin/sabres.php : Gestion des sabres BLE.
 *
 * Tout se met à jour EN DIRECT sans recharger la page :
 *   - liste des sabres + statut BLE (toutes les 3 s)
 *   - appareils découverts par le scan (toutes les 3 s)
 *   - indicateur « touché » par sabre (toutes les 500 ms)
 *   - ajout / modification / suppression en AJAX (le formulaire en cours
 *     de saisie n'est plus jamais effacé par un rechargement automatique)
 */
require_once __DIR__ . '/../config/config.php';
requirePermission('sabres.voir');
ensureSabresSchema($pdo);
ensureTelecommandesSchema($pdo);

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

/** Réponse unifiée : JSON en AJAX, flash + redirection sinon. */
function repondre(bool $ok, string $message, array $extra = []): never
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
        exit;
    }
    flash($ok ? 'success' : 'error', $message);
    redirect('admin/sabres.php');
}

function chargerSabres(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT s.id, s.mac_address, s.nom, s.joueur_id, s.actif, s.last_seen,
                j.pseudo AS joueur_pseudo,
                TIMESTAMPDIFF(SECOND, s.last_seen, NOW()) AS secondes_depuis_vu
         FROM sabres s
         LEFT JOIN joueurs j ON j.id = s.joueur_id
         ORDER BY s.nom, s.mac_address"
    )->fetchAll();
    return array_map(function ($s) {
        $sv = $s['secondes_depuis_vu'] !== null ? (int)$s['secondes_depuis_vu'] : null;
        $statut = 'offline';
        if ($s['last_seen'] !== null && $sv !== null) {
            if ($sv < 60) $statut = 'online';
            elseif ($sv < 300) $statut = 'recent';
        }
        return [
            'id'            => (int)$s['id'],
            'mac_address'   => $s['mac_address'],
            'nom'           => $s['nom'],
            'joueur_id'     => $s['joueur_id'] !== null ? (int)$s['joueur_id'] : null,
            'joueur_pseudo' => $s['joueur_pseudo'],
            'actif'         => (int)$s['actif'],
            'last_seen'     => $s['last_seen'],
            'secs'          => $sv,
            'statut'        => $statut,
        ];
    }, $rows);
}

function chargerScan(PDO $pdo, array $sabres): array
{
    $macs = array_column($sabres, 'mac_address');
    $phNot = $macs ? 'AND mac_address NOT IN (' . implode(',', array_fill(0, count($macs), '?')) . ')' : '';
    $st = $pdo->prepare(
        "SELECT mac_address, nom_ble, last_scanned,
                TIMESTAMPDIFF(SECOND, last_scanned, NOW()) AS secs
         FROM ble_scan_cache
         WHERE last_scanned > DATE_SUB(NOW(), INTERVAL 5 MINUTE) $phNot
           AND mac_address NOT IN (SELECT mac_address FROM telecommandes WHERE mac_address IS NOT NULL)
         ORDER BY last_scanned DESC LIMIT 30"
    );
    $st->execute($macs);
    return array_map(fn($d) => [
        'mac_address'  => $d['mac_address'],
        'nom_ble'      => $d['nom_ble'],
        'last_scanned' => $d['last_scanned'],
        'secs'         => (int)$d['secs'],
    ], $st->fetchAll());
}

/**
 * État du récepteur Bluetooth (ble_receiver/receiver.py), qui se signale à chaque scan :
 * 'actif' (signe de vie < 90 s), 'erreur' (il tourne mais le Bluetooth échoue),
 * 'arrete' (plus de nouvelles) ou 'jamais' (n'a jamais démarré).
 */
function etatRecepteur(PDO $pdo): array
{
    try {
        $r = $pdo->query('SELECT message, TIMESTAMPDIFF(SECOND, maj, NOW()) AS age_s FROM ble_recepteur WHERE id = 1')->fetch();
    } catch (PDOException $e) {
        $r = false;   // table créée au premier démarrage du récepteur
    }
    if (!$r) return ['etat' => 'jamais', 'message' => '', 'age_s' => null];
    $age = (int)$r['age_s'];
    $etat = $age > 90 ? 'arrete' : (str_starts_with($r['message'], 'Erreur') ? 'erreur' : 'actif');
    return ['etat' => $etat, 'message' => $r['message'], 'age_s' => $age];
}

/** Télécommandes Bluetooth (ble_receiver/receiver.py) : connectée = signe de vie < 10 s. */
function chargerTelecommandes(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT t.id, t.mac_address, t.nom, t.user_id, t.actif, u.username,
                    TIMESTAMPDIFF(SECOND, t.last_seen, NOW()) AS secs,
                    TIMESTAMPDIFF(MICROSECOND, t.last_press_at, NOW(3)) DIV 1000 AS appui_ms
             FROM telecommandes t
             LEFT JOIN users u ON u.id = t.user_id
             ORDER BY t.nom"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    return array_map(fn($t) => [
        'id'       => (int)$t['id'],
        'mac'      => $t['mac_address'],
        'nom'      => $t['nom'],
        'user_id'  => $t['user_id'] !== null ? (int)$t['user_id'] : null,
        'username' => $t['username'],
        'actif'    => (int)$t['actif'],
        'secs'     => $t['secs'] !== null ? (int)$t['secs'] : null,
        'branchee' => $t['secs'] !== null && (int)$t['secs'] < 10,
        'appui_ms' => $t['appui_ms'] !== null ? (int)$t['appui_ms'] : null,
    ], $rows);
}

/* ── GET JSON : état complet pour le rafraîchissement en direct ─────────── */
if (($_GET['ajax'] ?? '') === 'list') {
    $sabres = chargerSabres($pdo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['sabres' => $sabres, 'scan' => chargerScan($pdo, $sabres), 'recepteur' => etatRecepteur($pdo), 'telecommandes' => chargerTelecommandes($pdo)]);
    exit;
}

/* ── ACTIONS POST ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        repondre(false, 'Session expirée, rechargez la page.');
    }

    if ($action === 'add') {
        if (!can('sabres.ajouter')) repondre(false, 'Votre rôle n\'a pas la permission d\'ajouter des sabres.');
        $mac = strtoupper(trim(str_replace('-', ':', is_string($_POST['mac_address'] ?? null) ? $_POST['mac_address'] : '')));
        $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';
        $joueurId = idPositif($_POST['joueur_id'] ?? '') ?: null;

        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            repondre(false, 'Adresse MAC invalide (format attendu : AA:BB:CC:DD:EE:FF).');
        }
        $st = $pdo->prepare('SELECT id FROM sabres WHERE mac_address = ?');
        $st->execute([$mac]);
        $existant = $st->fetchColumn();
        try {
            $pdo->prepare(
                "INSERT INTO sabres (mac_address, nom, joueur_id) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE nom = VALUES(nom), joueur_id = VALUES(joueur_id), actif = 1"
            )->execute([$mac, $nom, $joueurId]);
        } catch (PDOException $e) {
            error_log('[Sabre] ajout sabre : ' . $e->getMessage());
            repondre(false, 'Enregistrement impossible (sabre ou joueur invalide).');
        }
        $id = $existant ?: (int)$pdo->lastInsertId();
        repondre(true, $existant
            ? 'Sabre « ' . ($nom ?: $mac) . ' » déjà connu : mis à jour et réactivé.'
            : 'Sabre « ' . ($nom ?: $mac) . ' » enregistré.', ['id' => (int)$id]);
    }

    if ($action === 'edit') {
        if (!can('sabres.modifier')) repondre(false, 'Votre rôle n\'a pas la permission de modifier les sabres.');
        $id = idPositif($_POST['id'] ?? '');
        $nom = texteBorne($_POST['nom'] ?? '', 60) ?? '';
        $joueurId = idPositif($_POST['joueur_id'] ?? '') ?: null;
        $actif = !empty($_POST['actif']) ? 1 : 0;
        $pdo->prepare('UPDATE sabres SET nom = ?, joueur_id = ?, actif = ? WHERE id = ?')
            ->execute([$nom, $joueurId, $actif, $id]);
        repondre(true, 'Sabre mis à jour.', ['id' => $id]);
    }

    if ($action === 'delete') {
        if (!can('sabres.supprimer')) repondre(false, 'Votre rôle n\'a pas la permission de supprimer les sabres.');
        $id = idPositif($_POST['id'] ?? '');
        try {
            $pdo->prepare('DELETE FROM sabres WHERE id = ?')->execute([$id]);
        } catch (PDOException $e) {
            // Un sabre qui a déjà enregistré des touches est référencé : on le désactive plutôt
            $pdo->prepare('UPDATE sabres SET actif = 0 WHERE id = ?')->execute([$id]);
            repondre(true, 'Ce sabre a un historique de touches : il a été désactivé au lieu d\'être supprimé.');
        }
        repondre(true, 'Sabre supprimé.');
    }

    if ($action === 'tel_edit') {
        if (!can('sabres.modifier')) repondre(false, 'Votre rôle n\'a pas la permission de modifier les télécommandes.');
        $id = idPositif($_POST['id'] ?? '');
        $userId = idPositif($_POST['user_id'] ?? '') ?: null;
        try {
            $pdo->prepare('UPDATE telecommandes SET user_id = ? WHERE id = ?')->execute([$userId, $id]);
        } catch (PDOException $e) {
            repondre(false, 'Arbitre invalide.');
        }
        repondre(true, $userId ? 'Télécommande associée à l\'arbitre.' : 'Télécommande dissociée.', ['id' => $id]);
    }

    if ($action === 'tel_delete') {
        if (!can('sabres.supprimer')) repondre(false, 'Votre rôle n\'a pas la permission de supprimer les télécommandes.');
        $pdo->prepare('DELETE FROM telecommandes WHERE id = ?')->execute([idPositif($_POST['id'] ?? '')]);
        repondre(true, 'Télécommande oubliée (elle réapparaîtra si elle est encore allumée à portée).');
    }

    repondre(false, 'Action inconnue.');
}

/* ── Chargement initial (le JS prend ensuite le relais) ──────────────────── */
$sabres   = chargerSabres($pdo);
$scan     = chargerScan($pdo, $sabres);
$recepteur = etatRecepteur($pdo);
$joueurs  = $pdo->query('SELECT id, pseudo FROM joueurs ORDER BY pseudo')->fetchAll();
$telecommandes = chargerTelecommandes($pdo);
// Arbitres possibles = comptes actifs dont le rôle a la permission « arbitrage.acces »
$rolesArbitres = rolesAvecPermission($pdo, 'arbitrage.acces');
$arbitres = [];
if ($rolesArbitres) {
    $st = $pdo->prepare('SELECT id, username FROM users WHERE actif = 1 AND role IN (' . implode(',', array_fill(0, count($rolesArbitres), '?')) . ') ORDER BY username');
    $st->execute($rolesArbitres);
    $arbitres = array_map(fn($u) => ['id' => (int)$u['id'], 'username' => $u['username']], $st->fetchAll());
}
$perms    = [
    'ajouter'   => can('sabres.ajouter'),
    'modifier'  => can('sabres.modifier'),
    'supprimer' => can('sabres.supprimer'),
];

$pageTitle = 'Sabres BLE';
require __DIR__ . '/../includes/header.php';
?>

<?php require __DIR__ . '/_tabs.php'; ?>

<div class="page-head">
    <div>
        <h1>Sabres</h1>
        <p class="sub">Sabres électroniques Bluetooth : état de connexion, joueur associé et test des touches en direct.</p>
    </div>
    <?php if ($perms['ajouter']): ?><div class="btn-row"><a href="#add-card" class="btn"><?= icon('plus') ?>Ajouter un sabre</a></div><?php endif; ?>
</div>

<div class="ble-recepteur" id="ble-recepteur" role="status" aria-live="polite"></div>

<?php /* ── Sabres enregistrés ── */ ?>
<section class="section" aria-labelledby="h-sabres">
    <div class="section-title">
        <h2 id="h-sabres">Sabres enregistrés<span class="count" id="nb-sabres"><?= count($sabres) ?></span></h2>
        <span class="live-status" id="live-status"><span class="pulse"></span> <span id="live-text">En direct</span></span>
        <button type="button" class="btn btn-icon btn-sm" id="btn-refresh" aria-label="Actualiser maintenant" title="Actualiser maintenant"><?= icon('refresh') ?></button>
    </div>
    <div class="table-wrap">
    <table class="table-cards">
        <thead>
            <tr>
                <th scope="col">Sabre</th><th scope="col">Connexion</th><th scope="col">Touche</th><th scope="col">Joueur associé</th>
                <?php if ($perms['modifier'] || $perms['supprimer']): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="sabres-body"></tbody>
    </table>
    </div>
    <div id="sabres-empty" hidden><?= etatVide('Aucun sabre enregistré', 'Allumez un sabre à portée : il apparaîtra dans « Appareils détectés », prêt à être ajouté.', '', 'saber', 'compact') ?></div>
</section>

<?php /* ── Scan BLE : appareils découverts ── */ ?>
<section class="section" aria-labelledby="h-scan">
    <div class="section-title">
        <h2 id="h-scan">Appareils détectés<span class="count" id="nb-scan"><?= count($scan) ?></span></h2>
    </div>
    <p class="hint section-intro">Vus par le récepteur Bluetooth ces 5 dernières minutes, pas encore enregistrés.</p>
    <div class="table-wrap" id="scan-table-wrap">
        <table>
            <thead><tr><th scope="col">Adresse MAC</th><th scope="col">Nom Bluetooth</th><th scope="col">Vu il y a</th><?php if ($perms['ajouter']): ?><th scope="col"><span class="visually-hidden">Action</span></th><?php endif; ?></tr></thead>
            <tbody id="scan-body"></tbody>
        </table>
    </div>
    <div id="scan-empty" hidden><?= etatVide('Aucun appareil détecté', 'Aucun sabre allumé à portée, ou le récepteur Bluetooth ne tourne pas (voir son état en haut de la page).', '', 'broadcast', 'compact') ?></div>
</section>

<?php /* ── Télécommandes d'arbitre USB ── */ ?>
<section class="section" aria-labelledby="h-tel">
    <div class="section-title">
        <h2 id="h-tel">Télécommandes<span class="count" id="nb-tel"><?= count($telecommandes) ?></span></h2>
    </div>
    <p class="hint section-intro">Connectées en Bluetooth au Raspberry, comme les sabres : une télécommande allumée (nom Bluetooth « TEL… ») apparaît ici toute seule. Bouton rouge : +1 Aka, bouton bleu : +1 Ao, les deux ensemble : annule le dernier point. Les points comptent pour l'arbitre associé, dans le combat en cours où il arbitre.</p>
    <div class="table-wrap" id="tel-table-wrap">
        <table>
            <thead><tr><th scope="col">Télécommande</th><th scope="col">Connexion</th><th scope="col">Appui</th><th scope="col">Arbitre</th><?php if ($perms['supprimer']): ?><th scope="col"><span class="visually-hidden">Action</span></th><?php endif; ?></tr></thead>
            <tbody id="tel-body"></tbody>
        </table>
    </div>
    <div id="tel-empty" hidden><?= etatVide('Aucune télécommande', 'Allumez une télécommande à portée : elle apparaît ici dès que le récepteur Bluetooth la voit (voir son état en haut de la page).', '', 'broadcast', 'compact') ?></div>
</section>

<?php /* ── Formulaire d'ajout manuel ── */ ?>
<?php if ($perms['ajouter']): ?>
<section class="card" id="add-card" aria-labelledby="h-add">
    <h3 id="h-add">Ajouter un sabre</h3>
    <form method="post" action="sabres.php" id="add-form" novalidate>
        <input type="hidden" name="csrf"   value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="grid grid-2">
            <div class="field">
                <label for="mac_address">Adresse MAC</label>
                <input type="text" id="mac_address" name="mac_address" placeholder="C4:86:96:A2:90:92" required
                       autocomplete="off" class="mono" autocapitalize="characters" spellcheck="false">
                <small class="hint">Astuce : « Ajouter » dans les appareils détectés la remplit pour vous.</small>
            </div>
            <div class="field">
                <label for="nom">Nom du sabre</label>
                <input type="text" id="nom" name="nom" placeholder="ex. Sabre-01" autocomplete="off">
            </div>
        </div>
        <div class="field">
            <label for="joueur_id">Joueur associé</label>
            <select id="joueur_id" name="joueur_id">
                <option value="">Aucun</option>
                <?php foreach ($joueurs as $j): ?>
                    <option value="<?= (int)$j['id'] ?>"><?= e($j['pseudo']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="hint">Ses touches seront attribuées à ce joueur pendant les combats.</small>
        </div>
        <button type="submit" class="btn" id="add-submit">Enregistrer le sabre</button>
    </form>
</section>
<?php endif; ?>

<?php /* ── Modal d'édition ── */ ?>
<?php if ($perms['modifier']): ?>
<dialog id="edit-modal" class="modal modal-form" aria-labelledby="edit-title">
    <form method="post" action="sabres.php" novalidate id="edit-form">
        <input type="hidden" name="csrf"   value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id"     id="edit-id">
        <div class="modal-header">
            <h3 class="modal-title" id="edit-title"><?= icon('edit') ?> Modifier le sabre</h3>
            <button type="button" class="modal-close-btn" aria-label="Fermer" data-close-modal><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label>Adresse MAC</label>
                <code id="edit-mac-display" class="mono-block"></code>
            </div>
            <div class="field">
                <label for="edit-nom">Nom</label>
                <input type="text" id="edit-nom" name="nom" placeholder="ex. Sabre-01">
            </div>
            <div class="field">
                <label for="edit-joueur">Joueur associé</label>
                <select id="edit-joueur" name="joueur_id">
                    <option value="">Aucun</option>
                    <?php foreach ($joueurs as $j): ?>
                        <option value="<?= (int)$j['id'] ?>"><?= e($j['pseudo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="switch">
                    <span class="switch-text"><strong>Sabre actif</strong><span class="hint">Le récepteur Bluetooth tente de s'y connecter.</span></span>
                    <input type="checkbox" id="edit-actif" name="actif" value="1" role="switch">
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close-modal>Annuler</button>
            <button type="submit" class="btn">Enregistrer</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script nonce="<?= e(cspNonce()) ?>">
(function () {
    const CSRF  = <?= json_encode(csrfToken()) ?>;
    const PERMS = <?= json_encode($perms) ?>;
    const SELF  = window.location.pathname;              // …/admin/sabres.php
    const API_TOUCH = SELF.replace(/admin\/sabres\.php.*$/, 'api/ble_sabre_status.php');
    const LIST_MS  = 3000;   // rafraîchissement liste + scan
    const TOUCH_MS = 500;    // indicateur touché
    const ICON = n => `<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-${n}"></use></svg>`;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const ARBITRES = <?= json_encode($arbitres) ?>;
    let state = { sabres: <?= json_encode($sabres) ?>, scan: <?= json_encode($scan) ?>, recepteur: <?= json_encode($recepteur) ?>, telecommandes: <?= json_encode($telecommandes) ?> };
    let highlightId = null;
    let loading = false;

    /* ── Messages inline ─────────────────────────────────── */
    let msgTimer;
    function message(ok, txt) {
        ok ? window.Toast.success(txt) : window.Toast.error(txt);
    }

    /* ── Rendu : mise à jour ligne par ligne (pas de clignotement,
          les boutons ne disparaissent pas sous la souris) ──────────── */
    function fmtAgo(s) {
        if (s === null || s === undefined) return 'jamais vu';
        s = Math.max(0, s);
        if (s < 60) return s + ' s';
        if (s < 3600) return Math.round(s / 60) + ' min';
        return Math.round(s / 3600) + ' h';
    }
    function statutBadge(s) {
        if (s.statut === 'online') return `<span class="st-badge st-online badge-dot" title="Vu il y a ${fmtAgo(s.secs)}">En ligne</span>`;
        if (s.statut === 'recent') return `<span class="st-badge st-recent badge-dot" title="Vu il y a ${fmtAgo(s.secs)}">Vu récemment</span>`;
        return `<span class="st-badge st-offline" title="${s.last_seen ? 'Vu il y a ' + fmtAgo(s.secs) : 'Jamais vu'}">Hors ligne</span>`;
    }
    function sabreRow(s) {
        let items = '';
        if (PERMS.modifier)  items += `<button type="button" role="menuitem" data-edit="${s.id}">${ICON('edit')}Modifier</button>`;
        if (PERMS.supprimer) items += (items ? '<div class="menu-sep" role="separator"></div>' : '') + `<button type="button" role="menuitem" class="is-danger" data-del="${s.id}">${ICON('trash')}Supprimer</button>`;
        const menu = items ? `<details class="menu"><summary class="btn btn-icon btn-sm" aria-label="Actions pour ${esc(s.nom || s.mac_address)}">${ICON('more')}</summary><div class="menu-panel" role="menu">${items}</div></details>` : '';
        return `
            <td class="cell-primary"><div class="player-cell"><strong>${esc(s.nom || 'Sans nom')}</strong><span class="hint mono">${esc(s.mac_address)}</span></div></td>
            <td data-label="">${statutBadge(s)}${s.actif ? '' : ' <span class="badge badge-neutral">Désactivé</span>'}</td>
            <td data-label=""><span class="touch-dot" id="touch-dot-${s.id}" title="Aucune touche récente"><span class="dot"></span><span class="touch-label">–</span></span></td>
            <td data-label="Joueur :">${s.joueur_pseudo ? esc(s.joueur_pseudo) : '<span class="muted">Non associé</span>'}</td>
            ${(PERMS.modifier || PERMS.supprimer) ? `<td class="cell-actions">${menu}</td>` : ''}`;
    }
    function rowKey(s) {   // tout ce qui change l'affichage (sauf les secondes exactes)
        return [s.statut, s.nom, s.mac_address, s.joueur_pseudo, s.actif].join('|');
    }

    function renderSabres() {
        const body = document.getElementById('sabres-body');
        const seen = new Set();
        let prev = null;
        state.sabres.forEach(s => {
            const id = String(s.id);
            seen.add(id);
            let tr = body.querySelector(`tr[data-id="${id}"]`);
            const key = rowKey(s);
            if (!tr) {
                tr = document.createElement('tr');
                tr.dataset.id = id;
                tr.innerHTML = sabreRow(s);
                tr.dataset.k = key;
            } else if (tr.dataset.k !== key) {
                // on ne remplace que les cellules hors "touché" pour ne pas couper l'animation
                const tmp = document.createElement('tr');
                tmp.innerHTML = sabreRow(s);
                [...tmp.children].forEach((td, i) => { if (i !== 2) tr.children[i].replaceWith(td); });
                tr.dataset.k = key;
            } else {
                const b = tr.querySelector('.st-badge');
                if (b) b.title = s.last_seen ? 'Vu il y a ' + fmtAgo(s.secs) : 'Jamais vu';
            }
            tr.classList.toggle('row-inactive', !s.actif);
            // ordre
            const expectedPos = prev ? prev.nextSibling : body.firstChild;
            if (tr !== expectedPos) body.insertBefore(tr, expectedPos);
            prev = tr;
            if (highlightId !== null && s.id === highlightId) {
                tr.classList.remove('row-new'); void tr.offsetWidth; tr.classList.add('row-new');
                tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                highlightId = null;
            }
        });
        body.querySelectorAll('tr[data-id]').forEach(tr => { if (!seen.has(tr.dataset.id)) tr.remove(); });
        document.getElementById('nb-sabres').textContent = state.sabres.length;
        document.getElementById('sabres-empty').hidden = state.sabres.length > 0;
        body.closest('.table-wrap').hidden = state.sabres.length === 0;
    }

    function renderScan() {
        const body = document.getElementById('scan-body');
        const html = state.scan.map(d => `
            <tr>
                <td class="mono">${esc(d.mac_address)}</td>
                <td>${esc(d.nom_ble || '-')}</td>
                <td>${fmtAgo(d.secs)}</td>
                ${PERMS.ajouter ? `<td class="cell-actions"><button type="button" class="btn btn-secondary btn-sm" data-prefill="${esc(d.mac_address)}" data-nom="${esc(d.nom_ble || '')}">${ICON('plus')}Ajouter</button></td>` : ''}
            </tr>`).join('');
        if (body.dataset.h !== html) { body.innerHTML = html; body.dataset.h = html; }
        document.getElementById('nb-scan').textContent = state.scan.length;
        document.getElementById('scan-table-wrap').hidden = state.scan.length === 0;
        document.getElementById('scan-empty').hidden = state.scan.length > 0;
    }

    /* ── État du récepteur Bluetooth (Raspberry) ─────────── */
    function renderRecepteur() {
        const r = state.recepteur || { etat: 'jamais' };
        const aide = 'Sur le Raspberry : ajoutez <code>ble</code> à <code>COMPOSE_PROFILES</code> dans <code>.env</code> '
            + '(ex. <code>COMPOSE_PROFILES=pi,ble</code>), puis <code>docker compose up -d --build</code>. '
            + 'Journal : <code>docker logs -f sabre_ble</code>.';
        const vues = {
            actif:  ['badge-success badge-dot', 'Récepteur Bluetooth actif', esc(r.message)],
            erreur: ['badge-danger', 'Récepteur Bluetooth en erreur', esc(r.message) + '<br>Sur le Raspberry : <code>sudo systemctl status bluetooth</code> et <code>docker logs -f sabre_ble</code>.'],
            arrete: ['badge-warning', 'Récepteur Bluetooth arrêté', 'Plus de nouvelles depuis ' + fmtAgo(r.age_s) + '. ' + aide],
            jamais: ['badge-neutral', 'Récepteur Bluetooth jamais démarré', aide],
        };
        const [cls, titre, texte] = vues[r.etat] || vues.jamais;
        const html = `<span class="badge ${cls}">${titre}</span><span class="hint">${texte}</span>`;
        const el = document.getElementById('ble-recepteur');
        if (el.dataset.h !== html) { el.innerHTML = html; el.dataset.h = html; }
    }

    /* ── Télécommandes USB ─────────────────────────────── */
    function telArbitre(t) {
        if (!PERMS.modifier) return t.username ? esc(t.username) : '<span class="muted">Non associée</span>';
        const opts = ARBITRES.map(a => `<option value="${a.id}"${a.id === t.user_id ? ' selected' : ''}>${esc(a.username)}</option>`).join('');
        return `<select data-tel-arbitre="${t.id}" aria-label="Arbitre de ${esc(t.nom)}"><option value="">Aucun</option>${opts}</select>`;
    }
    function renderTelecommandes() {
        const body = document.getElementById('tel-body');
        const liste = state.telecommandes || [];
        const seen = new Set();
        liste.forEach(t => {
            const id = String(t.id);
            seen.add(id);
            let tr = body.querySelector(`tr[data-id="${id}"]`);
            if (!tr) { tr = document.createElement('tr'); tr.dataset.id = id; body.appendChild(tr); }
            const appui = t.appui_ms !== null && t.appui_ms < 3000;
            const key = [t.nom, t.branchee, t.user_id, appui].join('|');
            // pas de rendu pendant qu'on choisit l'arbitre (la liste se refermerait)
            if (tr.dataset.k === key || tr.contains(document.activeElement)) return;
            tr.dataset.k = key;
            tr.innerHTML = `
                <td class="cell-primary"><div class="player-cell"><strong>${esc(t.nom || 'Sans nom')}</strong><span class="hint mono">${esc(t.mac || '')}</span></div></td>
                <td>${t.branchee ? '<span class="st-badge st-online badge-dot">Connectée</span>' : `<span class="st-badge st-offline" title="${t.secs === null ? 'Jamais connectée' : 'Vue il y a ' + fmtAgo(t.secs)}">Hors ligne</span>`}</td>
                <td><span class="touch-dot${appui ? ' active-touche' : ''}"><span class="dot"></span><span class="touch-label">${appui ? 'Appui' : '–'}</span></span></td>
                <td>${telArbitre(t)}</td>
                ${PERMS.supprimer ? `<td class="cell-actions"><button type="button" class="btn btn-icon btn-sm" data-tel-del="${t.id}" aria-label="Oublier ${esc(t.nom)}" title="Oublier">${ICON('trash')}</button></td>` : ''}`;
        });
        body.querySelectorAll('tr[data-id]').forEach(tr => { if (!seen.has(tr.dataset.id)) tr.remove(); });
        document.getElementById('nb-tel').textContent = liste.length;
        document.getElementById('tel-table-wrap').hidden = liste.length === 0;
        document.getElementById('tel-empty').hidden = liste.length > 0;
    }
    document.addEventListener('change', async e => {
        const sel = e.target.closest('[data-tel-arbitre]');
        if (!sel) return;
        const res = await post({ action: 'tel_edit', id: sel.dataset.telArbitre, user_id: sel.value });
        message(res.ok, res.message);
        sel.blur();
        refresh();
    });
    document.addEventListener('click', async e => {
        const del = e.target.closest('[data-tel-del]');
        if (!del) return;
        const t = (state.telecommandes || []).find(x => x.id === +del.dataset.telDel);
        const ok = window.Modal
            ? await window.Modal.show({ message: `Oublier la télécommande « ${t?.nom} » ?`, confirmLabel: 'Oublier', type: 'danger' })
            : confirm('Oublier cette télécommande ?');
        if (!ok) return;
        const res = await post({ action: 'tel_delete', id: del.dataset.telDel });
        message(res.ok, res.message);
        refresh();
    });

    function render() { renderRecepteur(); renderSabres(); renderScan(); renderTelecommandes(); }

    /* ── Rafraîchissement liste + scan ─────────────────── */
    async function refresh(manual) {
        if (loading) return;
        loading = true;
        const btn = document.getElementById('btn-refresh');
        if (manual) btn.querySelector('svg')?.classList.add('spin');
        try {
            const r = await fetch(SELF + '?ajax=list', { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });
            if (r.redirected || !r.ok) throw new Error('HTTP ' + r.status);
            state = await r.json();
            render();
            setLive(true);
        } catch (e) {
            setLive(false);
        } finally {
            loading = false;
            if (manual) setTimeout(() => btn.querySelector('svg')?.classList.remove('spin'), 300);
        }
    }
    function setLive(ok) {
        document.getElementById('live-status').classList.toggle('error', !ok);
        document.getElementById('live-text').textContent = ok
            ? 'En direct · ' + new Date().toLocaleTimeString('fr-FR')
            : 'Connexion perdue : nouvelle tentative…';
    }

    /* ── Indicateur « touché » ─────────────────────────── */
    async function pollTouches() {
        try {
            const r = await fetch(API_TOUCH, { cache: 'no-store' });
            if (!r.ok) return;
            const data = await r.json();
            (data.sabres || []).forEach(s => {
                const dot = document.getElementById('touch-dot-' + s.id);
                if (!dot) return;
                const label = dot.querySelector('.touch-label');
                const actif = s.last_touch_type && s.last_touch_ago_ms !== null && s.last_touch_ago_ms < 3000;
                const cls = actif ? 'active-' + s.last_touch_type : '';
                if (dot.dataset.cls !== cls) {
                    dot.classList.remove('active-touche', 'active-double');
                    if (cls) dot.classList.add(cls);
                    dot.dataset.cls = cls;
                }
                if (label) label.textContent = actif ? (s.last_touch_type === 'touche' ? 'Touché' : 'Double') : '–';
                dot.title = actif ? 'Touche il y a ' + s.last_touch_ago_ms + ' ms' : 'Aucune touche récente';
            });
        } catch (_) { /* silencieux */ }
    }

    /* ── Envoi AJAX des formulaires ─────────────────────── */
    async function post(data) {
        const fd = data instanceof FormData ? data : Object.entries(data).reduce((f, [k, v]) => (f.append(k, v), f), new FormData());
        if (!fd.has('csrf')) fd.append('csrf', CSRF);
        const r = await fetch(SELF, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
        try { return await r.json(); } catch (_) { return { ok: false, message: 'Réponse inattendue du serveur (HTTP ' + r.status + ').' }; }
    }

    const addForm = document.getElementById('add-form');
    if (addForm) {
        const mac = document.getElementById('mac_address');
        // formatage auto de la MAC pendant la frappe : c486... → C4:86:...
        mac.addEventListener('input', () => {
            const hex = mac.value.toUpperCase().replace(/[^0-9A-F]/g, '').slice(0, 12);
            const fmt = hex.match(/.{1,2}/g)?.join(':') ?? '';
            if (mac.value.toUpperCase() !== fmt && !/[:\-]$/.test(mac.value)) mac.value = fmt;
        });
        addForm.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = document.getElementById('add-submit');
            btn.disabled = true; btn.classList.add('is-submitting');
            const res = await post(new FormData(addForm));
            btn.disabled = false; btn.classList.remove('is-submitting');
            message(res.ok, res.message);
            if (res.ok) {
                addForm.reset();
                highlightId = res.id ?? null;
                await refresh();
            } else {
                mac.focus();
            }
        });
    }

    /* ── Délégation des clics (les lignes sont recréées) ── */
    document.addEventListener('click', async e => {
        const ed = e.target.closest('[data-edit]');
        if (ed) { openEditModal(state.sabres.find(s => s.id === +ed.dataset.edit)); return; }

        const del = e.target.closest('[data-del]');
        if (del) {
            const s = state.sabres.find(x => x.id === +del.dataset.del);
            const ok = window.Modal
                ? await window.Modal.show({ message: `Supprimer le sabre « ${s?.nom || s?.mac_address} » ?`, confirmLabel: 'Supprimer', type: 'danger' })
                : confirm('Supprimer ce sabre ?');
            if (!ok) return;
            const res = await post({ action: 'delete', id: del.dataset.del });
            message(res.ok, res.message);
            refresh();
            return;
        }

        const pf = e.target.closest('[data-prefill]');
        if (pf && addForm) {
            document.getElementById('mac_address').value = pf.dataset.prefill;
            document.getElementById('nom').value = pf.dataset.nom || '';
            document.getElementById('add-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => document.getElementById('nom').focus(), 350);
        }
    });

    document.getElementById('btn-refresh').addEventListener('click', () => refresh(true));

    /* ── Modal d'édition ───────────────────────────────── */
    const modal = document.getElementById('edit-modal');
    window.openEditModal = function (s) {
        if (!modal || !s) return;
        document.getElementById('edit-id').value = s.id;
        document.getElementById('edit-mac-display').textContent = s.mac_address;
        document.getElementById('edit-nom').value = s.nom || '';
        document.getElementById('edit-joueur').value = s.joueur_id || '';
        document.getElementById('edit-actif').checked = !!s.actif;
        modal.showModal();
        setTimeout(() => document.getElementById('edit-nom').focus(), 50);
    };
    window.closeEditModal = function () { modal?.close(); };
    document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', () => modal?.close()));
    if (modal) {
        modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });
        document.getElementById('edit-form').addEventListener('submit', async e => {
            e.preventDefault();
            const res = await post(new FormData(e.target));
            message(res.ok, res.message);
            if (res.ok) { modal.close(); highlightId = res.id ?? null; refresh(); }
        });
    }

    /* ── Démarrage ─────────────────────────────────────── */
    render();
    setLive(true);
    pollTouches();
    setInterval(() => { if (!document.hidden) refresh(); }, LIST_MS);
    setInterval(() => { if (!document.hidden) pollTouches(); }, TOUCH_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); pollTouches(); } });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
