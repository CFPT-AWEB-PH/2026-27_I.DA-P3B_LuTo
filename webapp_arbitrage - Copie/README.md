# Sabre Laser Arbitrage — App web PHP / MariaDB

Application complète de gestion de combats de sabre laser avec système d'arbitrage
(1 arbitre central + 2 arbitres de coin), calcul automatique de la moyenne des points,
chrono de combat, côté public (combats en cours / à venir / classement) et back-office admin en CRUD complet.

---

## 🐳 Installation avec Docker (recommandé)

**Prérequis :** Docker Desktop installé et démarré.

### Première installation

```bash
# 1. Cloner / télécharger le projet
cd sabre\ lazer\ arbitrage/webapp_arbitrage

# 2. Copier le fichier d'environnement
cp .env.example .env

# 3. Lancer l'application (build + démarrage en une commande)
docker compose up --build
```

Le site est disponible sur **http://localhost:8080**

La base de données est initialisée automatiquement au premier démarrage (schéma + migrations).

### Compte admin par défaut

| Identifiant | Mot de passe |
|-------------|--------------|
| `admin`     | `admin123`   |

> **⚠️ Changez ce mot de passe dès la première connexion** (Admin → Utilisateurs → Éditer).

### Arrêter l'application

```bash
docker compose down
```

### Réinitialiser complètement la base de données

```bash
# Supprime les conteneurs ET le volume de données (repart de zéro)
docker compose down -v
docker compose up --build
```

### Configurer les variables d'environnement

Toutes les options sont dans le fichier `.env` :

```env
APP_PORT=8080          # Port d'accès local (http://localhost:APP_PORT)

DB_ROOT_PASSWORD=…     # Mot de passe root MariaDB
DB_NAME=sabre_arbitrage
DB_USER=arbitrage_user
DB_PASS=arbitrage_pass

BASE_URL=/             # Laisser / sauf si l'app est dans un sous-dossier
```

---

## ⚙️ Installation manuelle (sans Docker)

**Prérequis :** PHP 8+, extension `pdo_mysql`, MariaDB/MySQL, serveur Apache ou Nginx.

1. **Base de données** — importer le schéma et les migrations :
   ```bash
   mysql -u root -p < sql/schema.sql
   mysql -u root -p sabre_arbitrage < sql/migration_profils_parametres.sql
   mysql -u root -p sabre_arbitrage < sql/migration_arbre.sql
   mysql -u root -p sabre_arbitrage < sql/migration_polling_config.sql
   mysql -u root -p sabre_arbitrage < sql/migration_matchmaking_retard.sql
   ```

2. **Configuration** — éditer `config/database.php` avec vos identifiants MariaDB :
   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'sabre_arbitrage';
   $DB_USER = 'votre_user';
   $DB_PASS = 'votre_pass';
   ```

3. **Serveur web** — pointer le DocumentRoot sur la racine du projet.  
   Test rapide avec le serveur intégré PHP :
   ```bash
   php -S localhost:8000
   ```
   puis ouvrir http://localhost:8000

---

## Fonctionnement du système d'arbitrage

- Chaque **combat** oppose 2 joueurs et se déroule en **manches**.
- 3 arbitres sont assignés : **1 arbitre central** + **2 arbitres de coin**.
- À chaque manche, chaque arbitre entre un score (0 à 10) depuis son espace "Arbitrage".
- Dès que **les 3 arbitres ont voté**, l'app calcule la **moyenne des 3 votes** pour chaque joueur,
  l'ajoute au score cumulé et passe à la manche suivante.
- Le combat a une **durée définie à l'avance**. À l'expiration du chrono, le combat est
  **automatiquement clôturé** (déclenché à chaque chargement de page — aucune tâche cron nécessaire)
  et le **vainqueur** est déterminé.

## Rôles

| Rôle | Accès |
|------|-------|
| **admin** | CRUD complet (joueurs, arbitres, combats), démarrer/terminer les combats |
| **arbitre** | Saisie des scores manche par manche pour les combats assignés |
| **joueur / public** | Inscription libre, consultation des combats en cours et du classement |

## Structure du projet

```
config/       Connexion DB + bootstrap (session, helpers)
includes/     auth.php, functions.php (logique métier), header/footer
admin/        Back-office CRUD
arbitrage/    Espace de saisie des points
api/          Endpoint JSON pour le live (scores/chrono en AJAX)
assets/       CSS + JS
sql/          Schéma et migrations
docker/       Fichiers Docker (Apache config, entrypoint, init SQL)
```

## Sécurité

- Mots de passe hashés (`password_hash` / `password_verify`).
- Protection CSRF sur tous les formulaires et actions sensibles.
- Requêtes préparées PDO partout — aucune concaténation SQL.
- Contrôle strict des rôles (`requireRole()`) sur toutes les pages protégées.
