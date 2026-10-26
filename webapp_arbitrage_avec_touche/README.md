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

> **À la première connexion, l'application impose de changer ce mot de passe** (page « Mon compte »).
> Avant tout démarrage, remplissez `.env` à partir de `.env.example` avec des mots de passe solides :
> l'application refuse de démarrer sans `DB_USER` / `DB_PASS`.

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

## 📱 Application installable (PWA)

L'app s'installe sur l'écran d'accueil (Android, iPhone, PC) et s'ouvre en plein écran comme une vraie app :
bouton **« Installer l'application »** en bas de page (Android / Chrome / Edge), ou Safari › Partager ›
« Sur l'écran d'accueil » (iPhone).

- `assets/pwa/` : manifeste, icônes, page « hors connexion »
- `sw.js` (racine) : Service Worker. Les pages et l'API passent **toujours** par le réseau
  (jamais de score périmé), seuls CSS / JS / polices / icônes sont mis en cache.
  Après une modification de CSS ou JS, incrémenter `ASSETS_VERSION` dans `includes/functions.php`
  (toutes les pages l'utilisent via `asset()` / `scriptJs()`) **et** la même valeur dans `PRECACHE`
  de `sw.js` (et `VERSION` pour vider les anciens caches).
- ⚠️ Une PWA exige **HTTPS** (ou `localhost`). En `http://<IP>:8080` l'app marche, mais n'est pas installable :
  sur le Raspberry, le HTTPS est fourni par le point d'accès Wi-Fi ci-dessous.

---

## 🎨 Apparence : thèmes et logos

**Admin › Apparence** (permission « Paramètres ») :

- **Thème** pour toute l'application, l'écran de salle et l'app installée : *Basique* (sombre),
  *Clair* (le basique en blanc), *Orange · blanc · bleu* (fond blanc, barre bleu marine, accents orange).
- **Thèmes personnalisés** : « Créer un thème » (ou « Copier » sur un thème existant), choisir l'ambiance
  (sombre / clair) et 8 couleurs (fond, cartes, texte, bouton principal, accent, barre du haut, joueur 1, joueur 2),
  avec un aperçu en direct. Les nuances (survols, bordures, textes secondaires, texte sur les boutons) sont
  calculées avec un contraste lisible. Modifiables et supprimables ; stockés en base (table `themes_perso`).
- Le **portail du Wi-Fi** du Raspberry reprend automatiquement le nom de l'application, les couleurs du thème
  et le logo choisis ici (relus toutes les 15 s via `portail_apparence.php`).
- **Logos** : on en envoie autant qu'on veut (PNG, JPEG ou WebP, 2 Mo max), ils restent enregistrés ;
  « Utiliser » change d'un coup le logo de la barre, du pied de page, de la page de connexion, de l'écran
  de salle, le favicon et l'icône de l'app installée. « Lame d'origine » revient au logo de départ.
- Les logos sont stockés **en base** (table `logos`, le code étant en lecture seule dans le conteneur),
  vérifiés puis ré-encodés en PNG avec GD et déclinés en toutes les tailles (`logo.php`, `manifest.php`).
- Fichiers : `admin/apparence.php`, `includes/apparence.php`, `logo.php`, `manifest.php`, section 20 de `style.css`.

---

## 📶 Raspberry Pi : Wi-Fi du tournoi (isolé, portail identifiant + mot de passe)

Le Raspberry diffuse son propre Wi-Fi. En le rejoignant, une **page de connexion** s'ouvre et demande un
**identifiant et un mot de passe** ; tant qu'un appareil ne s'est pas identifié, il n'accède à rien.
Les comptes se gèrent dans l'app : **Admin › Wi-Fi** (appliqués sur le Pi en ~15 s ; un compte supprimé ou
désactivé est déconnecté). Le **nom du Wi-Fi** se change au même endroit (« Renommer ») : le point d'accès
redémarre quelques secondes, les appareils rejoignent le nouveau nom sans se réidentifier.
Après l'identification, **le site s'affiche tout seul** ; il s'ouvre ensuite dans n'importe quel navigateur
(Chrome, Safari, Firefox, Samsung Internet, Edge…) en tapant `10.42.0.1` (ou `http://sabre.lan`) : toute
adresse `http://` tapée sur ce Wi-Fi ramène d'ailleurs au site.

- **Isolé** : pas d'internet, pas d'accès au réseau local ni au SSH du Pi depuis ce Wi-Fi.
- **LAN** : par défaut l'app reste aussi joignable sur le réseau local (`http://<IP-du-Pi>:8080`).
  Avec `--wifi-seulement`, elle n'est plus joignable que par le Wi-Fi.
