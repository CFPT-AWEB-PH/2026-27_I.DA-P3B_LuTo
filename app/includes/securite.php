<?php
/**
 * includes/securite.php : socle de sécurité chargé AVANT tout le reste.
 *
 *  - Erreurs : jamais affichées au visiteur en production, toujours journalisées
 *  - Session : cookie HttpOnly + SameSite=Lax (+ Secure en HTTPS), mode strict,
 *              expiration après inactivité et durée maximale
 *  - En-têtes HTTP : CSP avec nonce, anti-clickjacking, nosniff, Referrer-Policy,
 *              Permissions-Policy, HSTS quand la requête arrive en HTTPS
 *  - Adresse IP du client (en ne faisant confiance aux en-têtes de proxy que
 *    lorsqu'ils viennent d'un proxy de confiance, ex. le tunnel Cloudflare)
 *  - Limitation de débit (rate limiting) stockée en base
 */

const SESSION_INACTIVITE_MAX = 8 * 3600;    // déconnexion après 8 h sans activité
const SESSION_DUREE_MAX      = 24 * 3600;   // et au plus tard 24 h après la connexion

/** Mode debug : uniquement si APP_DEBUG=1 est explicitement défini. */
function appDebug(): bool
{
    return getenv('APP_DEBUG') === '1';
}

/* ------------------------------------------------------------------ */
/* Erreurs                                                             */
/* ------------------------------------------------------------------ */
function initialiserErreurs(): void
{
    ini_set('display_errors', appDebug() ? '1' : '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);

    set_exception_handler(function (Throwable $e) {
        $ref = substr(bin2hex(random_bytes(4)), 0, 8);
        error_log(sprintf('[Sabre][%s] %s: %s dans %s:%d', $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        if (!headers_sent()) http_response_code(500);
        $json = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        if ($json) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Erreur interne du serveur.', 'message' => 'Erreur interne du serveur (réf. ' . $ref . ').']);
        } else {
            echo '<!DOCTYPE html><html lang="fr"><meta charset="utf-8"><title>Erreur</title>'
               . '<body style="background:#0f1216;color:#eef1f5;font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;text-align:center">'
               . '<div><h1>Une erreur est survenue</h1><p>Merci de réessayer. Si le problème persiste, communiquez cette référence à l\'organisation : <strong>'
               . htmlspecialchars($ref) . '</strong></p><p><a style="color:#3ee0b0" href="/">Retour à l\'accueil</a></p></div></body></html>';
        }
        exit(1);
    });
}

/* ------------------------------------------------------------------ */
/* Requête : HTTPS et IP client                                        */
/* ------------------------------------------------------------------ */

/** L'adresse IP appartient-elle à une plage CIDR ? (IPv4 et IPv6) */
function ipDansPlage(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) return $ip === $cidr;
    [$reseau, $bits] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip);
    $resBin = @inet_pton($reseau);
    if ($ipBin === false || $resBin === false || strlen($ipBin) !== strlen($resBin)) return false;
    $bits = (int)$bits;
    $octets = intdiv($bits, 8);
    $reste = $bits % 8;
    if (substr($ipBin, 0, $octets) !== substr($resBin, 0, $octets)) return false;
    if ($reste === 0) return true;
    $masque = chr((0xFF << (8 - $reste)) & 0xFF);
    return ($ipBin[$octets] & $masque) === ($resBin[$octets] & $masque);
}

/**
 * La requête vient-elle d'un proxy de confiance ? Par défaut : le réseau
 * interne Docker (172.16.0.0/12), où tourne le tunnel Cloudflare.
 * Variable TRUSTED_PROXIES pour changer (liste séparée par des virgules, vide = aucun).
 */
function depuisProxyDeConfiance(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $liste = getenv('TRUSTED_PROXIES');
    $plages = $liste === false ? ['172.16.0.0/12'] : array_filter(array_map('trim', explode(',', $liste)));
    foreach ($plages as $p) {
        if (ipDansPlage($remote, $p)) return true;
    }
    return false;
}

function ipClient(): string
{
    static $ip = null;
    if ($ip !== null) return $ip;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (depuisProxyDeConfiance()) {
        $candidat = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($candidat === '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $candidat = end($parts);
        }
        if (filter_var($candidat, FILTER_VALIDATE_IP)) $ip = $candidat;
    }
    return $ip;
}

function requeteHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return depuisProxyDeConfiance() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/* ------------------------------------------------------------------ */
/* En-têtes de sécurité + CSP                                          */
/* ------------------------------------------------------------------ */
function cspNonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

/** Corps de requête plus gros que la limite de PHP : refus explicite (413). */
function refuserRequeteTropGrosse(): void
{
    $taille = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $taille > 4 * 1024 * 1024) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Requête trop volumineuse.';
        exit;
    }
}

