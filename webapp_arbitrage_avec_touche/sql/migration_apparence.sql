-- Logos personnalisés (Admin › Apparence). Créée automatiquement par l'application ;
-- utile seulement pour une installation manuelle. Paramètres associés : theme, logo_actif.
CREATE TABLE IF NOT EXISTS logos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nom         VARCHAR(60)  NOT NULL,
    largeur     INT          NOT NULL DEFAULT 0,
    hauteur     INT          NOT NULL DEFAULT 0,
    marque      MEDIUMBLOB   NOT NULL,
    f32         MEDIUMBLOB   NOT NULL,
    f180        MEDIUMBLOB   NOT NULL,
    f192        MEDIUMBLOB   NOT NULL,
    f512        MEDIUMBLOB   NOT NULL,
    maskable    MEDIUMBLOB   NOT NULL,
    created_by  INT          DEFAULT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
