-- Comptes, rôles, permissions, liste d'autorisation et sessions.

CREATE TABLE roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    -- rôle attribué aux nouveaux utilisateurs (exactement un)
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    -- rôle administrateur : non supprimable, possède toujours toutes les permissions
    is_protected TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    code VARCHAR(50) NOT NULL PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_code VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_id, permission_code),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(150) NOT NULL DEFAULT '',
    google_sub VARCHAR(64) NULL,
    avatar_url VARCHAR(500) NULL,
    role_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_sub (google_sub),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE allowlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('email', 'domain') NOT NULL,
    value VARCHAR(254) NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_allowlist (kind, value),
    CONSTRAINT fk_allowlist_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id = SHA-256 du jeton du cookie : une fuite de la table ne permet pas d'usurper une session
CREATE TABLE sessions (
    id CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liens de connexion à usage unique, générés en ligne de commande sur le serveur
CREATE TABLE login_tokens (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    KEY idx_login_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket VARCHAR(120) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE audit_log
    ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

INSERT INTO permissions (code, label, category, sort_order) VALUES
    ('rooms.view', 'Consulter les salles', 'Salles', 10),
    ('rooms.edit', 'Créer ou modifier des salles', 'Salles', 20),
    ('furniture.edit', 'Ajouter ou déplacer des armoires', 'Salles', 30),
    ('storage.edit', 'Modifier les rangements', 'Salles', 40),
    ('stock.view', 'Consulter le stock', 'Stock', 50),
    ('items.edit', 'Gérer le catalogue (objets, catégories, champs)', 'Stock', 60),
    ('stock.take', 'Prendre ou rendre des objets', 'Stock', 70),
    ('stock.add', 'Ajouter du stock', 'Stock', 80),
    ('stock.adjust', 'Effectuer une correction de stock', 'Stock', 90),
    ('stock.move', 'Déplacer du stock entre rangements', 'Stock', 100),
    ('history.view', 'Consulter l''historique des mouvements', 'Stock', 110),
    ('users.manage', 'Gérer les utilisateurs et la liste d''autorisation', 'Administration', 120),
    ('roles.manage', 'Gérer les rôles et les permissions', 'Administration', 130),
    ('settings.manage', 'Modifier les paramètres', 'Administration', 140),
    ('system.admin', 'Administrer le système', 'Administration', 150);

INSERT INTO roles (id, name, description, is_default, is_protected) VALUES
    (1, 'Administrateur', 'Accès complet au système', 0, 1),
    (2, 'Gestionnaire de stock', 'Gère les salles, les rangements et le stock', 0, 0),
    (3, 'Enseignant', 'Consulte, prend et rend des objets, consulte l''historique', 0, 0),
    (4, 'Élève', 'Consulte, prend et rend des objets', 1, 0);

INSERT INTO role_permissions (role_id, permission_code)
    SELECT 1, code FROM permissions;

INSERT INTO role_permissions (role_id, permission_code)
    SELECT 2, code FROM permissions
    WHERE code IN ('rooms.view', 'rooms.edit', 'furniture.edit', 'storage.edit', 'stock.view', 'items.edit',
                   'stock.take', 'stock.add', 'stock.adjust', 'stock.move', 'history.view');

INSERT INTO role_permissions (role_id, permission_code)
    SELECT 3, code FROM permissions WHERE code IN ('rooms.view', 'stock.view', 'stock.take', 'history.view');

INSERT INTO role_permissions (role_id, permission_code)
    SELECT 4, code FROM permissions WHERE code IN ('rooms.view', 'stock.view', 'stock.take');
