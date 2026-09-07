-- Schéma de base de données : Sabre Laser Arbitrage
-- MariaDB / MySQL

CREATE DATABASE IF NOT EXISTS sabre_arbitrage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sabre_arbitrage;

-- ---------------------------------------------------------------
-- Utilisateurs (admin, arbitre, joueur, public)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','arbitre','joueur','public') NOT NULL DEFAULT 'public',
  nom VARCHAR(100) DEFAULT NULL,
  prenom VARCHAR(100) DEFAULT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Grades de sabre laser (ceintures / niveaux) — entièrement configurables
-- depuis Admin → Paramètres, aucune valeur n'est codée en dur dans l'appli.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(60) NOT NULL UNIQUE,
  ordre INT NOT NULL DEFAULT 0,
  couleur VARCHAR(20) DEFAULT NULL COMMENT 'Couleur hexadécimale libre, ex. #00e5ff',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO grades (nom, ordre, couleur) VALUES
  ('Initié', 1, '#9aa3c7'),
  ('Padawan', 2, '#00e5ff'),
  ('Chevalier', 3, '#2be99a'),
  ('Maître', 4, '#ffb020')
ON DUPLICATE KEY UPDATE nom = nom;

-- ---------------------------------------------------------------
-- Paramètres généraux de l'application (clé/valeur), modifiables depuis
-- Admin → Paramètres sans jamais toucher au code.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parametres (
  cle VARCHAR(60) PRIMARY KEY,
  valeur VARCHAR(255) NOT NULL,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO parametres (cle, valeur, description) VALUES
  ('nom_application', 'Sabre Laser Arbitrage', 'Nom affiché dans l''en-tête et le titre du site'),
  ('duree_combat_defaut', '180', 'Durée par défaut (en secondes) proposée à la création d''un combat'),
  ('points_max', '10', 'Note maximale qu''un arbitre peut attribuer à un joueur par manche'),
  ('intervalle_actualisation_ms', '4000', 'Fréquence (en millisecondes) de rafraîchissement automatique des scores et du chrono en direct'),
  ('ecart_poids_max_kg', '0', 'Écart de poids maximum autorisé (kg) entre deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('ecart_age_max_ans', '0', 'Écart d''âge maximum autorisé (années) entre deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('ecart_grade_max', '0', 'Écart maximum autorisé entre les grades (niveaux) de deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('appariement_meme_sexe', '0', 'N''apparier automatiquement que des joueurs de même sexe (1 = oui, 0 = non)'),
  ('retard_minutes', '0', 'Retard cumulé actuel (en minutes), signalé par un arbitre ou l''admin, affiché aux spectateurs'),
  ('intervalle_rotation_affichage_ms', '8000', 'Fréquence (en millisecondes) de rotation des écrans sur la page d''affichage public (écran de salle)')
ON DUPLICATE KEY UPDATE cle = cle;

-- ---------------------------------------------------------------
-- Joueurs (fiche de combat, indépendante du compte utilisateur)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS joueurs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  pseudo VARCHAR(100) NOT NULL,
  nom VARCHAR(100) DEFAULT NULL,
  prenom VARCHAR(100) DEFAULT NULL,
  sexe ENUM('M','F','Autre') DEFAULT NULL,
  date_naissance DATE DEFAULT NULL,
  poids DECIMAL(5,2) DEFAULT NULL COMMENT 'Poids en kg',
  grade_id INT DEFAULT NULL,
  victoires INT NOT NULL DEFAULT 0,
  defaites INT NOT NULL DEFAULT 0,
  matchs_joues INT NOT NULL DEFAULT 0,
  points_cumules DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Combats
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS combats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  joueur1_id INT NOT NULL,
  joueur2_id INT NOT NULL,
  duree_secondes INT NOT NULL DEFAULT 180,
  statut ENUM('a_venir','en_cours','termine','annule') NOT NULL DEFAULT 'a_venir',
  date_prevue DATETIME DEFAULT NULL,
  temps_debut DATETIME DEFAULT NULL,
  temps_fin DATETIME DEFAULT NULL,
  score_joueur1 DECIMAL(10,2) NOT NULL DEFAULT 0,
  score_joueur2 DECIMAL(10,2) NOT NULL DEFAULT 0,
  vainqueur_id INT DEFAULT NULL,
  manche_actuelle INT NOT NULL DEFAULT 1,
  tour INT DEFAULT NULL COMMENT 'Numéro de tour du tournoi (1 = premier tour). NULL = combat hors arbre.',
  position INT DEFAULT NULL COMMENT 'Position du combat dans son tour, sert à tracer l''arbre (0-indexé).',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (joueur1_id) REFERENCES joueurs(id),
  FOREIGN KEY (joueur2_id) REFERENCES joueurs(id),
  FOREIGN KEY (vainqueur_id) REFERENCES joueurs(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Affectation des arbitres à un combat : 1 central + 2 coins
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS combat_arbitres (
  id INT AUTO_INCREMENT PRIMARY KEY,
  combat_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('central','coin1','coin2') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_combat_role (combat_id, role),
  UNIQUE KEY uniq_combat_user (combat_id, user_id),
  FOREIGN KEY (combat_id) REFERENCES combats(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Points donnés par chaque arbitre, par manche
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  combat_id INT NOT NULL,
  manche INT NOT NULL,
  arbitre_user_id INT NOT NULL,
  joueur1_points DECIMAL(5,2) NOT NULL,
  joueur2_points DECIMAL(5,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_combat_manche_arbitre (combat_id, manche, arbitre_user_id),
  FOREIGN KEY (combat_id) REFERENCES combats(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Résultat (moyenne) de chaque manche, une fois les 3 arbitres votés
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manches_resultats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  combat_id INT NOT NULL,
  manche INT NOT NULL,
  moyenne_joueur1 DECIMAL(6,2) NOT NULL,
  moyenne_joueur2 DECIMAL(6,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_combat_manche (combat_id, manche),
  FOREIGN KEY (combat_id) REFERENCES combats(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Compte admin par défaut : identifiant "admin" / mot de passe "admin123"
-- (le hash ci-dessous correspond à "admin123" — À CHANGER en production)
-- ---------------------------------------------------------------
INSERT INTO users (username, password_hash, role, nom, prenom)
VALUES ('admin', '$2y$12$QfRquIZ2eM.FYnEDB4RkkOSTkq2nIMkTnsNY95rwCedHaG3VpsZ4W', 'admin', 'Admin', 'Système')
ON DUPLICATE KEY UPDATE username = username;
