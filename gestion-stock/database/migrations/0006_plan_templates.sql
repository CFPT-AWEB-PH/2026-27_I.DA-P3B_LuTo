-- Plan du bâtiment : étages, position de chaque salle sur le plan, et modèles de rangement réutilisables.

CREATE TABLE floors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    -- 0 : rez-de-chaussée, 1 : premier étage, -1 : sous-sol
    level_no INT NOT NULL,
    height_mm INT UNSIGNED NOT NULL DEFAULT 3000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_floors_name (name),
    UNIQUE KEY uq_floors_level (level_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO floors (name, level_no) VALUES ('Rez-de-chaussée', 0);

-- plan_x_mm, plan_z_mm : position de l'origine de la salle sur le plan de l'étage ; NULL : pas encore placée
-- (le plan la range alors à la suite des autres, sans rien enregistrer tant qu'elle n'est pas déplacée)
ALTER TABLE rooms
    ADD COLUMN floor_id INT UNSIGNED NULL AFTER wall_color,
    ADD COLUMN plan_x_mm INT NULL AFTER floor_id,
    ADD COLUMN plan_z_mm INT NULL AFTER plan_x_mm,
    ADD COLUMN plan_rotation_deg SMALLINT NOT NULL DEFAULT 0 AFTER plan_z_mm,
    ADD CONSTRAINT fk_rooms_floor FOREIGN KEY (floor_id) REFERENCES floors (id) ON DELETE SET NULL;

UPDATE rooms SET floor_id = (SELECT id FROM floors WHERE level_no = 0);

-- Modèle : un rangement avec tout ce qu'il contient comme rangements (tiroirs, bacs…), sans le stock.
-- tree : { type_id, name, width_mm, …, children: [ … ] }
CREATE TABLE storage_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    tree LONGTEXT NOT NULL,
    node_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_templates_name (name),
    CONSTRAINT fk_templates_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rangements vus dans l'atelier : boîte plastique bleue à couvercle et plateau rainuré pour les outils
INSERT IGNORE INTO storage_types
    (name, shape, is_builtin, can_contain_units, can_hold_items, default_width_mm, default_depth_mm, default_height_mm, default_panel_mm, default_color)
VALUES
    ('Boîte plastique', 'box', 1, 0, 1, 230, 150, 50, 2, '#2f6fd6'),
    ('Plateau à outils', 'bin', 1, 0, 1, 400, 50, 40, 2, '#c4263c');
