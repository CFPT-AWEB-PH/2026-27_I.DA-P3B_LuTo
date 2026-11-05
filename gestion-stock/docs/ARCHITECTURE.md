# Architecture technique

Statut : **Phase 1, validée sur le matériel réel** (sauf le choix HTTPS, §6).

Cible vérifiée le 2026-11-02 : Raspberry Pi 4 Model B (rév. 1.5), 4 cœurs, 1,8 Go de RAM et 1,8 Go de swap zram, Debian 13 « trixie » en 64 bits (aarch64, noyau 6.18), carte SD de 28 Go (21 Go libres), Ethernet en DHCP sur 10.5.35.0/26 (10.5.35.38 depuis le 2026-11-05), heure synchronisée par NTP (Europe/Zurich), accès Internet vers Google : OK. Aucun service web, base de données ni conteneur présent : pas de données à préserver.

## 1. Vue d'ensemble

```
 Navigateur / PWA (HTML, CSS, JS, Three.js)
            │  HTTPS (obligatoire : PWA, service worker, Google OAuth)
            ▼
 ┌──────────────── Raspberry Pi : Docker Compose ─────────────────┐
 │  caddy (reverse proxy + TLS + fichiers statiques du frontend)  │
 │     │ FastCGI (réseau privé "backend")                         │
 │  php (PHP 8.3 FPM : API REST JSON, sessions, OAuth)            │
 │     │ TCP 3306 (réseau privé, jamais exposé au LAN)            │
 │  mariadb (MariaDB 11 LTS, volume persistant)                   │
 │  backup (mariadb-dump planifié + rotation, volume dédié)       │
 └────────────────────────────────────────────────────────────────┘
```

Seul `caddy` publie des ports (80/443). MariaDB et PHP ne sont joignables que depuis le réseau Docker privé.

## 2. Choix techniques

| Domaine | Choix | Raison |
|---|---|---|
| Frontend | HTML/CSS/JS natif, modules ES, sans framework ni étape de build | Imposé ; aucune chaîne de build à maintenir sur le Pi |
| 3D | Three.js (fichiers servis localement depuis `frontend/vendor/`, version figée) | Léger, mature ; aucune dépendance à un CDN sur le LAN |
| Backend | PHP 8.3 FPM, petit routeur maison, PDO, sans framework lourd | Imposé ; peu de mémoire ; code lisible |
| Dépendances PHP | Composer, limité au strict nécessaire (vérification des JWT Google via `firebase/php-jwt`) | Ne pas réimplémenter la cryptographie |
| Base | MariaDB 11.4 LTS, InnoDB, utf8mb4, index FULLTEXT pour la recherche | Imposé ; transactions pour le stock |
| Proxy | Caddy 2 | TLS automatique ou CA interne, configuration courte, image arm64 officielle |
| Sauvegardes | Conteneur `backup` : `mariadb-dump` quotidien, compressé, rotation 14 j + 8 hebdomadaires | Restauration testable par script |
| Migrations | Fichiers SQL numérotés `database/migrations/NNNN_nom.sql`, table `schema_migrations`, exécutés par `php bin/migrate` | Simple, traçable, idempotent |

## 3. Repère spatial (point de référence : la porte)

