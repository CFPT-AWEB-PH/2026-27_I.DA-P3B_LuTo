-- Migration BLE : sabres et touchers
-- Idempotente (IF NOT EXISTS) — peut être relancée sans risque.
-- À exécuter sur une base existante  : mysql -u root -p sabre_arbitrage < sql/migration_ble.sql
-- Sur une nouvelle installation, init.sql inclut déjà ces tables.

USE sabre_arbitrage;

-- ── Sabres enregistrés ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sabres (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  mac_address VARCHAR(17)  NOT NULL UNIQUE COMMENT 'Adresse MAC BLE ex. C4:86:96:A2:90:92',
  nom         VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'Nom du sabre ex. Sabre-01',
  joueur_id   INT          DEFAULT NULL COMMENT 'Joueur associé (mapping fixe admin)',
  actif       TINYINT(1)   NOT NULL DEFAULT 1,
  last_seen   DATETIME     DEFAULT NULL COMMENT 'Dernière connexion BLE détectée',
  last_hit_at DATETIME(3)  DEFAULT NULL COMMENT 'Dernière touche reçue (indicateur admin)',
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (joueur_id) REFERENCES joueurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Cache du dernier scan BLE ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ble_scan_cache (
  mac_address  VARCHAR(17)  NOT NULL PRIMARY KEY,
  nom_ble      VARCHAR(100) DEFAULT NULL COMMENT 'Nom BLE diffusé (advertising name)',
  last_scanned DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Touches enregistrées ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS touchers (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  combat_id    INT    NOT NULL,
  sabre_id     INT    NOT NULL,
  joueur_id    INT    DEFAULT NULL,
  timestamp_ms BIGINT NOT NULL COMMENT 'Horodatage Unix en millisecondes (côté Pi)',
  type         ENUM('touche','double','annule') NOT NULL DEFAULT 'touche',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (combat_id)  REFERENCES combats(id) ON DELETE CASCADE,
  FOREIGN KEY (sabre_id)   REFERENCES sabres(id),
  FOREIGN KEY (joueur_id)  REFERENCES joueurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;
