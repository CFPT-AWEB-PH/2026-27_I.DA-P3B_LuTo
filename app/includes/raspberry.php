<?php
/**
 * includes/raspberry.php : écrans des Raspberry (Admin › Raspberry).
 *
 * Chaque Raspberry a sa ligne : le compte de l'application affiché sur son fond
 * d'écran (identifiant + mot de passe) et une image de fond facultative.
 *  - Raspberry principal (point d'accès, qui héberge l'app) : son script lit ces infos
 *    en local (raspberry/infos_ecran.php, lancé par « docker exec »).
 *  - Autres Raspberry : ils rejoignent le Wi-Fi et les lisent sur api/raspberry_infos.php
 *    avec leur jeton. Chacun a son accès Wi-Fi « raspberry-N » pour passer le portail.
 *    Leur installation tient en une commande (raspberryCommande), servie par
 *    api/raspberry_installation.php.
 * Le fond d'écran lui-même est dessiné sur le Pi par raspberry/sabre-fond-ecran.py.
 */

function ensureRaspberrySchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if ($pdo->query("SHOW TABLES LIKE 'raspberrys'")->fetch()) return;
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS mdp_chiffre TEXT DEFAULT NULL AFTER password_hash");
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS raspberrys (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            nom            VARCHAR(60)  NOT NULL,
            principal      TINYINT(1)   NOT NULL DEFAULT 0,
            jeton_hash     CHAR(64)     DEFAULT NULL,
            jeton_chiffre  TEXT         DEFAULT NULL,
            user_id       INT          DEFAULT NULL,
            wifi_compte_id INT          DEFAULT NULL,
            fond           MEDIUMBLOB   DEFAULT NULL,
            fond_version   CHAR(16)     DEFAULT NULL,
            derniere_vue   DATETIME     DEFAULT NULL,
            created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_raspberry_jeton (jeton_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec("INSERT INTO raspberrys (nom, principal) VALUES ('Raspberry principal', 1)");
}

function raspberryHashJeton(string $jeton): string
{
    return hash('sha256', $jeton);
}

function raspberryNouveauJeton(PDO $pdo, int $id): string
{
    $jeton = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE raspberrys SET jeton_hash = ?, jeton_chiffre = ? WHERE id = ?')
        ->execute([raspberryHashJeton($jeton), chiffrerMotDePasse($jeton), $id]);
    return $jeton;
}

/** Le Raspberry qui présente ce jeton (en-tête X-Sabre-Jeton) ; sinon réponse 403 et fin du script. */
function raspberryDepuisJeton(PDO $pdo): array
{
    $jeton = $_SERVER['HTTP_X_SABRE_JETON'] ?? '';
    $ip = ipClient();
    $r = null;
    if (limiteDebit($pdo, 'raspberry_jeton', $ip, 20, 900, false) && is_string($jeton) && $jeton !== '') {
        $st = $pdo->prepare('SELECT id, nom, user_id, wifi_compte_id, fond_version FROM raspberrys WHERE jeton_hash = ?');
        $st->execute([raspberryHashJeton($jeton)]);
        $r = $st->fetch() ?: null;
    }
    if (!$r) {
        limiteDebit($pdo, 'raspberry_jeton', $ip, 20, 900);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false]);
        exit;
    }
    return $r;
}

/** Commande toute faite à lancer sur un autre Raspberry, null si son jeton n'est pas relisible. */
function raspberryCommande(PDO $pdo, array $r): ?string
{
    $jeton = dechiffrerMotDePasse($r['jeton_chiffre'] ?? null);
    if ($jeton === null) return null;
    $hote = (string)($_SERVER['HTTP_HOST'] ?? '');
    $etat = wifiEtat($pdo);
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $hote)) $hote = $etat['adresse'] ?? '10.42.0.1';
    // Sur le Wi-Fi, le HTTPS utilise le certificat local du Pi, inconnu d'un Raspberry neuf : on passe en HTTP
    $local = in_array(explode(':', $hote)[0], [$etat['adresse'] ?? '10.42.0.1', $etat['domaine'] ?? 'sabre.lan'], true);
    $url = (requeteHttps() && !$local ? 'https' : 'http') . '://' . $hote . BASE_URL . 'api/raspberry_installation.php';
    return 'curl -fsSL -H ' . escapeshellarg('X-Sabre-Jeton: ' . $jeton) . ' ' . escapeshellarg($url) . ' | sudo bash';
}

