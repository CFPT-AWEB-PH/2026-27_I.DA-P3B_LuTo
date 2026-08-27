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

function checkCsrf(?string $token): bool
{
    return $token !== null && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Tente de connecter un utilisateur. Retourne un tableau ['success' => bool, 'error' => string|null]
 */
function attemptLogin(PDO $pdo, string $username, string $password): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Identifiant ou mot de passe incorrect.'];
    }

    if (!$user['actif']) {
        return ['success' => false, 'error' => 'Ce compte a été désactivé.'];
    }

    $_SESSION['user'] = [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'nom'      => $user['nom'],
        'prenom'   => $user['prenom'],
    ];

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
        return ['success' => false, 'error' => 'Le nom d\'utilisateur ne peut contenir que lettres, chiffres, "_", "." et "-".'];
    }

    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères.'];
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
    $stmt->execute([$username, $hash, $role, $nom ?: null, $prenom ?: null]);

    $userId = (int)$pdo->lastInsertId();

    // Si c'est un joueur, on crée automatiquement sa fiche de joueur — avec
    // son profil complet s'il l'a renseigné à l'inscription, pour éviter à
    // l'admin d'avoir à ressaisir ces informations à la main plus tard.
    if ($role === 'joueur') {
        $pdo->prepare(
            'INSERT INTO joueurs (user_id, pseudo, nom, prenom, sexe, date_naissance, poids, grade_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $username,
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
    session_destroy();
}
