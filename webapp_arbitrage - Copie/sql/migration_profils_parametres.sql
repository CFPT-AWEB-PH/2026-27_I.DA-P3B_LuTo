-- Migration : profils joueurs enrichis (sexe, date de naissance, poids, grade)
-- + grades et paramètres généraux configurables.
-- À exécuter une seule fois sur une base déjà existante.

USE sabre_arbitrage;

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

CREATE TABLE IF NOT EXISTS parametres (
  cle VARCHAR(60) PRIMARY KEY,
  valeur VARCHAR(255) NOT NULL,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO parametres (cle, valeur, description) VALUES
  ('nom_application', 'Sabre Laser Arbitrage', 'Nom affiché dans l''en-tête et le titre du site'),
  ('duree_combat_defaut', '180', 'Durée par défaut (en secondes) proposée à la création d''un combat'),
  ('points_max', '10', 'Note maximale qu''un arbitre peut attribuer à un joueur par manche')
ON DUPLICATE KEY UPDATE cle = cle;

ALTER TABLE joueurs
  ADD COLUMN IF NOT EXISTS sexe ENUM('M','F','Autre') DEFAULT NULL AFTER prenom,
  ADD COLUMN IF NOT EXISTS date_naissance DATE DEFAULT NULL AFTER sexe,
  ADD COLUMN IF NOT EXISTS poids DECIMAL(5,2) DEFAULT NULL COMMENT 'Poids en kg' AFTER date_naissance,
  ADD COLUMN IF NOT EXISTS grade_id INT DEFAULT NULL AFTER poids;

-- La contrainte de clé étrangère est ajoutée séparément : MariaDB refuse
-- "ADD CONSTRAINT IF NOT EXISTS", donc on l'ajoute seulement si elle n'existe
-- pas déjà (sinon relancer la migration provoquerait une erreur de doublon).
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'joueurs'
    AND CONSTRAINT_NAME = 'fk_joueurs_grade'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE joueurs ADD CONSTRAINT fk_joueurs_grade FOREIGN KEY (grade_id) REFERENCES grades(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
