-- Migration : ajout de l'arbre de tournoi (bracket)
-- À exécuter une seule fois sur une base déjà existante (créée avant cette fonctionnalité).
-- Si vous repartez d'une base neuve avec schema.sql, cette migration est déjà incluse
-- et n'a pas besoin d'être rejouée.

USE sabre_arbitrage;

ALTER TABLE combats
  ADD COLUMN IF NOT EXISTS tour INT DEFAULT NULL COMMENT 'Numéro de tour du tournoi (1 = premier tour). NULL = combat hors arbre.' AFTER manche_actuelle,
  ADD COLUMN IF NOT EXISTS position INT DEFAULT NULL COMMENT 'Position du combat dans son tour, sert à tracer l''arbre (0-indexé).' AFTER tour;
