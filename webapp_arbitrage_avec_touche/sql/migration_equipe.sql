-- Migration : ajout du champ equipe dans la table joueurs
-- À exécuter une seule fois sur une base déjà existante.
-- Idempotent (IF NOT EXISTS) : peut être relancé sans risque.

USE sabre_arbitrage;

ALTER TABLE joueurs
  ADD COLUMN IF NOT EXISTS equipe VARCHAR(100) DEFAULT NULL COMMENT 'Équipe ou club du joueur' AFTER grade_id;
