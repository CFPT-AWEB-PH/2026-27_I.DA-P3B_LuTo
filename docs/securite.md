# Audit de sécurité : Sabre Laser Arbitrage

Date : 24/09/2026. Périmètre : application PHP (pages, API JSON, administration), configuration
Apache/PHP, Docker Compose, base MariaDB, daemon BLE (Python), dépendances.

## Modèle de menace (résumé)

- **À protéger** : comptes (surtout administrateurs), scores et résultats officiels, classement,
  données personnelles des joueurs (nom, date de naissance, poids), identifiants de la base (`.env`).
- **Acteurs** : spectateurs anonymes (réseau du club, ou internet si le tunnel est actif), joueurs
  inscrits, arbitres, organisateurs avec rôles personnalisés, administrateurs.
- **Frontières de confiance** : navigateur vers Apache/PHP ; tunnel Cloudflare (internet) vers le
  conteneur web ; PHP vers MariaDB ; daemon BLE vers MariaDB ; fichier CSV importé.
- **Abus envisagés** : prise de contrôle du compte admin, arbitre qui gonfle ou note un combat qui
  n'est pas le sien, organisateur qui s'octroie plus de droits, spectateur qui télécharge `.env`,
  saturation de l'API publique, fichiers piégés, injection de formules dans l'export CSV.

## Constats et corrections

### Critique

| # | Fichier | Problème | Impact | Correction |
|---|---------|----------|--------|------------|
| C1 | `docker-compose.yml`, `docker/apache.conf` | Le dossier du projet est monté comme racine web et Apache servait tout : `/.env` (mots de passe de la base), `/docker/init.sql`, `/sql/`, `/_backup_*`, `README.md`, code Python, listing des dossiers. | Vol des identifiants de la base et de tout le code par n'importe quel visiteur, y compris depuis internet via le tunnel. | Vhost Apache « tout refusé par défaut » : seuls les `.php` publics et `assets/` sont servis ; dossiers internes, fichiers cachés et extensions sensibles refusés ; `Options -Indexes`, `AllowOverride None`. Vérifié : 29 chemins sensibles testés, tous en 403. |
| C2 | `docker/init.sql`, `README.md` | Compte `admin` / `admin123` créé par défaut et publié dans le README, avec un tunnel Cloudflare qui démarrait par défaut. | Prise de contrôle totale de l'application depuis internet. | Tout compte encore sur `admin123` doit changer son mot de passe avant toute action (page `compte.php`, migration automatique) ; tunnel passé derrière un profil explicite (`--profile tunnel`). |
| C3 | `config/database.php` | Identifiants de base de secours codés en dur (`tomcnnng` / `Super`). | Fuite d'un mot de passe réel dans le code et les sauvegardes. | Supprimés : l'application refuse de démarrer sans `DB_USER` / `DB_PASS`. **Changez ce mot de passe partout où il est utilisé.** |

### Élevé

