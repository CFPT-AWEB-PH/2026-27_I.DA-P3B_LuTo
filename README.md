# 2026-27_I.DA-P3B_LuTo

Liens vers journal de bord : [journal de bord](https://github.com/CFPT-AWEB-PH/2026-27_I.DA-P3B_LuTo/blob/main/journalDeBord.md)

# Documentation technique — webapp-arbitrage

## 1. Présentation

Application web de gestion d'arbitrage / tournois (combats, joueurs, classement, arbre de tournoi), développée en **PHP natif**.

## 2. Stack technique

| Composant | Technologie |
|---|---|
| Backend | PHP natif |
| Base de données | MySQL / MariaDB (fichiers `.sql` de migration + schéma) |
| Frontend | HTML/CSS/JS classiques (dossier `assets/css`, `assets/js`) |
| Authentification | Système maison (`includes/auth.php`) |
| API interne | Endpoints PHP dédiés (dossier `api/`) |

## 3. Structure du projet

```
webapp-arbitrage/
├── admin/              # Back-office (gestion combats, joueurs, users, paramètres)
├── api/                # Endpoints API internes (ex: get_combat_status.php)
├── arbitrage/          # Interface de saisie/consultation pour les arbitres
├── assets/             # Ressources statiques (css/, js/)
├── config/             # Configuration (config.php, database.php)
├── includes/           # Composants partagés (auth, header, footer, fonctions utilitaires, icônes)
├── sql/                # Schéma de base + scripts de migration
├── arbre.php           # Arbre de tournoi
├── classement.php      # Classement des joueurs
├── combat.php          # Détail/affichage d'un combat
├── ecran.php           # Écran d'affichage 
├── index.php           # Page d'accueil
├── login.php / logout.php / register.php  # Gestion des sessions utilisateurs
├── scores.php          # Gestion/affichage des scores
└── README.md
```

## 4. Découpage par responsabilité

- **`admin/`** — Toutes les opérations de gestion réservées aux administrateurs : combats, joueurs, paramètres, utilisateurs. Utilise probablement un système d'onglets (`_tabs.php`) et vérifie les droits via `includes/auth.php`.
- **`arbitrage/`** — Interface dédiée aux arbitres pour la saisie des résultats en temps réel (`saisie.php`) et la consultation (`index.php`).
- **`api/`** — Endpoints PHP retournant du JSON, consommés en Ajax/JS par le frontend pour du polling en temps réel.
  - `get_combat_status.php` : reçoit un `id` de combat en `GET`, purge d'abord les combats expirés (`purgerCombatsExpires`), récupère le combat (`getCombat`), puis renvoie son statut, les scores des deux joueurs, le temps restant (`tempsRestant`), le vainqueur (id + pseudo) et la manche en cours. Gère les erreurs 400 (id manquant) et 404 (combat introuvable).
- **`config/`** — Centralise la connexion base de données (`database.php`) et les constantes/paramètres globaux (`config.php`).
- **`includes/`** — Fichiers inclus dans plusieurs pages : authentification, layout (header/footer), fonctions utilitaires communes, gestion des icônes.
- **`sql/`** — Le schéma initial (`schema.sql`) et les migrations versionnées (arbre, matchmaking, config polling, profils) témoignent d'une évolution incrémentale de la base.
- **Racine** — Pages "métier" directement accessibles (arbre de tournoi, classement, écran, scores, login/logout/register).

## 5. Points d'architecture à noter

- **Séparation admin / arbitrage / public** claire au niveau des dossiers, ce qui facilite la gestion des droits d'accès.