/** Raspberry affichant ce compte : remplace la liste par les identifiants choisis. */
function raspberryAttribuerCompte(PDO $pdo, int $userId, array $ids): void
{
    $pdo->prepare('UPDATE raspberrys SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
    $st = $pdo->prepare('UPDATE raspberrys SET user_id = ? WHERE id = ?');
    foreach ($ids as $id) {
        if ($id = idPositif($id)) $st->execute([$userId, $id]);
    }
}

/** Ce que l'écran d'un Raspberry doit afficher (JSON lu par raspberry/sabre-fond-ecran.py). */
function raspberryInfos(PDO $pdo, array $r): array
{
    ensureWifiSchema($pdo);
    $etat = wifiEtat($pdo);
    $portail = $pdo->query('SELECT identifiant, ecran_mdp FROM wifi_comptes WHERE actif = 1 AND ecran_mdp IS NOT NULL ORDER BY updated_at DESC LIMIT 1')->fetch();
    $compte = null;
    if ($r['user_id']) {
        $st = $pdo->prepare('SELECT username, mdp_chiffre FROM users WHERE id = ? AND actif = 1');
        $st->execute([$r['user_id']]);
        $compte = $st->fetch() ?: null;
    }
    return [
        'nom' => $r['nom'],
        'ssid' => $etat['ssid'] ?? null,
        'mdp_wifi' => $etat['mdp_wifi'] ?? null,
        'domaine' => $etat['domaine'] ?? null,
        'portail' => $portail ? ['identifiant' => $portail['identifiant'], 'mdp' => $portail['ecran_mdp']] : null,
        'compte' => $compte ? ['identifiant' => $compte['username'], 'mdp' => dechiffrerMotDePasse($compte['mdp_chiffre'])] : null,
        'fond_version' => $r['fond_version'],
    ];
}

/**
 * Vérifie une image envoyée et la ré-encode en JPEG ($lMax × $hMax au plus) : aucun contenu
 * caché ne passe. Retourne ['ok' => true, 'image' => …] ou ['ok' => false, 'erreur' => '…'].
 */
function preparerImageJpeg(array $fichier, int $lMax = 1920, int $hMax = 1080): array
{
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'erreur' => 'Le module d\'images (GD) manque : relancez « docker compose up -d --build ».'];
    }
    if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $erreurTaille = in_array($fichier['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return ['ok' => false, 'erreur' => $erreurTaille ? 'Image trop lourde (2 Mo maximum).' : 'L\'envoi de l\'image a échoué, réessayez.'];
    }
    if (!is_uploaded_file($fichier['tmp_name'])) return ['ok' => false, 'erreur' => 'L\'envoi de l\'image a échoué, réessayez.'];
    $info = @getimagesize($fichier['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return ['ok' => false, 'erreur' => 'Format non accepté : PNG, JPEG ou WebP uniquement.'];
    }
    [$l, $h] = $info;
    if ($l > 4000 || $h > 4000) return ['ok' => false, 'erreur' => 'Image trop grande (4000 × 4000 pixels maximum).'];
    $src = @imagecreatefromstring((string)file_get_contents($fichier['tmp_name']));
    if (!$src) return ['ok' => false, 'erreur' => 'Image illisible ou abîmée.'];

    $r = min($lMax / $l, $hMax / $h, 1.0);
    $nl = max(1, (int)round($l * $r));
    $nh = max(1, (int)round($h * $r));
    $t = gdToile($nl, $nh, [13, 17, 23]);
    imagecopyresampled($t, $src, 0, 0, 0, 0, $nl, $nh, $l, $h);
    ob_start();
    imagejpeg($t, null, 88);
    return ['ok' => true, 'image' => (string)ob_get_clean()];
}
