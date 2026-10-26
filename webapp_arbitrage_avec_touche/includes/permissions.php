<?php
/**
 * includes/permissions.php : Rôles dynamiques & permissions fines.
 *
 * - Les rôles sont stockés en base (table `roles`) : on peut en créer autant
 *   qu'on veut depuis Admin → Rôles & permissions.
 * - Chaque rôle possède une liste de permissions (table `role_permissions`).
 * - Le rôle `admin` est un super-rôle : il a TOUTES les permissions, toujours,
 *   et ne peut être ni supprimé ni restreint (impossible de s'enfermer dehors).
 * - Le schéma est créé / migré automatiquement au premier chargement
 *   (aucune commande SQL à lancer à la main).
 */

const ROLE_SUPER_ADMIN = 'admin';

/**
 * Catalogue de toutes les permissions de l'application, regroupées par section.
 * clé => [libellé, description]
 */
function permissionsCatalogue(): array
{
    return [
        'Administration' => [
            'admin.dashboard'        => ['Tableau de bord admin', 'Voir le tableau de bord et les statistiques.'],
            'bilan.voir'             => ['Bilan de la journée', 'Voir, imprimer et télécharger le bilan complet (classement, arbre, historique des combats).'],
        ],
        'Joueurs' => [
            'joueurs.voir'           => ['Voir les joueurs', 'Accéder à la liste des joueurs dans l\'administration.'],
            'joueurs.creer'          => ['Créer des joueurs', 'Ajouter un nouveau joueur.'],
            'joueurs.modifier'       => ['Modifier des joueurs', 'Éditer la fiche d\'un joueur (grade, poids, équipe…).'],
            'joueurs.supprimer'      => ['Supprimer des joueurs', 'Supprimer définitivement un joueur.'],
            'joueurs.importer'       => ['Importer des joueurs (CSV)', 'Importer une liste de joueurs depuis un fichier CSV.'],
            'joueurs.exporter'       => ['Exporter les joueurs (CSV)', 'Télécharger la liste des joueurs en CSV.'],
            'joueurs.champs'         => ['Gérer les champs des joueurs', 'Créer, modifier, supprimer les champs personnalisés des fiches joueurs.'],
        ],
        'Utilisateurs' => [
            'users.voir'             => ['Voir les utilisateurs', 'Accéder à la liste des comptes utilisateurs.'],
            'users.creer'            => ['Créer des utilisateurs', 'Créer un nouveau compte.'],
            'users.modifier'         => ['Modifier des utilisateurs', 'Modifier nom, prénom, statut actif d\'un compte.'],
            'users.changer_role'     => ['Changer le rôle d\'un utilisateur', 'Attribuer un autre rôle à un compte.'],
            'users.mot_de_passe'     => ['Réinitialiser les mots de passe', 'Définir un nouveau mot de passe pour un autre compte.'],
            'users.supprimer'        => ['Supprimer des utilisateurs', 'Supprimer définitivement un compte.'],
        ],
        'Rôles & permissions' => [
            'roles.voir'             => ['Voir les rôles', 'Consulter les rôles et leurs permissions.'],
            'roles.gerer'            => ['Gérer les rôles', 'Créer, modifier, supprimer des rôles et changer leurs permissions.'],
        ],
        'Combats' => [
            'combats.voir'           => ['Voir les combats (admin)', 'Accéder à la gestion des combats.'],
            'combats.creer'          => ['Créer des combats', 'Créer un combat manuellement.'],
            'combats.modifier'       => ['Modifier des combats', 'Modifier joueurs, horaire, arbitres d\'un combat à venir.'],
            'combats.demarrer'       => ['Démarrer des combats', 'Lancer un combat (chrono).'],
            'combats.terminer'       => ['Terminer des combats', 'Terminer manuellement un combat en cours.'],
            'combats.supprimer'      => ['Supprimer des combats', 'Supprimer définitivement un combat.'],
            'combats.generer'        => ['Génération automatique', 'Générer automatiquement les combats / l\'arbre.'],
            'combats.retard'         => ['Gérer le retard global', 'Ajouter / retirer du retard sur tous les combats à venir.'],
        ],
        'Sabres BLE' => [
            'sabres.voir'            => ['Voir les sabres', 'Voir la liste des sabres, leur statut et les touches en direct.'],
            'sabres.ajouter'         => ['Ajouter des sabres', 'Enregistrer un nouveau sabre (manuel ou depuis le scan).'],
            'sabres.modifier'        => ['Modifier des sabres', 'Renommer, associer à un joueur, activer / désactiver.'],
            'sabres.supprimer'       => ['Supprimer des sabres', 'Supprimer un sabre enregistré.'],
        ],
        'Réseau Wi-Fi' => [
            'wifi.voir'              => ['Voir les accès Wi-Fi', 'Voir les comptes autorisés sur le Wi-Fi du Raspberry et son état.'],
            'wifi.gerer'             => ['Gérer les accès Wi-Fi', 'Créer, désactiver, supprimer les comptes Wi-Fi et changer leurs mots de passe.'],
        ],
        'Paramètres' => [
            'parametres.voir'        => ['Voir les paramètres', 'Accéder à la page des paramètres.'],
            'parametres.modifier'    => ['Modifier les paramètres', 'Changer les réglages généraux de l\'application.'],
            'grades.gerer'           => ['Gérer les grades', 'Créer, modifier, supprimer les grades.'],
        ],
        'Arbitrage' => [
            'arbitrage.acces'        => ['Accès à l\'espace arbitrage', 'Voir l\'espace arbitrage et pouvoir être assigné comme arbitre d\'un combat.'],
            'arbitrage.voter'        => ['Saisir des points', 'Soumettre des points sur les combats où il est assigné.'],
            'arbitrage.tous_combats' => ['Superviser tous les combats', 'Voir et ouvrir tous les combats dans l\'espace arbitrage, même sans y être assigné.'],
            'arbitrage.retard'       => ['Signaler un retard (arbitre)', 'Signaler un retard depuis la page de saisie.'],
        ],
    ];
}

