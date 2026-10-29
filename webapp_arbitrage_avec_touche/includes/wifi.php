<?php
/**
 * includes/wifi.php : comptes et nom du Wi-Fi du Raspberry Pi (portail de connexion).
 *
 * Le Raspberry Pi diffuse un Wi-Fi avec PORTAIL DE CONNEXION : en le rejoignant,
 * une page demande un IDENTIFIANT et un MOT DE PASSE ; tant qu'un appareil ne
 * s'est pas identifié, il n'accède à rien (raspberry/sabre-portail.py).
 * Les comptes sont gérés ici (Admin › Wi-Fi) ; le Raspberry relit cette table
 * toutes les 15 s (raspberry/sabre-wifi sync). Rien n'est exposé : le Pi lit la
 * base via Docker, en local.
 *
 * Format du mot de passe stocké : hachage NT (MD4), partagé avec le Pi et
 * compatible WPA2-Entreprise (si une clé Wi-Fi USB compatible est utilisée un
 * jour). Format faible : ces mots de passe sont donc distincts de ceux de
 * l'application, générés aléatoirement, et la table n'est lisible que par
 * l'administration.
 */

/** Identifiant Wi-Fi : minuscules, chiffres, . _ - (3 à 32 caractères). */
const WIFI_IDENTIFIANT_REGEX = '/^[a-z0-9][a-z0-9._-]{2,31}$/';

/**
 * Nom du Wi-Fi (SSID) : 1 à 32 caractères, lettres sans accent, chiffres, espace . _ -
 * (pas d'espace au début ni à la fin). Mêmes règles que raspberry/sabre-wifi, qui
 * l'écrit dans la configuration du point d'accès : rien d'autre n'y est accepté.
 */
const WIFI_SSID_REGEX = '/^[A-Za-z0-9._-](?:[A-Za-z0-9 ._-]{0,30}[A-Za-z0-9._-])?$/';

function ensureWifiSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wifi_comptes (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            identifiant VARCHAR(32)  NOT NULL,
            nt_hash     CHAR(32)     NOT NULL,
            description VARCHAR(100) DEFAULT NULL,
            actif       TINYINT(1)   NOT NULL DEFAULT 1,
            created_by  INT          DEFAULT NULL,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wifi_identifiant (identifiant)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // État remonté par le Raspberry Pi à chaque synchronisation
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wifi_etat (
            id               TINYINT      NOT NULL PRIMARY KEY,
            ssid             VARCHAR(64)  DEFAULT NULL,
            domaine          VARCHAR(100) DEFAULT NULL,
            adresse          VARCHAR(45)  DEFAULT NULL,
            nb_comptes       INT          NOT NULL DEFAULT 0,
            nb_connectes     INT          NOT NULL DEFAULT 0,
            derniere_synchro DATETIME     DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    // Mot de passe commun du Wi-Fi (vide = Wi-Fi ouvert, seul le portail identifie)
    $pdo->exec("ALTER TABLE wifi_etat ADD COLUMN IF NOT EXISTS mdp_wifi VARCHAR(64) DEFAULT NULL");
    // Compte affiché sur le fond d'écran du Raspberry (raspberry/sabre-fond-ecran.py) : seul
    // ce compte garde son mot de passe en clair, puisqu'il est de toute façon affiché.
    $pdo->exec("ALTER TABLE wifi_comptes ADD COLUMN IF NOT EXISTS ecran_mdp VARCHAR(64) DEFAULT NULL");
}

/** Affiche ce compte (et ce mot de passe) sur l'écran du Raspberry, à la place du précédent. */
function wifiAfficherSurEcran(PDO $pdo, int $id, string $mdp): void
{
    $pdo->prepare('UPDATE wifi_comptes SET ecran_mdp = NULL WHERE id <> ?')->execute([$id]);
    $pdo->prepare('UPDATE wifi_comptes SET ecran_mdp = ?, nt_hash = ? WHERE id = ?')
        ->execute([$mdp, wifiNtHash($mdp), $id]);
}

function wifiRetirerDeEcran(PDO $pdo, ?int $id = null): void
{
    if ($id === null) $pdo->exec('UPDATE wifi_comptes SET ecran_mdp = NULL');
    else $pdo->prepare('UPDATE wifi_comptes SET ecran_mdp = NULL WHERE id = ?')->execute([$id]);
}

/** Hachage NT (MD4 de l'UTF-16LE), vérifié par le portail du Raspberry. */
function wifiNtHash(string $mdp): string
{
    return hash('md4', mb_convert_encoding($mdp, 'UTF-16LE', 'UTF-8'));
}

/** Mot de passe facile à taper sur un téléphone : xxxx-xxxx-xxxx (≈ 62 bits). */
function wifiGenererMotDePasse(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // sans l, i, o, 0, 1 (ambigus)
    $groupes = [];
    for ($g = 0; $g < 3; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $groupes[] = $s;
    }
    return implode('-', $groupes);
}

function wifiErreurMotDePasse(string $mdp): ?string
{
    $n = mb_strlen($mdp);
    if ($n < 8)  return 'Le mot de passe Wi-Fi doit faire au moins 8 caractères.';
    if ($n > 64) return 'Le mot de passe Wi-Fi ne doit pas dépasser 64 caractères.';
    if (preg_match('/[\x00-\x1F\x7F]/', $mdp)) return 'Le mot de passe contient des caractères invalides.';
    return null;
}

/**
 * Dernier état connu du point d'accès (null si le Raspberry ne s'est jamais signalé).
 * derniere_synchro est en UTC (écrite par le Pi) : age_s = secondes écoulées depuis.
 */
function wifiEtat(PDO $pdo): ?array
{
    $st = $pdo->query('SELECT *, TIMESTAMPDIFF(SECOND, derniere_synchro, UTC_TIMESTAMP()) AS age_s FROM wifi_etat WHERE id = 1');
    return $st->fetch() ?: null;
}
