<?php
/**
 * Fonctions d'authentification et de gestion des rôles
 */

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function hasRole(string ...$roles): bool
{
    $user = currentUser();
    if (!$user) return false;
    return in_array($user['role'], $roles, true);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) {
        renderErrorPage(403, 'Accès refusé', 'Vous n\'avez pas les droits nécessaires pour accéder à cette page.');
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf(mixed $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Tente de connecter un utilisateur. Retourne ['success' => bool, 'error' => string|null].
 *
 * Protections :
 *  - limite par adresse IP (20 échecs / 15 min) et par identifiant (5 échecs / 15 min)
 *  - message d'erreur identique que le compte existe ou non, et temps de réponse
 *    comparable (vérification d'un hash factice si le compte n'existe pas)
 *  - nouvel identifiant de session à la connexion (anti fixation)
 *  - hash recalculé si l'algorithme par défaut de PHP a évolué
 */
function attemptLogin(PDO $pdo, string $username, string $password): array
{
    $username = mb_substr(trim($username), 0, 50);
    $ip = ipClient();
    $cleUser = mb_strtolower($username);
    $messageGenerique = 'Identifiant ou mot de passe incorrect.';

    if (!limiteDebit($pdo, 'login_ip', $ip, 20, 900, false) || !limiteDebit($pdo, 'login_user', $cleUser, 5, 900, false)) {
        error_log('[Sabre][auth] connexion bloquée (trop d\'échecs) ip=' . $ip . ' user=' . $cleUser);
        return ['success' => false, 'error' => 'Trop de tentatives. Patientez 15 minutes avant de réessayer.'];
    }
    if ($username === '' || $password === '' || strlen($password) > 200) {
        return ['success' => false, 'error' => $messageGenerique];
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Hash factice : le temps de réponse ne révèle pas si le compte existe
    $hash = $user['password_hash'] ?? '$2y$12$ATxU1UoXMRFbcU1xIpOpmeo8o9JYDpUnimkxUVuDRXMTis3HjV0g6';
    $ok = password_verify($password, $hash) && $user;

    if (!$ok || !$user['actif']) {
        limiteDebit($pdo, 'login_ip', $ip, 20, 900);
        limiteDebit($pdo, 'login_user', $cleUser, 5, 900);
        error_log('[Sabre][auth] échec de connexion ip=' . $ip . ' user=' . $cleUser);
        return ['success' => false, 'error' => $messageGenerique];
    }

    effacerLimiteDebit($pdo, 'login_user', $cleUser);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    if (estMotDePasseParDefaut($password) && !(int)($user['doit_changer_mdp'] ?? 0)) {
        $pdo->prepare('UPDATE users SET doit_changer_mdp = 1 WHERE id = ?')->execute([$user['id']]);
    }
    try {
        $pdo->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);
    } catch (PDOException $e) { /* colonne absente avant migration */ }

    ouvrirSessionUtilisateur($user);
    error_log('[Sabre][auth] connexion réussie ip=' . $ip . ' user=' . $cleUser);

    return ['success' => true, 'error' => null];
}

/**
 * Crée un nouveau compte utilisateur. Le nom d'utilisateur doit être unique.
 */
function registerUser(PDO $pdo, string $username, string $password, string $nom = '', string $prenom = '', string $role = 'joueur', array $profil = []): array
{
    $username = trim($username);

    if ($username === '' || mb_strlen($username) < 3) {
        return ['success' => false, 'error' => 'Le nom d\'utilisateur doit contenir au moins 3 caractères.'];
    }

    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
        return ['success' => false, 'error' => 'Le nom d\'utilisateur ne peut contenir que des lettres, chiffres, « _ », « . » et « - ».'];
    }

    if (mb_strlen($username) > 30) {
        return ['success' => false, 'error' => 'Le nom d\'utilisateur doit faire 30 caractères au maximum.'];
    }

    if ($err = erreurMotDePasse($password, $username)) {
        return ['success' => false, 'error' => $err];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Ce nom d\'utilisateur est déjà pris.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, nom, prenom) VALUES (?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$username, $hash, $role, $nom ?: null, $prenom ?: null]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['success' => false, 'error' => 'Ce nom d\'utilisateur est déjà pris.'];
        }
        throw $e;
    }

    $userId = (int)$pdo->lastInsertId();

    // Si c'est un joueur, on crée automatiquement sa fiche de joueur : avec
    // son profil complet s'il l'a renseigné à l'inscription, pour éviter à
    // l'admin d'avoir à ressaisir ces informations à la main plus tard.
    if ($role === 'joueur') {
        $pdo->prepare(
            'INSERT INTO joueurs (user_id, pseudo, nom, prenom, sexe, date_naissance, poids, grade_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            texteBorne($profil['pseudo'] ?? '', 60) ?? $username,
            $nom ?: null,
            $prenom ?: null,
            $profil['sexe'] ?? null,
            $profil['date_naissance'] ?? null,
            $profil['poids'] ?? null,
            $profil['grade_id'] ?? null,
        ]);
    }

    return ['success' => true, 'error' => null, 'user_id' => $userId];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}
