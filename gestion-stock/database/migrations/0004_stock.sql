-- Catalogue, stock par emplacement, emprunts et journal des mouvements.
-- Quantités : DECIMAL(12,3) (pièces, mètres, kilos…), jamais négatives.

CREATE TABLE categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    color CHAR(7) NOT NULL DEFAULT '#6b7280',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Champs personnalisés : pour tous les objets (category_id NULL) ou une catégorie
CREATE TABLE custom_fields (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'boolean', 'select') NOT NULL DEFAULT 'text',
    options JSON NULL,
    category_id INT UNSIGNED NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_searchable TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_fields_name (name, category_id),
    CONSTRAINT fk_fields_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    category_id INT UNSIGNED NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'pièce',
    min_quantity DECIMAL(12, 3) NULL,
    -- consommable : une prise n'ouvre pas d'emprunt à rendre
    is_consumable TINYINT(1) NOT NULL DEFAULT 0,
    photo_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- texte agrégé (nom, référence, catégorie, champs, emplacements, étiquettes) pour la recherche
    search_text TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_items_reference (reference),
    KEY idx_items_name (name),
    KEY idx_items_category (category_id),
    FULLTEXT KEY ft_items_search (search_text),
    CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_items_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_field_values (
    item_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    value VARCHAR(500) NOT NULL,
    PRIMARY KEY (item_id, field_id),
    CONSTRAINT fk_values_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
    CONSTRAINT fk_values_field FOREIGN KEY (field_id) REFERENCES custom_fields (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quantité d'un objet dans un rangement précis
CREATE TABLE stock_levels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    unit_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 3) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_item_unit (item_id, unit_id),
    KEY idx_stock_unit (unit_id),
    CONSTRAINT chk_stock_positive CHECK (quantity >= 0),
    CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
    -- la suppression d'un rangement contenant du stock est refusée par l'application
    CONSTRAINT fk_stock_unit FOREIGN KEY (unit_id) REFERENCES storage_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    origin_unit_id INT UNSIGNED NULL,
    origin_path VARCHAR(1000) NOT NULL DEFAULT '',
    quantity DECIMAL(12, 3) NOT NULL,
    quantity_returned DECIMAL(12, 3) NOT NULL DEFAULT 0,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    comment VARCHAR(500) NOT NULL DEFAULT '',
    taken_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    closed_at DATETIME(3) NULL,
    KEY idx_loans_item (item_id, status),
    KEY idx_loans_user (user_id, status),
    CONSTRAINT chk_loans_returned CHECK (quantity_returned >= 0 AND quantity_returned <= quantity),
    CONSTRAINT fk_loans_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT fk_loans_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_loans_unit FOREIGN KEY (origin_unit_id) REFERENCES storage_units (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal immuable : les chemins sont copiés au moment du mouvement
CREATE TABLE stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    movement_type ENUM('take', 'consume', 'return', 'add', 'adjust', 'move') NOT NULL,
    quantity DECIMAL(12, 3) NOT NULL,
    quantity_before DECIMAL(12, 3) NULL,
    quantity_after DECIMAL(12, 3) NULL,
    from_unit_id INT UNSIGNED NULL,
    from_path VARCHAR(1000) NOT NULL DEFAULT '',
    to_unit_id INT UNSIGNED NULL,
    to_path VARCHAR(1000) NOT NULL DEFAULT '',
    room_id INT UNSIGNED NULL,
    loan_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    comment VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_movements_item (item_id, created_at),
    KEY idx_movements_user (user_id, created_at),
    KEY idx_movements_room (room_id, created_at),
    KEY idx_movements_type (movement_type, created_at),
    KEY idx_movements_created (created_at),
    CONSTRAINT fk_movements_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT fk_movements_from FOREIGN KEY (from_unit_id) REFERENCES storage_units (id) ON DELETE SET NULL,
    CONSTRAINT fk_movements_to FOREIGN KEY (to_unit_id) REFERENCES storage_units (id) ON DELETE SET NULL,
    CONSTRAINT fk_movements_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE SET NULL,
    CONSTRAINT fk_movements_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Capacité facultative d'un rangement (en nombre d'unités d'objets) pour l'état de remplissage
ALTER TABLE storage_units ADD COLUMN capacity DECIMAL(12, 3) NULL AFTER panel_mm;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('fill.colors', '{"empty": "#9ca3af", "partial": "#f59e0b", "full": "#dc2626"}');
