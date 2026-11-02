# Journal des phases

## Phase 1 : audit et préparation (2026-11-02)

**Poste de développement (Windows 11)** : Git 2.55, Node 24, Docker 29.7 et Compose 5.4 installés (Docker Desktop arrêté) ; PHP et Composer absents (on les utilisera via Docker) ; une clé SSH ed25519 est présente.

**Raspberry Pi 10.5.35.39** : connexion SSH par clé opérationnelle. Pi 4 avec 2 Go, Debian 13 arm64, 21 Go libres sur la carte SD, Internet OK. Docker, PHP et MariaDB absents ; aucune donnée à préserver (dossier personnel vierge, aucun service à part SSH). `sudo` demande un mot de passe. Le dépôt Docker pour Debian trixie arm64 est disponible.

**Fait** : structure du projet, document d'architecture (mis à jour avec le matériel réel), `.env.example`, `.gitignore`. Git reste **local uniquement** (aucun dépôt distant, à votre demande).

**Bloquant pour la phase 2** : installation de Docker (nécessite `sudo`), choix du nom de domaine et du mode HTTPS.

## Phase 2 : socle technique (2026-11-02)

**Installé** : Docker 29.9.2 et Compose 5.6.1 sur le Pi (installation lancée par l'utilisateur, `cuto` membre du groupe `docker`).

**Fait**
- `docker-compose.yml` : Caddy 2.10, PHP 8.3 FPM, MariaDB 11.4 et un service de sauvegarde, tous avec contrôle de santé ; réseau `db` interne (sans accès extérieur), rotation des journaux ; volumes nommés persistants.
- API PHP : routeur, PDO strict en UTC, erreurs JSON sans fuite d'informations en production, `GET /api/health`.
- Migrations SQL versionnées avec empreinte et verrou (`bin/migrate.php`) ; `0001_base` (paramètres, journal d'audit).
- Sauvegardes quotidiennes à 02:30 avec rotation, sauvegarde automatique avant chaque déploiement, restauration avec sauvegarde de sécurité, test de restauration non destructif.
- Déploiement par version (`scripts/deploy.sh`), bascule uniquement si tout est sain, retour arrière (`scripts/rollback.sh`).

**Tests réalisés sur le Pi (tous réussis)**

| Test | Résultat |
|---|---|
| Syntaxe PHP 8.3 (`php -l`, 12 fichiers) | aucune erreur |
| Syntaxe des scripts bash (`bash -n`) | OK |
| 4 conteneurs « healthy » après déploiement | OK |
| `/api/health` depuis le LAN (poste Windows) | 200, base `ok`, 0 migration en attente |
| Route inconnue / méthode interdite | 404 / 405 en JSON |
| En-têtes (CSP, X-Frame-Options, no-store sur l'API) | présents |
| Port 3306 depuis le LAN | fermé |
| Migration relancée (idempotence) | « Base à jour » |
| Sauvegarde manuelle + test de restauration | 3 tables, nombres de lignes identiques |
| `docker compose down` puis `up` : donnée témoin conservée | OK |
| `docker compose restart` manuel : version inchangée | OK, après correction |
| Retour arrière puis retour à la nouvelle version | OK, données conservées |

**Problème trouvé et corrigé** : un `docker compose up` lancé à la main reconstruisait une image « dev ». Les images validées sont désormais étiquetées `:current` (commit 63420a0).

**Remarques**
- Avertissements MariaDB `io_uring` (désactivé par le noyau du Pi) : sans effet, MariaDB utilise une autre méthode d'entrées-sorties.
- `docker stats` affiche 0 o de mémoire : le contrôleur mémoire cgroup est désactivé dans le noyau du Pi (option `cgroup_enable=memory` absente). Sans conséquence pour l'instant ; à activer avec sudo si l'on veut limiter la mémoire par conteneur.
- La ligne témoin `test.persistence` est conservée dans `audit_log`.
- Le site est en **HTTP** sur http://10.5.35.39 en attendant le choix HTTPS.

**Prochaine étape** : phase 3 (authentification Google, liste d'autorisation, rôles et permissions). Nécessite le choix HTTPS et un identifiant OAuth Google.
