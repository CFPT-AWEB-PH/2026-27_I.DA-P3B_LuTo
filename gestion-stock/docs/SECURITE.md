# Sécurité

Audit complet du 2026-11-05 : modèle de menace, règles à respecter et risques restants. Le détail des corrections est dans `docs/JOURNAL.md`.

## Modèle de menace

**À protéger**
- Comptes et droits : rôle administrateur, sessions, empreintes de mots de passe.
- Données personnelles, dont celles de mineurs : noms, classes, emprunts, adresses IP du journal et des sessions.
- Fiabilité du stock et de l'historique.
- Disponibilité sur un Raspberry Pi 4 (2 Go, 8 processus PHP).

**Qui agit**
- Personne du réseau de l'école, sans compte.
- Élève curieux ou malveillant, connecté, sur un poste parfois partagé.
- Enseignant, gestionnaire de stock.
- Gestionnaire de comptes ou de rôles non administrateur (rôle personnalisé).
- Administrateur.
- Accès SSH au Pi, hors du périmètre de l'application : il a tout pouvoir.

**Frontières de confiance**
- Navigateur → Caddy : tout ce qui arrive est non fiable.
- Caddy → PHP : seul `index.php` s'exécute ; `REMOTE_ADDR` vient de la connexion, aucun en-tête `X-Forwarded-*` n'est cru.
- PHP → MariaDB : requêtes préparées uniquement.
- Fichier Excel → navigateur → API : le fichier est lu dans le navigateur ; le serveur revalide chaque champ envoyé et ne reçoit jamais le fichier.

**Surfaces exposées** : ports 80 et 443 de Caddy, 4 routes publiques (`/api/health`, `/api/auth/config`, `/api/auth/link`, `/api/auth/login`), les autres routes avec session, l'envoi de photos.

**Détournements envisagés**

| Détournement | Protection |
|---|---|
| Essais de mots de passe en série | 10 échecs par compte et 30 par adresse en 10 min ; variantes accentuées de l'identifiant refusées avant la base |
| Gestionnaire de comptes qui se donne le rôle administrateur, ou change le mot de passe d'un compte plus fort | `PrivilegeGuard` |
| Gestionnaire de rôles qui s'ajoute une permission | `PrivilegeGuard` |
| Rôle puissant désigné par défaut, donné à chaque nouveau compte | `assertSafeAsDefault` |
| Gestionnaire limité qui retire l'adresse d'un administrateur de la liste d'autorisation | `PrivilegeGuard` : le retrait est refusé |
| Élève qui lit les emprunts d'autrui ou rend à la place d'un autre | Filtre côté serveur (`history.view`, propriétaire) |
| Stock négatif ou doublé par requêtes simultanées | Verrou sur l'objet, mises à jour conditionnelles |
| Image piégée ou trop grande | Dimensions lues avant décodage, 24 Mpx au plus, réencodage WebP, nom choisi par le serveur, fichier jamais exécuté |
| Saturation du Pi | Quotas par compte sur les opérations coûteuses, délais de Caddy et de PHP-FPM |
| Page malveillante qui agit à la place d'un utilisateur connecté | Cookie `SameSite=Lax`, jeton CSRF, contrôle de l'origine, JSON obligatoire |
| Élève qui prend un objet non consommable en décochant « Je le rendrai » | **Non bloqué**, voir ci-dessous |

## Règles pour la suite

- **Refus par défaut** : toute route exige une session. Une route publique se déclare avec `public: true` dans `backend/routes.php`, et `security_test.php` échoue si elle n'est pas ajoutée à sa liste.
- **Permissions** : chaque contrôleur appelle `requirePermission()`. Le frontend ne fait que masquer des boutons.
- **Hiérarchie** : tout ce qui attribue ou touche un rôle passe par `PrivilegeGuard`.
- **Affichage** : le DOM se construit avec `h()` (`dom.js`), jamais `innerHTML`. Un lien n'est jamais construit à partir d'une URL saisie par un utilisateur.
- **SQL** : requêtes préparées. Seuls des noms de colonnes figés dans le code et des entiers peuvent être concaténés.
- **Regex de validation** : modificateur `D`, sinon `$` accepte un saut de ligne final.
- **Connexion Google** : le jour où elle arrive, ajouter le réseau `web` au service `php` et `https://accounts.google.com` à `form-action` dans le `Caddyfile`.

## Risques restants

| Gravité | Risque | Action |
|---|---|---|
| 🟠 Élevé | **Site en HTTP** : mots de passe et cookies de session circulent en clair sur le réseau de l'école. Report décidé par l'utilisateur. | Passer en HTTPS (`ARCHITECTURE.md` §6). `SITE_ADDRESS` suffit : HTTPS, redirection, HSTS et cookie `Secure` s'activent seuls. |
| 🟡 Moyen | Sessions de 30 jours (7 sans activité) sur des postes parfois partagés : la personne suivante peut retrouver la session ouverte. | Décision à prendre : `SESSION_LIFETIME_DAYS`, `SESSION_IDLE_DAYS`. |
| 🟡 Moyen | Le gestionnaire voit les mots de passe et une personne ne peut pas changer le sien (choix de conception) ; fiches imprimées. | Décision à prendre : changement obligatoire à la première connexion. |
| 🟡 Moyen | N'importe qui ayant `stock.take` peut déclarer un objet non consommable « non rendu ». L'acte reste au journal. | Décision à prendre : le réserver à `stock.adjust`. |
| 🟡 Moyen | L'application et les migrations partagent un utilisateur MariaDB avec tous les droits sur la base ; les sauvegardes utilisent root. | Séparer les comptes (à tester lors d'un déploiement réel). |
| 🟡 Moyen | Sauvegardes non chiffrées sur la carte SD (empreintes, noms d'élèves) ; le dossier `shared/backups` doit rester en droits 700. | Chiffrer les copies sorties du Pi. |
| 🟢 Faible | `audit_log` sans durée de conservation (adresses IP, identifiants). | Définir une durée conforme aux règles de l'école, puis purger. |
| 🟢 Faible | Verrouillage temporaire : 10 échecs bloquent un compte 10 min au plus, ce qu'un camarade peut provoquer exprès. Compromis assumé. | Surveiller `auth.denied`. |
| 🟢 Faible | Images Docker par version, pas par empreinte ; aucune analyse de CVE automatisée. Three.js 0.170 ne charge aucun modèle externe. | `docker compose build --pull` régulier, analyse d'images. |
