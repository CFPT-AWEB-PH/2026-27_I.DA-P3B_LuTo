-- Données de démonstration (audit UX) : un tournoi en cours avec un arbre,
-- deux combats en direct, des combats à venir, des résultats et des touches.
-- À utiliser sur une base de TEST uniquement.
--
-- Comptes créés (mot de passe commun : demo-ux-2026) :
--   demo_admin (administrateur) · arb_lea, arb_noah, arb_ines, arb_hugo, arb_sara, arb_tom (arbitres)
--
-- Réinitialiser : relancer ce fichier (il supprime d'abord ses propres données).

SET @h = '$2y$10$uoiSLqR.BI7g7YtgdOGx1ePoiGOOOHbTvIUv5YymI2NbONvBJNnNa';

DELETE FROM touchers; DELETE FROM points_live; DELETE FROM combat_arbitres; DELETE FROM combats;
DELETE FROM sabres; DELETE FROM joueurs;
DELETE FROM users WHERE username IN ('demo_admin','arb_lea','arb_noah','arb_ines','arb_hugo','arb_sara','arb_tom');

INSERT INTO users (username, password_hash, role, prenom, nom) VALUES
 ('demo_admin', @h, 'admin',   'Démo', 'Admin'),
 ('arb_lea',  @h, 'arbitre', 'Léa', 'Marchand'),
 ('arb_noah', @h, 'arbitre', 'Noah', 'Perret'),
 ('arb_ines', @h, 'arbitre', 'Inès', 'Rochat'),
 ('arb_hugo', @h, 'arbitre', 'Hugo', 'Favre'),
 ('arb_sara', @h, 'arbitre', 'Sara', 'Blanc'),
 ('arb_tom',  @h, 'arbitre', 'Tom', 'Girard');

INSERT INTO joueurs (id, pseudo, prenom, nom, sexe, poids, grade_id, equipe, victoires, defaites, matchs_joues, points_cumules, points_victoire) VALUES
 (1, 'Kaelen Vos',    'Kaelen', 'Vos',    'M', 72, 3, 'Genève Sabres', 1, 0, 1, 14, 2),
 (2, 'Mira Solenne',  'Mira',   'Solenne','F', 61, 2, 'Lausanne Lames', 0, 1, 1, 6, 0),
 (3, 'Darius Okonkwo','Darius', 'Okonkwo','M', 80, 4, 'Genève Sabres', 1, 0, 1, 19, 3),
 (4, 'Yuna Takeda',   'Yuna',   'Takeda', 'F', 58, 3, 'Vevey Kenshi', 0, 1, 1, 2, 0),
 (5, 'Elio Brandt',   'Elio',   'Brandt', 'M', 75, 2, 'Lausanne Lames', 0, 0, 0, 0, 0),
 (6, 'Saskia Moreau', 'Saskia', 'Moreau', 'F', 64, 3, NULL, 0, 0, 0, 0, 0),
 (7, 'Rafael Quint',  'Rafael', 'Quint',  'M', 70, 1, 'Vevey Kenshi', 0, 0, 0, 0, 0),
 (8, 'Anouk Lindqvist-Barrientos', 'Anouk', 'Lindqvist-Barrientos', 'F', 66, 2, 'Genève Sabres', 0, 0, 0, 0, 0);

INSERT INTO sabres (id, mac_address, nom, joueur_id, actif, last_seen) VALUES
 (1, 'C4:86:96:A2:90:01', 'Sabre-01', NULL, 1, NOW()),
 (2, 'C4:86:96:A2:90:02', 'Sabre-02', NULL, 1, NOW());

-- Tour 1 : 2 terminés, 2 en cours. Tour 2 : 2 à venir (vainqueurs connus pour l'un).
INSERT INTO combats (id, joueur1_id, joueur2_id, duree_secondes, statut, date_prevue, temps_debut, temps_fin, score_joueur1, score_joueur2, vainqueur_id, points_victoire, tour, position) VALUES
 (1, 1, 2, 180, 'termine', NOW() - INTERVAL 40 MINUTE, NOW() - INTERVAL 40 MINUTE, NOW() - INTERVAL 37 MINUTE, 14, 6, 1, 2, 1, 0),
 (2, 3, 4, 180, 'termine', NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 27 MINUTE, 19, 2, 3, 3, 1, 1),
 (3, 5, 6, 900, 'en_cours', NOW() - INTERVAL 2 MINUTE, NOW() - INTERVAL 95 SECOND, NULL, 0, 0, NULL, NULL, 1, 2),
 (4, 7, 8, 900, 'en_cours', NOW() - INTERVAL 2 MINUTE, NOW() - INTERVAL 40 SECOND, NULL, 0, 0, NULL, NULL, 1, 3),
 (5, 1, 3, 180, 'a_venir', NOW() + INTERVAL 18 MINUTE, NULL, NULL, 0, 0, NULL, NULL, 2, 0),
 (6, 5, 7, 180, 'a_venir', NOW() + INTERVAL 26 MINUTE, NULL, NULL, 0, 0, NULL, NULL, 2, 1),
 (7, 2, 4, 120, 'a_venir', NOW() + INTERVAL 4 MINUTE, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL);

SET @lea  = (SELECT id FROM users WHERE username='arb_lea');
SET @noah = (SELECT id FROM users WHERE username='arb_noah');
SET @ines = (SELECT id FROM users WHERE username='arb_ines');
SET @hugo = (SELECT id FROM users WHERE username='arb_hugo');
SET @sara = (SELECT id FROM users WHERE username='arb_sara');
SET @tom  = (SELECT id FROM users WHERE username='arb_tom');

INSERT INTO combat_arbitres (combat_id, user_id, role) VALUES
 (1,@lea,'central'),(1,@noah,'coin1'),(1,@ines,'coin2'),
 (2,@hugo,'central'),(2,@sara,'coin1'),(2,@tom,'coin2'),
 (3,@lea,'central'),(3,@noah,'coin1'),(3,@ines,'coin2'),
 (4,@hugo,'central'),(4,@sara,'coin1'),(4,@tom,'coin2'),
 (5,@lea,'central'),(5,@noah,'coin1'),(5,@ines,'coin2'),
 (6,@hugo,'central'),(6,@sara,'coin1'),
 (7,@lea,'central'),(7,@tom,'coin1'),(7,@sara,'coin2');

-- Points en direct (combat 3 : Elio mène ; combat 4 : quasi égalité)
INSERT INTO points_live (combat_id, arbitre_user_id, joueur_num, valeur) VALUES
 (3,@lea,1,3),(3,@lea,1,2),(3,@lea,2,1),(3,@lea,1,3),
 (3,@noah,1,3),(3,@noah,1,3),(3,@noah,2,1),(3,@noah,1,1),
 (3,@ines,1,2),(3,@ines,1,3),(3,@ines,2,2),(3,@ines,1,2),
 (4,@hugo,1,2),(4,@hugo,2,3),
 (4,@sara,1,2),(4,@sara,2,2),
 (4,@tom,1,3),(4,@tom,2,2);
UPDATE combats SET score_joueur1 = 7, score_joueur2 = 1 WHERE id = 3;
UPDATE combats SET score_joueur1 = 2, score_joueur2 = 2 WHERE id = 4;

-- Touches des sabres (combat 3 en cours, combat 1 terminé)
INSERT INTO touchers (combat_id, sabre_id, joueur_id, timestamp_ms, type) VALUES
 (3, 1, 5, (UNIX_TIMESTAMP(NOW() - INTERVAL 80 SECOND))*1000, 'touche'),
 (3, 2, 6, (UNIX_TIMESTAMP(NOW() - INTERVAL 61 SECOND))*1000, 'touche'),
 (3, 1, 5, (UNIX_TIMESTAMP(NOW() - INTERVAL 44 SECOND))*1000, 'touche'),
 (3, 1, NULL, (UNIX_TIMESTAMP(NOW() - INTERVAL 20 SECOND))*1000, 'double'),
 (3, 1, 5, (UNIX_TIMESTAMP(NOW() - INTERVAL 8 SECOND))*1000, 'touche'),
 (1, 1, 1, (UNIX_TIMESTAMP(NOW() - INTERVAL 39 MINUTE))*1000, 'touche'),
 (1, 2, 2, (UNIX_TIMESTAMP(NOW() - INTERVAL 38 MINUTE))*1000, 'touche'),
 (1, 1, 1, (UNIX_TIMESTAMP(NOW() - INTERVAL 38 MINUTE) + 40)*1000, 'touche');
