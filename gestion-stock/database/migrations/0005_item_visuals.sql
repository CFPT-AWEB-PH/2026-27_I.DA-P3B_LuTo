-- Apparence 3D des objets : modèle affiché dans les rangements ('auto' : déduit du nom et de la catégorie).

ALTER TABLE items
    ADD COLUMN visual VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER photo_path,
    ADD COLUMN visual_color CHAR(7) NULL AFTER visual;

-- Couleurs par défaut des armoires d'atelier (uniquement si elles n'ont pas été personnalisées)
UPDATE storage_types SET default_color = '#e4e0d2' WHERE name = 'Armoire' AND is_builtin = 1 AND default_color = '#b08d5c';
UPDATE storage_types SET default_color = '#1f2a5c' WHERE name = 'Tiroir' AND is_builtin = 1 AND default_color = '#8fa3b8';
UPDATE storage_types SET default_color = '#c4263c' WHERE name = 'Bac' AND is_builtin = 1 AND default_color = '#6f9fd8';
