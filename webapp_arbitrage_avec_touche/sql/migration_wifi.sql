-- Wi-Fi du Raspberry Pi (WPA2-Entreprise) : comptes identifiant + mot de passe.
-- Créé automatiquement par l'application (Admin › Wi-Fi) ; ce script n'est utile
-- que pour une installation manuelle.
CREATE TABLE IF NOT EXISTS wifi_comptes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    identifiant VARCHAR(32)  NOT NULL,
    nt_hash     CHAR(32)     NOT NULL,
    description VARCHAR(100) DEFAULT NULL,
    actif       TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wifi_identifiant (identifiant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wifi_etat (
    id               TINYINT      NOT NULL PRIMARY KEY,
    ssid             VARCHAR(64)  DEFAULT NULL,
    domaine          VARCHAR(100) DEFAULT NULL,
    adresse          VARCHAR(45)  DEFAULT NULL,
    nb_comptes       INT          NOT NULL DEFAULT 0,
    nb_connectes     INT          NOT NULL DEFAULT 0,
    derniere_synchro DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