| # | Fichier | Problème | Impact | Correction |
|---|---------|----------|--------|------------|
| E1 | `includes/auth.php`, `login.php` | Aucune protection contre la force brute. | Mots de passe devinables en ligne. | Limites de 5 échecs par identifiant et de 20 par IP sur 15 min, message identique que le compte existe ou non, hash factice pour un temps de réponse constant, journalisation des échecs. |
| E2 | `includes/auth.php` | Pas de nouvel identifiant de session à la connexion ; cookie sans HttpOnly ni SameSite ; session sans expiration. | Fixation et vol de session. | `session_regenerate_id`, mode strict, cookie HttpOnly + SameSite=Lax (+ Secure et préfixe `__Host-` en HTTPS), expiration après 8 h d'inactivité ou 24 h au total. |
| E3 | `admin/users.php` | Un organisateur non-admin pouvant gérer les utilisateurs pouvait changer son propre rôle, ou attribuer un rôle plus puissant que le sien. | Escalade de privilèges jusqu'aux droits maximum. | `roleSubordonne()` : on n'attribue ou ne gère que des rôles dont les permissions sont incluses dans les siennes ; on ne change pas son propre rôle (sauf admin). Testé. |
| E4 | `arbitrage/saisie.php` | L'ancien formulaire de vote par manche restait actif en POST et ajoutait des points au score en contournant les boutons +1/+2/+3. | Un arbitre pouvait fausser le score officiel. | Supprimé : les points passent uniquement par `api/points.php` (arbitre affecté, combat en cours, valeurs 1 à 3, CSRF, limite de débit). |
| E5 | PHP (pas de `php.ini`) | `display_errors` actif : messages et chemins internes affichés ; un message d'ajout de sabre renvoyait l'erreur SQL brute. | Fuite d'informations internes. | `php.ini-production` + réglages dédiés, gestionnaire d'exceptions global (page ou JSON générique avec référence, détail uniquement dans le journal). |
| E6 | `register.php` | L'inscription plantait (mauvais appel de `registerUser`), sans limite de débit. | Erreur 500 exposée, création massive de comptes possible. | Page réécrite : rôle « joueur » imposé côté serveur, confirmation du mot de passe, 5 inscriptions par heure et par IP, fermeture possible dans les paramètres (`inscriptions_ouvertes`). |
| E7 | `api/ble_sabre_status.php` | Adresses MAC et activité des sabres accessibles sans connexion. | Repérage et perturbation des sabres BLE. | Réservé à la permission `sabres.voir`. |

### Moyen

| # | Fichier | Problème | Correction |
|---|---------|----------|------------|
| M1 | Toutes les pages | Aucun en-tête de sécurité. | CSP stricte avec nonce par requête (scripts), `frame-ancestors 'none'`, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP/CORP, HSTS en HTTPS. Gestionnaires `onclick` inline retirés. |
| M2 | `admin/users.php`, `joueurs.php`, `parametres.php`, `combats.php` | Suppressions et export par lien GET avec le jeton CSRF dans l'URL (fuite via l'historique et les journaux). | Toutes les actions passent en POST + CSRF. |
| M3 | `admin/joueurs.php` | Import CSV sans limite de taille ni vérification du contenu. | 1 Mo et 2000 lignes au maximum, `is_uploaded_file`, type réel vérifié (finfo), champs nettoyés et bornés. |
| M4 | `includes/functions.php` | Export CSV vulnérable à l'injection de formules (`=cmd…`). | Les cellules qui commencent par `= + - @` sont neutralisées. |
| M5 | Mots de passe | 6 caractères minimum, mot de passe fixé par l'admin sans changement imposé, pas de page « Mon compte ». | 8 caractères minimum, liste des mots de passe courants refusés, changement obligatoire après création ou réinitialisation par un tiers, page `compte.php`, révocation des autres sessions (`session_version`). |
| M6 | API publiques | Aucune limite (polling libre). | 600 requêtes/min par IP pour les API publiques, 240 appuis/min par arbitre. |
| M7 | `admin/parametres.php` | Intervalle de rafraîchissement réglable à 0 ms ; clé interne `schema_tournoi` modifiable. | Bornes sur chaque valeur, clés internes ignorées. |
| M8 | Formulaires admin | Entrées non bornées (longueurs, dates, identifiants, couleurs). | Helpers `texteBorne`, `idPositif`, `couleurHex` ; dates au format strict ; joueurs et arbitres vérifiés en base. |
| M9 | `admin/users.php` | Changer le rôle ou désactiver un compte ne fermait pas ses sessions ouvertes. | Révocation par `session_version` ; le rôle est revérifié en base à chaque requête. |

### Faible