/** Liste plate de toutes les clés de permission. */
function toutesLesPermissions(): array
{
    $all = [];
    foreach (permissionsCatalogue() as $perms) {
        foreach ($perms as $cle => $_) $all[] = $cle;
    }
    return $all;
}

/** Permissions par défaut des rôles créés à l'installation. */
function permissionsParDefaut(): array
{
    return [
        'arbitre' => ['arbitrage.acces', 'arbitrage.voter', 'arbitrage.retard'],
        'joueur'  => [],
        'public'  => [],
    ];
}

/**
 * Crée / migre le schéma des rôles si nécessaire. Appelé à chaque requête
 * mais ne fait qu'un SHOW TABLES quand tout est déjà en place.
 */
function ensureRolesSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $existe = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetch();
    if ($existe) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS roles (
            slug        VARCHAR(50)  NOT NULL PRIMARY KEY,
            nom         VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            couleur     VARCHAR(7)   NOT NULL DEFAULT '#7c8cff',
            systeme     TINYINT(1)   NOT NULL DEFAULT 0,
            ordre       INT          NOT NULL DEFAULT 100,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_slug  VARCHAR(50) NOT NULL,
            permission VARCHAR(64) NOT NULL,
            PRIMARY KEY (role_slug, permission),
            CONSTRAINT fk_role_perm_role FOREIGN KEY (role_slug) REFERENCES roles(slug)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // users.role : ENUM figé → VARCHAR libre (pour accepter les nouveaux rôles)
    $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'public'");

    $defauts = [
        ['admin',   'Administrateur', 'Accès total à toute l\'application (non modifiable).', '#ff5c7a', 1, 1],
        ['arbitre', 'Arbitre',        'Arbitre les combats auxquels il est assigné.',         '#4af0c8', 1, 2],
        ['joueur',  'Joueur',         'Compte joueur (fiche joueur créée automatiquement).',  '#7c8cff', 1, 3],
        ['public',  'Public',         'Spectateur, aucun droit particulier.',                  '#8a8fa3', 1, 4],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO roles (slug, nom, description, couleur, systeme, ordre) VALUES (?,?,?,?,?,?)');
    foreach ($defauts as $r) $ins->execute($r);

    $insP = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES (?, ?)');
    foreach (permissionsParDefaut() as $slug => $perms) {
        foreach ($perms as $p) $insP->execute([$slug, $p]);
    }
}

/** Tous les rôles (triés), avec nombre d'utilisateurs. */
function getRoles(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = $pdo->query(
        "SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) AS nb_users
         FROM roles r ORDER BY r.ordre, r.nom"
    )->fetchAll();
    return $cache;
}

function getRole(PDO $pdo, string $slug): ?array
{
    foreach (getRoles($pdo) as $r) if ($r['slug'] === $slug) return $r;
    return null;
}

/** Permissions d'un rôle (tableau de clés). */
function getRolePermissions(PDO $pdo, string $slug): array
{
    if ($slug === ROLE_SUPER_ADMIN) return toutesLesPermissions();
    $stmt = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Slugs des rôles qui possèdent une permission donnée (admin inclus). */
function rolesAvecPermission(PDO $pdo, string $permission): array
{
    $stmt = $pdo->prepare('SELECT role_slug FROM role_permissions WHERE permission = ?');
    $stmt->execute([$permission]);
    return array_values(array_unique(array_merge([ROLE_SUPER_ADMIN], $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

/**
 * Recharge le rôle de l'utilisateur connecté depuis la base à chaque requête :
 * un changement de rôle / permissions s'applique immédiatement, et un compte
 * désactivé ou supprimé est déconnecté.
 */
function rafraichirUtilisateurSession(PDO $pdo): void
{
    if (empty($_SESSION['user']['id'])) return;
    $stmt = $pdo->prepare('SELECT role, actif, nom, prenom, session_version, doit_changer_mdp FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user']['id']]);
    $u = $stmt->fetch();
    // Compte supprimé, désactivé, ou sessions révoquées (mot de passe changé) : on déconnecte
    if (!$u || !$u['actif'] || (int)$u['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['user']['role']   = $u['role'];
    $_SESSION['user']['nom']    = $u['nom'];
    $_SESSION['user']['prenom'] = $u['prenom'];
    $_SESSION['doit_changer_mdp'] = (int)$u['doit_changer_mdp'] === 1;
}

/** Permissions de l'utilisateur connecté (cache par requête). */
function mesPermissions(): array
{
    static $cache = null;
    global $pdo;
    $user = currentUser();
    if (!$user) return [];
    if ($cache === null) $cache = getRolePermissions($pdo, $user['role']);
    return $cache;
}

function estSuperAdmin(): bool
{
    $u = currentUser();
    return $u && $u['role'] === ROLE_SUPER_ADMIN;
}

/** L'utilisateur connecté a-t-il AU MOINS UNE de ces permissions ? */
function can(string ...$permissions): bool
{
    if (!currentUser()) return false;
    if (estSuperAdmin()) return true;
    return (bool)array_intersect($permissions, mesPermissions());
}

/** Bloque la page (403) si l'utilisateur n'a aucune des permissions listées. */
function requirePermission(string ...$permissions): void
{
    requireLogin();
    if (!can(...$permissions)) {
        renderErrorPage(403, 'Accès refusé', 'Votre rôle n\'a pas la permission nécessaire pour cette action. Demandez à un administrateur de l\'ajouter dans Admin → Rôles & permissions.');
    }
}

/** Vérifie une permission pour une action précise ; sinon flash + redirection. */
function exigerPermissionAction(string $permission, string $redirectTo): void
{
    if (!can($permission)) {
        flash('error', 'Action refusée : votre rôle n\'a pas la permission « ' . libellePermission($permission) . ' ».');
        redirect($redirectTo);
    }
}

function libellePermission(string $cle): string
{
    foreach (permissionsCatalogue() as $perms) {
        if (isset($perms[$cle])) return $perms[$cle][0];
    }
    return $cle;
}

/** Onglets de l'administration visibles selon les permissions. */
function adminOnglets(): array
{
    $onglets = [
        ['index.php',      'dashboard', 'Vue d\'ensemble',          ['admin.dashboard']],
        ['combats.php',    'bolt',      'Combats',                  ['combats.voir']],
        ['joueurs.php',    'users',     'Joueurs',                  ['joueurs.voir']],
        ['users.php',      'shield',    'Comptes',                  ['users.voir']],
        ['sabres.php',     'saber',     'Sabres',                   ['sabres.voir']],
        ['roles.php',      'lock',      'Rôles',                    ['roles.voir', 'roles.gerer']],
        ['wifi.php',       'wifi',      'Wi-Fi',                    ['wifi.voir', 'wifi.gerer']],
        ['bilan.php',      'printer',   'Bilan',                    ['bilan.voir']],
        ['parametres.php', 'settings',  'Paramètres',               ['parametres.voir', 'parametres.modifier', 'grades.gerer', 'joueurs.champs']],
        ['apparence.php',  'sliders',   'Apparence',                ['parametres.voir', 'parametres.modifier']],
    ];
    return array_values(array_filter($onglets, fn($o) => can(...$o[3])));
}

/** L'utilisateur a-t-il accès à au moins une page de l'administration ? */
function peutAccederAdmin(): bool
{
    return (bool)adminOnglets();
}

/** Couleur d'affichage d'un rôle (badge). */
function couleurRole(PDO $pdo, ?string $slug): string
{
    $r = $slug ? getRole($pdo, $slug) : null;
    return $r['couleur'] ?? '#8a8fa3';
}

function nomRole(PDO $pdo, ?string $slug): string
{
    $r = $slug ? getRole($pdo, $slug) : null;
    return $r['nom'] ?? (string)$slug;
}

function badgeRole(PDO $pdo, ?string $slug): string
{
    $c = e(couleurRole($pdo, $slug));
    return '<span class="badge" style="background:' . $c . '22;color:' . $c . ';border-color:' . $c . '66;">' . e(nomRole($pdo, $slug)) . '</span>';
}

/**
 * Anti escalade de privilèges : un utilisateur qui n'est pas administrateur ne
 * peut attribuer (ou gérer un compte ayant) un rôle que si toutes les
 * permissions de ce rôle font déjà partie des siennes.
 */
function roleSubordonne(PDO $pdo, ?string $slug): bool
{
    if (estSuperAdmin()) return true;
    if ($slug === null || $slug === ROLE_SUPER_ADMIN) return false;
    return !array_diff(getRolePermissions($pdo, $slug), mesPermissions());
}
