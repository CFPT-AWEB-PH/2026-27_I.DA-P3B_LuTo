<?php
/**
 * Bootstrap de l'application : sécurité, session, base de données, helpers.
 * Toutes les pages et API passent par ce fichier.
 */

require_once __DIR__ . '/../includes/securite.php';

initialiserErreurs();          // aucune erreur affichée au visiteur, tout est journalisé
demarrerSessionSecurisee();    // cookie HttpOnly / SameSite / Secure, expiration
envoyerEntetesSecurite();      // CSP, anti-clickjacking, nosniff, HSTS en HTTPS
refuserRequeteTropGrosse();    // corps de requête > 4 Mo

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/tournoi.php';
require_once __DIR__ . '/../includes/points.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/arbre.php';
require_once __DIR__ . '/../includes/apparence.php';
require_once __DIR__ . '/../includes/champs_joueur.php';
require_once __DIR__ . '/../includes/guide.php';

// Sous-dossier éventuel de l'application (lettres, chiffres, - _ / uniquement)
$__base = trim((string)(getenv('BASE_URL') ?: ''), '/');
if (!preg_match('#^[A-Za-z0-9_\-/]*$#', $__base)) $__base = '';
define('BASE_URL', $__base === '' ? '/' : '/' . $__base . '/');

// Le fuseau horaire (heure du serveur) est fixé dans config/database.php,
// au même endroit que celui de la connexion MySQL, pour qu'ils restent identiques.

// Schéma à jour (migrations automatiques) + utilisateur de la session revérifié en base
ensureRolesSchema($pdo);
ensureSchemaTournoi($pdo);
rafraichirUtilisateurSession($pdo);

// Mot de passe par défaut ou réinitialisé : changement obligatoire avant toute autre action
if (!empty($_SESSION['doit_changer_mdp'])) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($script, ['compte.php', 'logout.php'], true)) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Changez d\'abord votre mot de passe.']);
            exit;
        }
        header('Location: ' . BASE_URL . 'compte.php');
        exit;
    }
}
