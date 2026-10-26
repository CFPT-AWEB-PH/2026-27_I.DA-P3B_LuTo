-- Migration : rend configurable la fréquence de rafraîchissement en direct
-- (scores/chrono côté public et arbitrage). À exécuter une seule fois sur
-- une base existante ayant déjà reçu migration_profils_parametres.sql.
-- Idempotent : peut être relancée sans risque.

USE sabre_arbitrage;

INSERT INTO parametres (cle, valeur, description) VALUES
  ('intervalle_actualisation_ms', '4000', 'Fréquence (en millisecondes) de rafraîchissement automatique des scores et du chrono en direct')
ON DUPLICATE KEY UPDATE cle = cle;