- Unité interne de stockage : **millimètre entier** (aucune erreur d'arrondi flottant en base). L'interface convertit vers l'unité choisie (mm, cm, m).
- Origine `O` : le **coin intérieur gauche du mur de la porte, au sol**, vu depuis l'intérieur de la salle en regardant la porte.
  - Axe **X** : le long du mur de la porte, vers la droite (vu de l'intérieur, dos à la porte, vers la gauche).
  - Axe **Y** : vertical, vers le haut.
  - Axe **Z** : perpendiculaire au mur de la porte, vers l'intérieur de la salle.
- **Contour** : la salle est un polygone `[[x, z], …]` (rectangle par défaut, forme libre possible, ex. en L). Le sommet 0 est l'origine, le segment 0 (sommet 0 → sommet 1, sur l'axe X) est le **mur de la porte**. Chaque segment est un mur, numéroté à partir de 1 dans l'interface. L'épaisseur des murs est ajoutée côté extérieur, donc les cotes saisies sont intérieures.
- **Porte de référence** : `door_offset_mm` (distance de l'origine au bord de la porte), largeur et hauteur, toujours sur le mur 1. Les autres ouvertures (fenêtres, portes, passages) se placent sur n'importe quel mur, par distance depuis le début du mur.
- **Meuble** (rangement sans parent) : position = son **coin arrière gauche bas** dans le repère de la salle. La rotation (degrés) se fait autour de l'axe vertical passant par ce coin, dans le même sens que `rotation.y` de Three.js. Dans l'interface, les boutons ±90° tournent autour du centre, en recalculant le coin.
- **Rangement imbriqué** : position = son coin arrière gauche bas, **relatif au coin arrière gauche bas du volume intérieur du parent**. Il est toujours aligné sur son parent. Déplacer un meuble déplace donc tout son contenu.
- **Volume intérieur** selon la forme du type (épaisseur des parois `p`) :
  - façade ouverte (armoire, compartiment, case) : L−2p × P−p × H−2p ;
  - dessus ouvert (tiroir, boîte, bac) : L−2p × P−2p × H−p ;
  - niveau d'étagère : planche en bas, L × P × H−p ;
  - séparateur : aucun volume.
- **Règles vérifiées par le serveur** :
  - meubles entièrement dans la salle, rotation comprise (polygones, théorème de l'axe séparateur) ;
  - aucun chevauchement entre meubles, sauf s'ils sont superposés en hauteur ;
  - sous-rangements dans le volume intérieur de leur parent, sans chevauchement ;
  - une modification de la salle ou d'un parent est refusée si elle invaliderait ce qui existe.
- Les mêmes fonctions existent en JavaScript (`frontend/assets/js/spatial.js`) pour le pré-contrôle visuel. Le serveur reste seul juge.

## 4. Modèle de données (aperçu)

- `users`, `roles`, `permissions`, `role_permissions`, `allowlist` (adresses et domaines autorisés), `sessions`, `audit_log`
- `rooms`, `room_walls`, `room_openings` (porte de référence et autres ouvertures)
- `users` : e-mail (connexion par lien ou Google) ou `username` + `password_hash` (comptes importés, mot de passe géré par `users.manage`, jamais conservé en clair)
- `floors` (niveau, hauteur) ; chaque salle porte `floor_id`, `plan_x_mm`, `plan_z_mm`, `plan_rotation_deg` : sa place sur le plan de l'étage (`NULL` : pas encore placée, le plan la range à la suite sans rien enregistrer)
- `storage_templates` : un rangement enregistré avec ses tiroirs et ses bacs (arbre JSON), sans le stock
- `storage_types` (armoire, tiroir, étagère, boîte, bac, case, types personnalisés)
- `storage_units` : arbre (`parent_id`, `room_id`), dimensions, position relative, rotation, couleur, étiquette, `path_cache` pour la recherche
- `categories`, `custom_fields`, `item_custom_values`
- `items` (référence unique, unité, quantité minimale, photo)
- `stock_levels` (objet × emplacement → quantité) : un même objet peut être réparti à plusieurs endroits
- `stock_movements` (prise, retour, ajout, correction, déplacement ; utilisateur, horodatage, emplacements d'origine et de destination, commentaire)
- `loans` (prise ouverte, quantité restant à rendre, statut)

Opérations de stock : transaction InnoDB + `SELECT … FOR UPDATE` sur la ligne `stock_levels`, contrainte `CHECK (quantity >= 0)`. Une prise supérieure au stock échoue côté serveur, quoi qu'envoie le client.

## 5. Sécurité

- Google OpenID Connect, flux « authorization code » côté serveur avec `state`, `nonce` et PKCE ; validation de l'`id_token` (signature JWKS, `iss`, `aud`, `exp`, `email_verified`).
- Accès refusé si l'e-mail ne figure pas dans la liste d'autorisation (adresse exacte ou domaine explicitement autorisé). Aucun domaine n'est autorisé par défaut.
- Premier administrateur : désigné par la variable `BOOTSTRAP_ADMIN_EMAIL` dans `.env`, prise en compte une seule fois à sa première connexion, puis ignorée.
- Rôle par défaut des nouveaux utilisateurs : « Élève ». Le dernier administrateur actif ne peut être ni rétrogradé ni désactivé.
- Cookies de session `Secure`, `HttpOnly`, `SameSite=Lax` ; jeton CSRF (en-tête `X-CSRF-Token`) sur toute requête qui modifie des données.
- PDO avec requêtes préparées partout ; échappement à l'affichage côté client (`textContent`, jamais `innerHTML` avec des données).
- Permissions vérifiées dans chaque contrôleur via `require_permission('stock.take')` ; le frontend ne fait que masquer des boutons.
- Secrets uniquement dans `.env` (non versionné, `chmod 600` sur le Pi). `.env.example` contient des valeurs vides.
- Limite de taille des requêtes (1 Mo pour le JSON, 5 Mo pour les photos, types MIME vérifiés, ré-encodage des images), limitation du nombre de requêtes sur les points d'authentification.

## 6. HTTPS, PWA et Google sur un réseau local : contraintes

- **Service worker et installation PWA** : exigent un contexte sécurisé (HTTPS), sauf sur `localhost`. En HTTP sur l'adresse du Pi, la PWA n'est **pas installable** et le service worker ne démarre pas ; l'application fonctionne normalement sans eux. Tout est prêt et testé via un tunnel SSH vers `localhost` : il suffira de servir le site en HTTPS.
- **Google OAuth** : Google refuse les URI de redirection sur une adresse IP privée en HTTP. Il faut un **nom de domaine public** (le DNS peut pointer vers l'IP locale) en **HTTPS**.
- **Accès Internet** : la connexion Google exige que le navigateur et le Pi joignent Google (le Pi récupère les clés JWKS et échange le code). Sans Internet, aucune nouvelle connexion n'est possible ; les sessions déjà ouvertes restent valides jusqu'à leur expiration. Les erreurs sont affichées clairement.

Options (décision en attente) :

1. **Recommandée** : un sous-domaine que vous contrôlez (ex. `stock.mondomaine.ch`), avec un enregistrement DNS A vers l'adresse du Pi (à figer d'abord, voir §8) et un certificat Let's Encrypt obtenu par challenge DNS-01 (Caddy + API du fournisseur DNS). Certificat reconnu par tous les appareils, sans rien installer.
2. Un nom de type `10-5-35-38.sslip.io` avec la CA interne de Caddy : il faut installer le certificat racine sur **chaque** appareil, ce qui est pénible sur les smartphones et souvent impossible sur des appareils gérés.
3. HTTP seul : fonctionne pour la consultation, mais **sans** PWA ni connexion Google. Non conforme au cahier des charges.

## 7. Hors ligne

`frontend/sw.js` :

- **Coque préchargée** (HTML, CSS, JS, Three.js, icônes), liste générée à la construction de l'image Caddy. Toutes les pages sont servies depuis ce cache : l'application démarre sans réseau et ses fichiers restent de la même version.
- **API jamais interceptée ni stockée** : aucune donnée de compte ou de stock ne reste sur l'appareil, et il n'y a donc rien à purger à la déconnexion. Sans serveur, l'application affiche « Serveur injoignable » : pas de consultation hors ligne.
- **Écriture** : **aucune** opération n'est simulée ni mise en attente. Une requête échoue franchement et le message indique que rien n'a été enregistré. Une file d'attente avec synchronisation et gestion des conflits reste possible si le besoin est confirmé.
- **Mises à jour** : chaque déploiement change la version dans `sw.js`. La nouvelle version se télécharge en arrière-plan, puis une notification propose « Mettre à jour » : elle n'est jamais imposée, pour ne pas couper un formulaire en cours.
- Le bandeau « Serveur injoignable » s'appuie aussi sur l'échec réel des requêtes, car `navigator.onLine` reste vrai quand le Wi-Fi fonctionne mais que le serveur ne répond pas.

## 8. Risques identifiés

| Risque | Impact | Mesure |
|---|---|---|
| Architecture CPU | Vérifiée : aarch64 / arm64, donc images officielles PHP, MariaDB et Caddy disponibles | Aucune |
| 2 Go de RAM, dont environ 350 Mo pris par le bureau graphique | Marge limitée | `innodb_buffer_pool_size=256M`, PHP-FPM `ondemand` ; désactiver le bureau graphique (`multi-user.target`) si vous êtes d'accord |
| IP obtenue par DHCP (**déjà changée** : 10.5.35.39 puis 10.5.35.38 le 2026-11-05) | Site et liens de connexion inaccessibles, et plus tard DNS et OAuth cassés | Réservation DHCP auprès de l'administrateur réseau ou IP fixe. En attendant : `scripts/deploy.sh` et `APP_URL` à ajuster. Identifier le Pi par ses clés SSH (ssh refuse une autre machine) et **pas par `cuto.local`**, nom porté aussi par un autre Raspberry Pi du réseau |
| `sudo` demande un mot de passe | Je ne peux pas installer Docker moi-même | Installation de Docker lancée par vous, puis `cuto` ajouté au groupe `docker` |
| Carte SD (usure, corruption) | Perte de données | Sauvegardes quotidiennes, copie hors du Pi conseillée, SSD USB recommandé |
| HTTPS et domaine non disponibles | Ni PWA ni Google | Voir §6 |
| GPU limité sur les vieux téléphones et tablettes | 3D saccadée | Géométries simples, rendu à la demande uniquement, pixel ratio plafonné |
| Pas d'accès Internet sur le LAN | Connexion Google impossible | Message d'erreur explicite, sessions longues configurables |
