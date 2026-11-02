-- Salles, ouvertures, types de rangement et rangements imbriqués.
-- Unité : millimètre entier. Repère d'une salle : origine au coin intérieur gauche du mur
-- de la porte, au sol ; X le long de ce mur, Y vers le haut, Z vers l'intérieur de la salle.

CREATE TABLE rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    label VARCHAR(50) NOT NULL DEFAULT '',
    description VARCHAR(1000) NOT NULL DEFAULT '',
    display_unit ENUM('mm', 'cm', 'm') NOT NULL DEFAULT 'cm',
    -- contour intérieur [[x, z], …] ; sommet 0 = origine, segment 0 = mur de la porte (sur l'axe X)
    outline JSON NOT NULL,
    height_mm INT UNSIGNED NOT NULL,
    wall_thickness_mm INT UNSIGNED NOT NULL DEFAULT 100,
    -- porte de référence, sur le mur 0 : distance depuis l'origine, largeur, hauteur
    door_offset_mm INT UNSIGNED NOT NULL,
    door_width_mm INT UNSIGNED NOT NULL,
    door_height_mm INT UNSIGNED NOT NULL,
    floor_color CHAR(7) NOT NULL DEFAULT '#c8c2b8',
    floor_material ENUM('plain', 'wood', 'tiles', 'concrete') NOT NULL DEFAULT 'plain',
    wall_color CHAR(7) NOT NULL DEFAULT '#eceae4',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_name (name),
    CONSTRAINT fk_rooms_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ouvertures supplémentaires (fenêtres, autres portes, passages) sur n'importe quel mur
CREATE TABLE room_openings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    kind ENUM('door', 'window', 'opening') NOT NULL,
    name VARCHAR(80) NOT NULL DEFAULT '',
    wall_index SMALLINT UNSIGNED NOT NULL,
    offset_mm INT UNSIGNED NOT NULL,
    width_mm INT UNSIGNED NOT NULL,
    height_mm INT UNSIGNED NOT NULL,
    sill_mm INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_openings_room (room_id),
    CONSTRAINT fk_openings_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- shape détermine le rendu 3D et le volume intérieur :
--   cabinet, compartment, cell : façade ouverte ; drawer, box, bin : dessus ouvert ;
--   shelf : niveau d'étagère (planche en bas + espace utile au-dessus) ; divider : séparateur plein
CREATE TABLE storage_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    shape ENUM('cabinet', 'compartment', 'cell', 'drawer', 'box', 'bin', 'shelf', 'divider') NOT NULL,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    can_contain_units TINYINT(1) NOT NULL DEFAULT 1,
    can_hold_items TINYINT(1) NOT NULL DEFAULT 1,
    default_width_mm INT UNSIGNED NOT NULL,
    default_depth_mm INT UNSIGNED NOT NULL,
    default_height_mm INT UNSIGNED NOT NULL,
    default_panel_mm SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    default_color CHAR(7) NOT NULL DEFAULT '#b08d5c',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_storage_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE storage_units (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    -- NULL : meuble posé dans la salle ; sinon rangement situé dans l'intérieur du parent
    parent_id INT UNSIGNED NULL,
    type_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    label VARCHAR(50) NOT NULL DEFAULT '',
    reference VARCHAR(50) NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    width_mm INT UNSIGNED NOT NULL,
    depth_mm INT UNSIGNED NOT NULL,
    height_mm INT UNSIGNED NOT NULL,
    -- coin arrière-gauche-bas, dans le repère de la salle (meuble) ou de l'intérieur du parent
    pos_x_mm INT NOT NULL DEFAULT 0,
    pos_y_mm INT NOT NULL DEFAULT 0,
    pos_z_mm INT NOT NULL DEFAULT 0,
    -- rotation autour de l'axe vertical passant par ce coin (meubles uniquement)
    rotation_deg SMALLINT NOT NULL DEFAULT 0,
    panel_mm SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    color CHAR(7) NOT NULL DEFAULT '#b08d5c',
    sort_order INT NOT NULL DEFAULT 0,
    -- chemin complet « Salle › Armoire › Tiroir », tenu à jour pour la recherche
    path_text VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_units_room (room_id),
    KEY idx_units_parent (parent_id),
    UNIQUE KEY uq_units_reference (reference),
    CONSTRAINT fk_units_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
    CONSTRAINT fk_units_parent FOREIGN KEY (parent_id) REFERENCES storage_units (id) ON DELETE CASCADE,
    CONSTRAINT fk_units_type FOREIGN KEY (type_id) REFERENCES storage_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO storage_types
    (name, shape, is_builtin, can_contain_units, can_hold_items, default_width_mm, default_depth_mm, default_height_mm, default_panel_mm, default_color)
VALUES
    ('Armoire', 'cabinet', 1, 1, 1, 1000, 500, 2000, 19, '#b08d5c'),
    ('Étagère', 'shelf', 1, 1, 1, 960, 460, 350, 19, '#c9a978'),
    ('Tiroir', 'drawer', 1, 1, 1, 400, 450, 150, 12, '#8fa3b8'),
    ('Compartiment', 'compartment', 1, 1, 1, 300, 450, 300, 10, '#a7b79a'),
    ('Boîte', 'box', 1, 1, 1, 300, 200, 150, 3, '#d9b77e'),
    ('Bac', 'bin', 1, 1, 1, 300, 400, 150, 3, '#6f9fd8'),
    ('Séparateur', 'divider', 1, 0, 0, 10, 300, 300, 0, '#9a8f80'),
    ('Case', 'cell', 1, 1, 1, 100, 100, 100, 2, '#cfc6b6');
