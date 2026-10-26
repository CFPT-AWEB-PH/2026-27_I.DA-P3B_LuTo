-- Rôles dynamiques & permissions fines.
-- NB : cette migration est appliquée AUTOMATIQUEMENT par l'application
-- (includes/permissions.php → ensureRolesSchema) au premier chargement.
-- Ce fichier n'est utile que pour une installation manuelle.

CREATE TABLE IF NOT EXISTS roles (
  slug        VARCHAR(50)  NOT NULL PRIMARY KEY,
  nom         VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  couleur     VARCHAR(7)   NOT NULL DEFAULT '#7c8cff',
  systeme     TINYINT(1)   NOT NULL DEFAULT 0,
  ordre       INT          NOT NULL DEFAULT 100,
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_slug  VARCHAR(50) NOT NULL,
  permission VARCHAR(64) NOT NULL,
  PRIMARY KEY (role_slug, permission),
  CONSTRAINT fk_role_perm_role FOREIGN KEY (role_slug) REFERENCES roles(slug)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'public';

INSERT IGNORE INTO roles (slug, nom, description, couleur, systeme, ordre) VALUES
 ('admin',   'Administrateur', 'Accès total à toute l''application (non modifiable).', '#ff5c7a', 1, 1),
 ('arbitre', 'Arbitre',        'Arbitre les combats auxquels il est assigné.',          '#4af0c8', 1, 2),
 ('joueur',  'Joueur',         'Compte joueur (fiche joueur créée automatiquement).',   '#7c8cff', 1, 3),
 ('public',  'Public',         'Spectateur, aucun droit particulier.',                   '#8a8fa3', 1, 4);

INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES
 ('arbitre', 'arbitrage.acces'), ('arbitre', 'arbitrage.voter'), ('arbitre', 'arbitrage.retard');