function envoyerEntetesSecurite(): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    $nonce = cspNonce();
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (requeteHttps()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */
function demarrerSessionSecurisee(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', (string)SESSION_DUREE_MAX);
    session_name(requeteHttps() ? '__Host-sabre' : 'sabre_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => requeteHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (!empty($_SESSION['user'])) {
        $debut = $_SESSION['auth_debut'] ?? $now;
        $derniere = $_SESSION['auth_derniere'] ?? $now;
        if ($now - $derniere > SESSION_INACTIVITE_MAX || $now - $debut > SESSION_DUREE_MAX) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'][] = ['type' => 'info', 'message' => 'Votre session a expiré, reconnectez-vous.'];
        } else {
            $_SESSION['auth_derniere'] = $now;
        }
    }
}

/** À appeler juste après une authentification réussie (anti fixation de session). */
function ouvrirSessionUtilisateur(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'user' => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
            'nom'      => $user['nom'],
            'prenom'   => $user['prenom'],
        ],
        'session_version' => (int)($user['session_version'] ?? 0),
        'auth_debut'      => time(),
        'auth_derniere'   => time(),
        'csrf_token'      => bin2hex(random_bytes(32)),
    ];
}

/* ------------------------------------------------------------------ */
/* Limitation de débit                                                 */
/* ------------------------------------------------------------------ */

/**
 * Compte un événement pour une clé et dit si la limite est dépassée.
 * Fenêtre fixe : au plus $max événements par tranche de $fenetre secondes.
 * Retourne true si l'action est AUTORISÉE.
 */
function limiteDebit(PDO $pdo, string $action, string $cle, int $max, int $fenetre, bool $compter = true): bool
{
    $id = hash('sha256', $action . '|' . $cle);
    try {
        // Ménage occasionnel des compteurs expirés (évite que la table grossisse)
        if (random_int(1, 500) === 1) {
            $pdo->exec('DELETE FROM limites_debit WHERE debut < NOW() - INTERVAL 1 DAY');
        }
        $st = $pdo->prepare('SELECT compteur, UNIX_TIMESTAMP(debut) AS debut FROM limites_debit WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $now = time();
        if (!$row || $now - (int)$row['debut'] >= $fenetre) {
            if ($compter) {
                $pdo->prepare('REPLACE INTO limites_debit (id, action, compteur, debut) VALUES (?, ?, 1, NOW())')->execute([$id, $action]);
            }
            return true;
        }
        if ((int)$row['compteur'] >= $max) return false;
        if ($compter) {
            $pdo->prepare('UPDATE limites_debit SET compteur = compteur + 1 WHERE id = ?')->execute([$id]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('[Sabre] limiteDebit : ' . $e->getMessage());
        return true; // on ne bloque pas le service si la table est indisponible
    }
}

function effacerLimiteDebit(PDO $pdo, string $action, string $cle): void
{
    try {
        $pdo->prepare('DELETE FROM limites_debit WHERE id = ?')->execute([hash('sha256', $action . '|' . $cle)]);
    } catch (PDOException $e) { /* rien */ }
}

/** Refuse la requête (429) si la limite est dépassée. */
function exigerLimiteDebit(PDO $pdo, string $action, string $cle, int $max, int $fenetre): void
{
    if (limiteDebit($pdo, $action, $cle, $max, $fenetre)) return;
    http_response_code(429);
    header('Retry-After: ' . $fenetre);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Trop de requêtes, patientez quelques instants.']);
    } else {
        echo 'Trop de requêtes, patientez quelques instants.';
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/** Chaîne nettoyée et bornée ; null si vide. */
function texteBorne(mixed $v, int $max): ?string
{
    if (!is_string($v)) return null;
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
    if ($v === '') return null;
    return mb_substr($v, 0, $max);
}

/** Entier positif depuis une entrée ; 0 si invalide. */
function idPositif(mixed $v): int
{
    return (is_string($v) || is_int($v)) && ctype_digit((string)$v) && (int)$v > 0 && (int)$v < PHP_INT_MAX ? (int)$v : 0;
}

/** Couleur #rrggbb ou null. */
function couleurHex(mixed $v): ?string
{
    return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
}

/** Politique de mot de passe commune (création, changement, réinitialisation). */
function erreurMotDePasse(string $mdp, string $username = ''): ?string
{
    if (strlen($mdp) < 8) return 'Le mot de passe doit contenir au moins 8 caractères.';
    if (strlen($mdp) > 200) return 'Le mot de passe est trop long (200 caractères au maximum).';
    $bas = mb_strtolower($mdp);
    $interdits = ['admin123', 'password', 'motdepasse', '12345678', '123456789', 'azertyui', 'qwertyui', 'sabrelaser', 'arbitrage'];
    if (in_array($bas, $interdits, true)) return 'Ce mot de passe est trop courant, choisissez-en un autre.';
    if ($username !== '' && str_contains($bas, mb_strtolower($username))) return 'Le mot de passe ne doit pas contenir le nom d\'utilisateur.';
    return null;
}

/** Mots de passe par défaut connus (installation d'origine). */
function estMotDePasseParDefaut(string $mdp): bool
{
    return in_array($mdp, ['admin123'], true);
}