- **Sans certificat** : l'app est servie directement sur `http://sabre.lan`.
  **HTTPS facultatif** avec une autorité de certification locale (limitée au domaine `sabre.lan` : elle ne peut pas
  servir à intercepter d'autres sites), à installer depuis `http://sabre.lan/certificat`.
- Si les ports 80/443 du Pi sont déjà pris (Apache, autre conteneur…), le script propose d'arrêter le service,
  sinon le proxy passe sur 8081/8443 et le Wi-Fi y est redirigé automatiquement.
- Pourquoi un portail et pas un Wi-Fi « Entreprise » (identifiant demandé par le téléphone lui-même) ?
  La puce Wi-Fi intégrée du Raspberry ne le gère pas en point d'accès (les téléphones sont rejetés en boucle).

### Installation (sur le Pi, avec internet par câble Ethernet le temps de l'installation)

```bash
cp .env.example .env        # puis remplir les mots de passe
sudo bash raspberry/install_wifi.sh --ssid "Sabre-Arbitrage" --pays CH
```

Options utiles : `--mot-de-passe-wifi XXXXXXXX` (mot de passe commun en plus du portail), `--wifi-ouvert` (défaut),
`--session-heures 12`, `--wifi-seulement`. Le script peut être relancé sans risque.

### Se connecter (téléphone)

1. Rejoindre le Wi-Fi (**Sabre-Arbitrage** par défaut) : la page de connexion s'ouvre seule sur Android, iPhone,
   iPad, Mac et Windows (sinon ouvrir `10.42.0.1` dans le navigateur).
2. Identifiant + mot de passe (Admin › Wi-Fi, ou compte de secours du Pi) : le site s'affiche.
3. Dans le navigateur de son choix : `10.42.0.1` ou `http://sabre.lan`, **rien à installer**. Bouton
   « Installer l'application » en bas de page pour l'ajouter à l'écran d'accueil.
   Sur Android, la petite fenêtre de connexion peut se fermer d'elle-même une fois connecté : ouvrir alors le
   navigateur, et répondre « Rester connecté » si le téléphone signale « pas d'accès à internet ».
4. Facultatif : `http://sabre.lan/certificat` pour installer le certificat et utiliser `https://sabre.lan`
   (nécessaire seulement pour l'app en vrai plein écran sur Android).

### Commandes utiles

```bash
sudo sabre-wifi statut                        # services, comptes, appareils
sudo sabre-wifi sessions                      # qui est connecté (IP, MAC, compte)
sudo sabre-wifi deconnecter <ip|identifiant|tous>  # refaire passer un appareil par le portail
sudo sabre-wifi secours-ajouter <identifiant> # compte local (marche même si l'app est arrêtée)
sudo sabre-wifi secours-supprimer <identifiant>
sudo sabre-wifi definir-ssid "Nom du Wi-Fi"   # renommer le Wi-Fi (comme Admin › Wi-Fi)
sudo bash raspberry/install_wifi.sh --desinstaller   # retirer le point d'accès
```

Fichiers : `raspberry/install_wifi.sh`, `raspberry/sabre-portail.py` (portail), `raspberry/sabre-wifi`
(synchro des comptes), `raspberry/Caddyfile`, `raspberry/accueil/`, `admin/wifi.php`, `includes/wifi.php`.

---

## 🔵 Sabres Bluetooth (récepteur sur le Raspberry)

Le service `ble_receiver` (profil Docker `ble`) se connecte aux sabres enregistrés dans **Admin › Sabres**
et enregistre leurs touches. Il ne démarre que si `ble` figure dans `COMPOSE_PROFILES` du `.env`
(`install_wifi.sh` l'ajoute tout seul si le Pi a une puce Bluetooth) :

```bash
# .env sur le Pi
COMPOSE_PROFILES=pi,ble
```

```bash
docker compose up -d --build
docker logs -f sabre_ble          # « ✓ Connecté à … », « HIT reçu de … »
```

Admin › Sabres affiche l'état du récepteur (actif, arrêté, en erreur, jamais démarré).
Le service `bluetooth` du Pi doit être actif (`sudo systemctl status bluetooth`).

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

Voir **SECURITE.md** pour l'audit complet. En résumé :

- Mots de passe hashés (bcrypt via `password_hash`), politique de 8 caractères minimum,
  changement obligatoire du mot de passe par défaut, sessions révoquées au changement.
- Connexion protégée contre la force brute (5 échecs par identifiant, 20 par IP, sur 15 min).
- Session : cookie HttpOnly + SameSite=Lax (+ Secure en HTTPS), expiration après 8 h d'inactivité.
- CSRF sur tous les formulaires et actions (plus aucune action par lien GET).
- Permissions vérifiées côté serveur sur chaque page et API, anti escalade de privilèges.
- En-têtes : CSP avec nonce, X-Frame-Options, nosniff, Referrer-Policy, HSTS en HTTPS.
- Apache refuse tout par défaut : `.env`, SQL, sauvegardes, sources internes ne sont jamais servis.
- Erreurs jamais affichées au visiteur (référence d'erreur + journal serveur).
- Tunnel Cloudflare (accès internet) désactivé par défaut : `docker compose --profile tunnel up -d`.
- Tests : `python3 tests/securite_test.py http://IP:8080` (sur une base de TEST uniquement).
