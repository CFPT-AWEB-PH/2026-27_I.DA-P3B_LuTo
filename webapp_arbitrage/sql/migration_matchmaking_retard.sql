-- Migration : règles d'appariement automatique configurables + retard cumulé.
-- À exécuter une seule fois sur une base existante. Idempotent.

USE sabre_arbitrage;

INSERT INTO parametres (cle, valeur, description) VALUES
  ('ecart_poids_max_kg', '0', 'Écart de poids maximum autorisé (kg) entre deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('ecart_age_max_ans', '0', 'Écart d''âge maximum autorisé (années) entre deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('ecart_grade_max', '0', 'Écart maximum autorisé entre les grades (niveaux) de deux joueurs appariés automatiquement — 0 = pas de limite'),
  ('appariement_meme_sexe', '0', 'N''apparier automatiquement que des joueurs de même sexe (1 = oui, 0 = non)'),
  ('retard_minutes', '0', 'Retard cumulé actuel (en minutes), signalé par un arbitre ou l''admin, affiché aux spectateurs'),
  ('intervalle_rotation_affichage_ms', '8000', 'Fréquence (en millisecondes) de rotation des écrans sur la page d''affichage public (écran de salle)')
ON DUPLICATE KEY UPDATE cle = cle;