| # | Fichier | Problème | Correction |
|---|---------|----------|------------|
| F1 | `docker-compose.yml` | Mots de passe par défaut `rootpass` / `arbitrage_pass` utilisés si `.env` est incomplet. | Variables obligatoires (`${VAR:?}`). Votre `.env` contient encore ces valeurs par défaut : voir « À faire ». |
| F2 | `Dockerfile` | Code modifiable par l'utilisateur Apache ; volume en écriture. | Code en lecture seule (`:ro`, appartenant à root), `no-new-privileges`, fonctions shell désactivées, `allow_url_fopen` coupé. |
| F3 | Apache | Version exposée, TRACE autorisé, méthodes non utilisées acceptées. | `ServerTokens Prod`, `TraceEnable Off`, seules GET, POST et HEAD sont acceptées, corps limité à 4 Mo. |
| F4 | `combat.php` | Lien « Retour » construit depuis le Referer (redirection vers un site externe possible). | Seulement un chemin `.php` du même site. |
| F5 | IP client | Aucune notion de proxy de confiance. | `CF-Connecting-IP` / `X-Forwarded-*` ne sont crus que depuis le réseau Docker (`TRUSTED_PROXIES`). |
| F6 | `sabres.php` | Supprimer un sabre avec des touches provoquait une erreur SQL. | Il est désactivé à la place. |

### Vérifiés sans problème

- **Injection SQL** : toutes les requêtes utilisent des requêtes préparées PDO. Les seules concaténations portent sur des entiers castés ou des listes de `?`.
- **XSS** : toutes les sorties passent par `e()` (`htmlspecialchars`), et le JavaScript échappe avant chaque `innerHTML`. Testé avec des charges `<script>` et `onerror`.
- **CORS** : aucun en-tête CORS, donc même origine seulement. C'est le bon choix, rien ne nécessite d'appels cross-origin.
- **Stockage local** : aucun jeton ni secret dans `localStorage` ou dans le JavaScript.
- **Dépendances** : PHP 8.2 (support de sécurité jusqu'à fin 2026, prévoir la 8.3), MariaDB 11, Python 3.11, bleak 0.22.3, PyMySQL 1.1.1 (corrige CVE-2024-36039). Pas de dépendance inutile. `cloudflared:latest` n'est pas figé : figer une version si le tunnel est utilisé.

## À faire de votre côté (non automatisable)

1. **Changer les mots de passe de la base** (votre `.env` utilise encore `rootpass` et `arbitrage_pass`). Sur le Pi :
   ```bash
   docker exec -it sabre_db mariadb -uroot -p'ANCIEN_ROOT' -e "
     ALTER USER 'root'@'%' IDENTIFIED BY 'NOUVEAU_ROOT';
     ALTER USER 'root'@'localhost' IDENTIFIED BY 'NOUVEAU_ROOT';
     ALTER USER 'arbitrage_user'@'%' IDENTIFIED BY 'NOUVEAU_APP';"
   ```
   Puis mettez les mêmes valeurs dans `.env` et relancez `docker compose up -d`.
2. **Changer le mot de passe `Super`** partout où il servait, puisqu'il était écrit dans le code.
3. **Supprimer les dossiers `_backup_*`** du projet quand vous n'en avez plus besoin.
4. **Tunnel internet** : ne l'activez (`--profile tunnel`) que lorsque c'est nécessaire.
5. **Moindre privilège de la base (optionnel)** : l'utilisateur de l'application a tous les droits sur sa base, car il crée lui-même ses tables aux mises à jour. Après la première mise à jour, on peut lui retirer `DROP` et `GRANT OPTION`.
6. **Daemon BLE** : il tourne en mode `privileged` avec le réseau de l'hôte, ce qu'exige Bluetooth sous Docker. Ne l'activez (`--profile ble`) que sur le Pi dédié.

## Tests

`tests/securite_test.py` exécute 65 contrôles défensifs sur une base de **test** : en-têtes,
cookies, fixation de session, accès anonyme, mauvais rôle, IDOR, CSRF, entrées invalides,
XSS, escalade de privilèges, révocation de sessions, force brute, limite de débit.
Résultat : 65/65. Les parcours de toutes les pages ont aussi été testés dans un navigateur : aucune
erreur JavaScript ni violation CSP, et aucun débordement de 320 px à la 4K.
