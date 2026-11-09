-- Comptes sans adresse e-mail (élèves importés depuis une liste de classe) : identifiant et mot de passe.
-- Le mot de passe n'est jamais conservé en clair : seule son empreinte est stockée. Il ne s'affiche donc
-- qu'à sa création ; pour réimprimer une fiche, on en génère un nouveau.

ALTER TABLE users
    MODIFY COLUMN email VARCHAR(254) NULL,
    ADD COLUMN username VARCHAR(60) NULL AFTER email,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER username,
    ADD COLUMN password_set_at DATETIME NULL AFTER password_hash,
    ADD COLUMN user_group VARCHAR(60) NOT NULL DEFAULT '' AFTER password_set_at,
    -- empreinte groupe + nom + prénom : importer deux fois la même liste ne crée pas de doublon
    ADD COLUMN import_key CHAR(64) NULL AFTER user_group,
    ADD UNIQUE KEY uq_users_username (username),
    ADD UNIQUE KEY uq_users_import_key (import_key),
    ADD KEY idx_users_group (user_group);

INSERT IGNORE INTO storage_types
    (name, shape, is_builtin, can_contain_units, can_hold_items, default_width_mm, default_depth_mm, default_height_mm, default_panel_mm, default_color)
VALUES
    ('Établi', 'cabinet', 1, 1, 1, 2000, 800, 900, 40, '#b9905f'),
    ('Rayonnage', 'cabinet', 1, 1, 1, 2000, 450, 2000, 19, '#8d949c');
