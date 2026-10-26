# Journal de bord
# 17.08:
## rentrée scolaire : 
- choix du projet :
   - nous avons choisis ce projet car nous le trouvons intéressant, de plus, on pourra intégrer le projet de l'atelier Raspberry au site web, ce qui nous a motiver a prendre ce projet.
- mise en place du poste de travail :
   - installation des disque durs flashé:
        - installation de wsl
        - etc
# 20.08:
## Lucas : 
- abscent
## Tom : 
- Mise en place de la maquette :
-  Index.php  (page principal) accessible a tout le monde :
  ![maquette](images/maquette.png)
- arbre.php (visualisation en style "arbre") accessible par tout le monde, inspiré des images de grand tournois (coupe du monde, etc) :
  ![arbres](images/tournoiEnArbre.jpg)
-  classement.php, accessible par tout le monde
  ![classement](images/classement.drawio.png)
- écran.php, écran d'affichage (ex : écran principal de la salle) qui affiche en continue, les matche en cours, ceux a venir et les scores
- admin.php / dashboard, page accessible uniquement au admin, permet de gérer le tournois, lancer des matches, modifier les participant, changer les paramètres du tournois.
  ![dashboard](images/dashboard.drawio.png)
- arbitre.php, accessible uniquement aux admin ainsi que aux arbitres, sers a arbitrer les matches, donner des points au participants.
# 24.08:
## Brainstorming :
- Nous avons réfléchie a l'infrastructure du site web.
     - premièrement on pensais faire une architecture sur une seul machine, tout centralise sur le Raspberry, au final on est partie sur une architecture comme la suivante ↓
- site web inscription -> fichier Excel -> serveur Raspberry du tournoi
     - réflexion derrière ce choix : cela facilitera l'inscription de participant, car ensuite l'administrateur n'aura qu'a importer le fichier csv final et commencer le tournois.
- infrastructure  : ![schema](images/shema.png)
## Lucas :
- Lucas a commencer a modifié le CSS crée ( / générer ) par Tom :
   - Changement de toute l'interface pour correspondre au maquette
   - Changement du CSS pour un thème plus "monochrome", plus correspondant a un site professionnel
   - dévlopement du backend notamment l'api:
        - l'api retourne sur un match renseigne via son id, celle si retourne notamment son status, le score des deux joueurs, le temps restant, le vainqueur etc    
## Tom :
   - Tom a générer grâce a l'intelligence artificiel, une base utilisable du projet, celle ci ayant pleins de problème devra être complétement modifier, mais elle donne une pour le style css, par exemple.
   - problème rencontrer : l'importation d'un fichier csv en donne php :
        - la documentation de php présente des fonctions qui nous sers pour résoudre ce probleme :
        - [premier liens](https://www.php.net/manual/en/ref.filesystem.php) les fonctions filesysteme.
        - et notamment la fonction [fgetcsv](https://www.php.net/manual/en/function.fgetcsv.php)
     - Commencement du développement du backend / base de donnée, création du la base mariadb, et de la création du schème de db.
# 31.08:
## Lucas:
- Création et rédaction plus poussé du README.MD.
   - Ajouts d'une documentation plus poussé sur les technologie utilise pour ce projet, architecture du projet explique plus en détails
   - continuer le développement du backend
## tom:
- absent
# 07.09:
## Lucas :
- absent
## Tom :
- Dockerisation complète de l'application :
   - création du `Dockerfile` :
        - image de base `php:8.2-apache`
        - installation des extensions PHP nécessaires : `pdo`, `pdo_mysql`, `mysqli`
        - activation du module Apache `mod_rewrite`
        - intégration d'un script `entrypoint.sh` qui attend que MariaDB soit prêt avant de démarrer Apache
   - création du `docker-compose.yml` :
        - service `web` (PHP 8.2 + Apache) exposé sur le port `8080`
        - service `db` (MariaDB 11) avec healthcheck automatique
        - volume persistant `db_data` pour les données de la base
        - passage des credentials via variables d'environnement
   - création du fichier `docker/apache.conf` :
        - configuration du VirtualHost Apache avec `AllowOverride All`
   - création du fichier `docker/entrypoint.sh` :
        - script bash qui teste la connexion PDO à MariaDB en boucle avant de lancer Apache
   - création du fichier `docker/init.sql` :
        - fusion de `sql/schema.sql` et de toutes les migrations en un seul fichier SQL
        - exécuté automatiquement par MariaDB au premier démarrage du conteneur
        - problème rencontré : la commande `SOURCE` de MariaDB ne fonctionne pas dans le conteneur car il n'a pas accès aux fichiers PHP — résolution en concaténant directement tous les fichiers SQL en un seul `init.sql`
   - création des fichiers `.env` / `.env.example` :
        - variables configurables : `APP_PORT`, `DB_ROOT_PASSWORD`, `DB_NAME`, `DB_USER`, `DB_PASS`, `BASE_URL`
   - création du `.dockerignore` :
        - exclusion de `.env`, `.git`, `*.md`, `docker-compose*.yml`
   - modification de `config/database.php` :
        - remplacement des credentials codés en dur par des appels `getenv()` avec valeur par défaut
   - modification de `config/config.php` :
        - `BASE_URL` désormais lu depuis la variable d'environnement (plus de chemin `/webapp_arbitrage/` codé en dur)
   - mise à jour du `README.md` :
        - section Docker placée en tête avec les 3 commandes clés (`up --build`, `down`, `down -v`)
        - tableau des variables d'environnement `.env`
        - procédure de réinitialisation de la base documentée
        - installation manuelle conservée en dessous pour référence
- résultat : l'application se lance avec une seule commande :
   ```
   docker compose up --build
   ```
   Le site est accessible sur http://localhost:8080, la base de données est initialisée automatiquement.
