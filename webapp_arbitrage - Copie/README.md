# Sabre Laser Arbitrage — App web PHP / MariaDB

Application complète de gestion de combats de sabre laser avec système d'arbitrage
(1 arbitre central + 2 arbitres de coin), calcul automatique de la moyenne des points,
chrono de combat, côté public (combats en cours / à venir / classement) et back-office admin en CRUD complet.

## Installation

1. **Base de données** : créez la base en important le fichier SQL fourni :
   ```
   mysql -u root -p < sql/schema.sql
   ```
   Cela crée la base `sabre_arbitrage`, toutes les tables, et un compte admin par défaut :
   - Identifiant : `admin`
   - Mot de passe : `admin123`
   **Changez ce mot de passe dès la première connexion** (Admin → Utilisateurs → Éditer).

2. **Configuration** : ouvrez `config/database.php` et adaptez si besoin :
   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'sabre_arbitrage';
   $DB_USER = 'root';
   $DB_PASS = '';
   ```

3. **Serveur web** : placez le dossier sur un serveur avec PHP 8+ et l'extension `pdo_mysql`
   (Apache/Nginx + PHP-FPM, ou XAMPP/WAMP/MAMP en local). Le point d'entrée public est `index.php`
   à la racine.

   Pour tester rapidement en local avec le serveur intégré de PHP :
   ```
   php -S localhost:8000
   ```
   puis ouvrez http://localhost:8000

## Fonctionnement du système d'arbitrage

- Chaque **combat** oppose 2 joueurs et se déroule en **manches**.
- 3 arbitres sont assignés à chaque combat : **1 arbitre central** + **2 arbitres de coin**.
- À chaque manche, chaque arbitre entre un score (0 à 10) pour chacun des 2 joueurs depuis
  son espace "Arbitrage".
- Dès que **les 3 arbitres ont voté** pour la manche en cours, l'application calcule
  automatiquement **la moyenne des 3 votes** pour chaque joueur, l'ajoute au score cumulé
  du combat, et passe à la manche suivante.
- Le combat a une **durée définie à l'avance** (en secondes). Le chrono démarre quand
  l'admin clique sur "Démarrer". Dès que le temps est écoulé, le combat est **automatiquement
  clôturé** (déclenché à chaque chargement d'une page publique/admin — aucune tâche cron
  n'est nécessaire) et le **vainqueur** (meilleur score cumulé) est déterminé et affiché.
  Les statistiques du joueur (victoires/défaites/points) sont mises à jour.

## Rôles

- **admin** : accès total (CRUD joueurs, utilisateurs/arbitres, combats ; démarrer/terminer
  un combat, affecter les 3 arbitres).
- **arbitre** : accède à "Arbitrage" pour voir les combats qui lui sont assignés et saisir
  ses points manche par manche.
- **joueur / public** : création de compte libre (nom d'utilisateur unique obligatoire),
  accès au site public (combats en cours, à venir, classement).

## Structure du projet

```
config/       connexion DB + bootstrap (session, helpers)
includes/     auth.php (connexion/inscription/rôles), functions.php (logique métier
              des combats/moyennes/chrono), header.php/footer.php (template)
admin/        back-office CRUD (joueurs, utilisateurs/arbitres, combats)
arbitrage/    espace de saisie des points pour les arbitres
api/          endpoint JSON utilisé en AJAX pour le direct (scores/chrono en temps réel)
assets/       CSS + JS (rafraîchissement live des scores et du chrono)
sql/          schéma de base de données complet (schema.sql)
index.php, login.php, register.php, logout.php, classement.php, combat.php
              pages publiques (racine du site)
```

## Sécurité incluse

- Mots de passe hashés (`password_hash`/`password_verify`), jamais stockés en clair.
- Protection CSRF sur tous les formulaires et actions sensibles (GET d'action incluent un jeton).
- Toutes les requêtes SQL utilisent des requêtes préparées (PDO), aucune concaténation
  de valeurs utilisateur dans le SQL.
- Contrôle des rôles strict (`requireRole()`) sur toutes les pages admin/arbitrage.
- Contrainte d'unicité sur le nom d'utilisateur au niveau base de données ET applicatif.

Testé de bout en bout (inscription, connexion, création de compte unique, création de
combat, affectation des 3 arbitres, vote multi-arbitres avec calcul de moyenne, clôture
automatique du combat à l'expiration du chrono, détermination du vainqueur, mise à jour
du classement, protections CSRF et de suppression en cascade).
