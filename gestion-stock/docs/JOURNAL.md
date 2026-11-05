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

## Phase 3 : authentification, rôles et permissions (2026-11-02)

**Décision** : la connexion Google est reportée à la fin, avec HTTPS (à votre demande). En attendant, la connexion se fait par lien à usage unique généré en SSH (`bin/login-link.php`). Tout le reste (comptes, sessions, rôles) est le système définitif ; Google viendra s'y brancher via `LoginService::login()`.

**Fait**
- Migration `0002_auth` : 15 permissions granulaires ; rôles Administrateur (protégé), Gestionnaire de stock, Enseignant, Élève (par défaut) ; utilisateurs, liste d'autorisation, sessions, jetons de connexion, limitation de débit.
- Sessions en base (le cookie contient un jeton aléatoire, la base son empreinte SHA-256) ; cookie HttpOnly, SameSite=Lax, Secure dès que le site passe en HTTPS ; expiration de 30 jours et de 7 jours sans activité ; révocation immédiate à la désactivation.
- CSRF : jeton de session dans l'en-tête `X-CSRF-Token` et vérification de l'en-tête `Origin`.
- Permissions vérifiées dans chaque contrôleur côté serveur ; rôle protégé non supprimable et toujours complet ; impossible de supprimer le dernier administrateur actif (verrou + contrôle dans la transaction), de se désactiver soi-même, ou de retirer sa propre adresse de la liste.
- Validation stricte des entrées (types, longueurs, caractères de contrôle) ; journal d'audit des connexions, refus et modifications de droits.
- Interface : connexion, accueil, utilisateurs (recherche et filtres, changement de rôle, désactivation), rôles et permissions, liste d'autorisation ; menu adapté au mobile.
- Administrateur initial : `tom.cnnng@eduge.ch` (créé par `bin/create-admin.php`, une seconde exécution est refusée).

**Tests** (`scripts/run-tests.sh`, scénario E) : **57 vérifications, 0 échec**. Ils couvrent l'accès anonyme, les liens inventés ou réutilisés, les adresses non autorisées, l'absence de jeton CSRF et une origine étrangère, ainsi que toutes les actions d'administration tentées par un Élève directement en HTTP (toutes refusées, rôle inchangé en base). Ils couvrent aussi le cycle de vie d'un rôle, l'application immédiate des permissions, la protection du dernier administrateur, la désactivation (session révoquée, connexion refusée), la déconnexion et le journal d'audit.

Vérifié dans le navigateur : page de connexion, messages d'erreur, redirection des pages protégées vers `/login`.

**Non vérifié** : l'affichage des pages d'administration une fois connecté. Je ne me suis pas connecté moi-même dans le navigateur ; à tester par l'utilisateur avec un lien de connexion.

**Reporté à la fin** : HTTPS et connexion Google (OpenID Connect).

## Phase 4 : salles en 3D, armoires et rangements (2026-11-02)

**Fait**
- Migration `0003_spaces` : salles (contour polygonal, porte de référence, apparence), ouvertures, 8 types de rangement fournis (armoire, étagère, tiroir, compartiment, boîte, bac, séparateur, case) et types personnalisés, rangements imbriqués sans limite de profondeur, chemin complet tenu à jour (« Salle › Armoire › Tiroir › Boîte › Case ») pour la recherche de la phase 5.
- API : salles (création, modification, duplication, suppression), ouvertures, rangements (création, modification, déplacement vers un autre parent, suppression en cascade), génération d'une grille intérieure (étagères, compartiments, cases), types de rangement. Permissions : `rooms.view`, `rooms.edit`, `furniture.edit` (meubles), `storage.edit` (rangements internes et types).
- Validation spatiale côté serveur (voir ARCHITECTURE §3) et verrou de salle avec isolation READ COMMITTED : deux écritures simultanées ne peuvent pas créer de chevauchement.
- Éditeur 3D (Three.js 0.170.0 local, rendu à la demande) :
  - murs découpés autour des ouvertures, fenêtres vitrées, murs côté caméra masqués automatiquement (ou transparents, masqués, pleins) ;
  - porte de référence et origine signalées ; revêtements de sol générés ;
  - sélection au clic ou dans l'arborescence, cotes de la salle et du rangement sélectionné, masquage temporaire ;
  - tiroirs qui s'ouvrent (double-clic) ; vues 3D, dessus et depuis la porte ;
  - déplacement des meubles à la souris (pas de 1 cm, ou 10 cm avec Maj ; contour rouge si la position est impossible) ;
  - ajustement fin par boutons ou clavier, rotation de ±90°, emplacement libre proposé à la création ;
  - mise en évidence par l'adresse `?unit=` : ouverture des parents et cadrage.
- Fenêtres : paramètres de la salle (rectangle ou contour libre avec aperçu du plan et du repère), ouvertures, meuble ou rangement (dimensions et position dans l'unité de la salle), génération d'intérieur. Page « Types de rangement ».

**Tests**
- `spaces_test.php` : **80 vérifications, 0 échec**. Il couvre :
  - scénario A : contours invalides refusés, porte et ouvertures sur leurs murs, armoire à l'échelle, rotation de 90°, persistance après relecture ;
  - scénario B : 4 étagères générées, tiroirs, boîte, 3 cases, soit 5 niveaux d'imbrication avec positions et chemins conservés ;
  - débordements et chevauchements refusés, cohérence après redimensionnement de la salle ou d'une armoire, références uniques, renommage propagé, duplication ;
  - deux créations simultanées au même endroit : une acceptée, une refusée ;
  - toutes les écritures refusées pour un Élève (403).
- `auth_test.php` : 57 vérifications, 0 échec (non-régression).
- Interface : vérifiée dans un banc de test **local** (serveur Node sur 127.0.0.1 servant `frontend/`, fausse API en mémoire, aucun identifiant). Points vérifiés :
  - rendu de la salle en L, cotes, mise en évidence d'une case au 5e niveau (tiroir ouvert automatiquement) ;
  - glisser-déposer d'une armoire, création d'un meuble avec emplacement libre ;
  - fenêtre de la salle, mobile 375 px sans défilement horizontal.
- Corrigés pendant ces tests : « 0 » parasite dans l'arborescence, cadrage faux avant le premier rendu (matrices non à jour), champs masqués encore visibles, débordement horizontal sur mobile.

**Limites connues**
- Les salles ont un plafond horizontal unique (pas de sous-pente).
- Le déplacement à la souris concerne les meubles. Les rangements internes se placent par formulaire, boutons ou clavier.
- Le contenu en objets des rangements, leur taux de remplissage et la légende des couleurs arrivent avec la phase 5 (stock).
- Le menu contextuel (clic droit) est prévu en phase 6 : pour l'instant, le clic droit dans la vue 3D déplace la caméra.
- L'éditeur réel (avec authentification) n'a pas été ouvert dans un navigateur par moi : le serveur a été testé par l'API, l'interface par le banc local.

## Phase 5 : stock, recherche, emprunts et historique (2026-11-02)

**Fait**
- Migration `0004_stock` : catégories, champs personnalisés (texte, nombre, date, oui/non, liste ; globaux ou par catégorie, obligatoires ou non), objets (référence unique, unité, stock minimal, consommable, photo), stock par emplacement (`CHECK quantity >= 0`), emprunts, journal des mouvements immuable (chemins d'origine et de destination copiés), capacité des rangements, couleurs de remplissage paramétrables.
- Opérations de stock : prise (emprunt à rendre, ou consommation pour les consommables), retour partiel ou total vers l'origine ou un autre rangement, réapprovisionnement, correction (motif obligatoire, avant et après), déplacement. Chacune est **atomique** : verrou de la ligne de l'objet, mise à jour conditionnelle (`quantity >= ?`), journalisation dans la même transaction.
- Recherche côté serveur :
  - index FULLTEXT (préfixes, mots de 2 lettres, sans mots vides) sur un texte agrégé : nom, référence, description, catégorie, champs recherchables, chemin complet, étiquettes et références des rangements, étiquette de la salle ;
  - complétée par des LIKE sur le nom et la référence ;
  - pertinence : référence exacte, puis début de référence ou de nom, puis score plein texte ;
  - filtres par catégorie, salle, rangement (avec sous-rangements) et stock bas ; tri ; pagination ; suggestions ;
  - index mis à jour à chaque renommage de salle, de rangement ou de catégorie.
- Photos : décodées puis réencodées en WebP (1200 px maximum, 5 Mo et 40 mégapixels maximum à l'envoi), servies uniquement aux utilisateurs connectés.
- Protection des données : suppression refusée pour un rangement ou une salle qui contient encore du stock, et pour un objet en stock ou en prêt. Un objet qui a un historique est archivé, pas supprimé.
- Interface :
  - recherche globale dans la barre supérieure (suggestions, clavier, raccourcis / et Ctrl+K) ;
  - catalogue (filtres, tri, emplacements exacts, « Voir en 3D ») et fiche objet ;
  - fenêtres de prise, retour, ajout, correction et déplacement ;
  - pages emprunts et retours, historique filtrable (utilisateur, salle, type, période), catégories et champs, paramètres (nom, couleurs de remplissage) ;
  - dans l'éditeur 3D : objets du rangement sélectionné (prise directe, ajout de stock, nouvel objet sur place) et mode couleurs de remplissage avec légende (vide, partiel, plein si la capacité est atteinte).

**Tests**
- `stock_test.php` : **86 vérifications, 0 échec** :
  - scénario C : recherche par 16 critères (nom, préfixe, référence exacte ou partielle, description, catégorie, salle, étiquettes, armoire, tiroir, boîte, compartiment, champ personnalisé, sans accents, majuscules), quantité et emplacement exacts, lien vers la 3D ;
  - scénario D : prise de 4, refus au-delà du stock, retour partiel de 1,5, reste de 2,5 à rendre, retour du reste ailleurs, historique complet ;
  - corrections et déplacements tracés (qui, avant, après, anciens et nouveaux emplacements) ;
  - **20 prises simultanées sur un stock de 6** : exactement 6 acceptées et 14 refusées, stock à 0, jamais négatif ;
  - photos (fichier non image refusé, réencodage WebP) ;
  - **5000 objets** : recherches en 65 à 265 ms, requête HTTP complète incluse, sur le Pi.
- Non-régression : `auth_test` 58 et `spaces_test` 80 vérifications, 0 échec. Test de restauration réussi (22 tables).
- Interface vérifiée sur le banc local (fausse API) : catalogue, suggestions et navigation au clavier, fiche, prise refusée puis acceptée, couleurs de remplissage et contenu dans la 3D.

**Problèmes trouvés et corrigés**
- La limitation des tentatives de connexion comptait aussi les connexions réussies. Deux séries de tests en 10 minutes bloquaient alors des comptes légitimes. Elle ne compte plus que les **échecs** (test ajouté).
- Dans le test de volume, une variable écrasait le compteur de vérifications.

**Limites connues**
- L'orientation EXIF des photos de téléphone n'est pas appliquée (extension `exif` absente) : une photo peut apparaître tournée. Il suffit de la réenvoyer, ou d'ajouter l'extension plus tard.
- Pas d'export CSV de l'historique pour l'instant.
- Le mode hors ligne (file d'attente) n'est pas fait : prévu avec la PWA en phase 6. Aucune opération de stock n'est simulée hors ligne.
- Données réelles constatées : la salle « test » créée par l'utilisateur (conservée).

## Interface : deuxième passe de refonte (2026-11-03)

Audit écran par écran sur une copie locale complète (Docker, données réalistes), à 1440, 768 et 375 px, en clair et en sombre. Détail, design system et pistes : [DESIGN.md](DESIGN.md).

**Problèmes trouvés et corrigés**
- Catalogue : tous les objets affichés « Archivé » et bouton « Prendre » absent (la liste de l'API ne renvoie pas `is_active`). L'état découle maintenant du filtre de la requête.
- Historique : « null » écrit au-dessus des filtres (`Element.append`). Ajout d'un `append()` sûr dans `dom.js`, utilisé par toutes les pages.
- Contrastes WCAG AA : texte d'indication, contours des champs et boutons (3:1), texte sur le rouge du thème sombre.

**Améliorations**
- Accueil : une seule recherche, avec suggestions instantanées.
- Fiche objet : action principale mise en avant, actions rares dans un menu ; suppression expliquée avant d'être tentée si du stock reste.
- Menus d'actions unifiés (navigation aux flèches), focus en contour qui respecte la forme des composants.
- Historique : filtres repliés et cartes compactes sur mobile, « Effacer les filtres ».
- Changement de rôle et retrait d'un accès : immédiats, annulables depuis la notification.
- Jetons de graisse, d'interligne, d'espacement, de durée et de couleurs de calque ; plus de valeur en dur dans les composants.

**Vérifications**
- 17 routes parcourues sans erreur de console ni texte parasite ; navigation au clavier du menu ; annulation d'un retrait d'accès ; refus du serveur (dernier administrateur) correctement affiché et rétabli.
- Aucune modification de l'API ni des données. Les tests d'intégration du serveur n'ont pas été relancés (backend inchangé).

## Phase 6 : tableau de bord, menu contextuel, PWA et mobile (2026-11-05)

**Fait**
- Tableau de bord sur l'accueil (déjà refondu) : chiffres clés cliquables et derniers mouvements. `GET /api/dashboard` ne renvoie chaque bloc qu'avec la permission correspondante.
- Menu contextuel (`context-menu.js`) : clic droit, appui long au toucher, touche Menu ou Maj+F10.
  - Présent sur les salles, les objets, l'arborescence, les objets d'un rangement et la vue 3D (meuble, rangement ou vide de la salle).
  - Contenu adapté aux permissions, recadré dans l'écran, fermé par Échap, clic extérieur, défilement ou redimensionnement.
  - Le menu natif reste actif ailleurs, dans les champs de saisie, sur un texte sélectionné et avec Maj + clic droit.
  - Sur les cartes, le bouton « ⋯ » est l'alternative visible.
  - Dans la 3D, un clic droit glissé déplace la vue sans ouvrir de menu.
- Écarts assumés par rapport aux exemples du cahier des charges : sur une salle, « Modifier » et « Gérer les paramètres » ne font qu'une entrée (« Modifier les paramètres… »), car c'est le même formulaire ; « Gérer les ouvertures… » s'y ajoute. Sur un rangement, « Ajouter ou déplacer des objets » est en deux entrées.
- PWA : manifeste, icônes générées par `scripts/generate-icons.mjs`, service worker (voir ARCHITECTURE §7), proposition de mise à jour, installation depuis le menu du compte quand le navigateur la permet.
- Serveur injoignable : écran et bandeau clairs, reprise au retour du réseau, message « rien n'a été enregistré » sur une écriture échouée.

**Tests**
- `pwa_test.php` : 23 vérifications (tableau de bord selon le rôle, manifeste, icônes, service worker versionné, fichiers préchargés tous disponibles, en-têtes). Total serveur : **247 vérifications, 0 échec** (58 + 23 + 80 + 86).
- PWA dans un **vrai navigateur (Edge sans interface)**, sur le vrai serveur via un tunnel SSH vers `localhost`, sans compte : service worker activé, 39 fichiers préchargés, aucune réponse d'API en cache, installable selon le navigateur, écran « Serveur injoignable » et Three.js disponibles tunnel coupé, écriture en échec franc, retour à la normale, aucune erreur de console ni de CSP.
- Mise à jour testée avec un vrai déploiement pendant que la page était ouverte : nouvelle version proposée, ancien et nouveau caches côte à côte, ancien cache supprimé après accord. Total navigateur : 17 vérifications, 0 échec.
- Interface sur le banc local (fausse API) : vrais clics droits, permissions Élève et Administrateur, appui long simulé, 375 px sans débordement.

**Bugs trouvés et corrigés**
- **Éditeur 3D bloqué sur « Chargement… » pour tout compte sans `furniture.edit`, donc les élèves** (`false?.addEventListener` lève une erreur), présent depuis la phase 4. Les tests d'API ne pouvaient pas le voir.
- Menu « ⋯ » des fiches du catalogue déformé par `.item-actions *`.
- **Le Pi a changé d'adresse** (DHCP) : 10.5.35.39 est maintenant une autre machine et `cuto.local` désigne un autre Raspberry Pi. Pi identifié à 10.5.35.38 par ses empreintes SSH ; `APP_URL` et `scripts/deploy.sh` corrigés.

**Limites connues**
- En HTTP (situation actuelle), ni service worker ni installation : c'est imposé par les navigateurs, et l'étape HTTPS reste à faire.
- Le navigateur intégré à l'application Claude n'enregistre pas de service worker : ces tests passent par Edge.
- Appui long testé par événements simulés, pas sur un vrai téléphone. L'installation elle-même (fenêtre du navigateur) n'a pas été cliquée : seule l'installabilité est vérifiée.
- La connexion réelle sur le site n'a pas été parcourue par mes soins : à vérifier à la phase 7.

## Objets en 3D dans les rangements (2026-11-05)

Demande de l'utilisateur, photos de l'atelier à l'appui : voir l'armoire, ses tiroirs, ses bacs et **les objets dedans**. La phase 7 passe après.

**Fait**
- Migration `0005_item_visuals` : apparence 3D sur chaque objet (`visual`, `visual_color`) et couleurs par défaut d'atelier (armoire crème, tiroir marine, bac rouge), seulement si elles n'avaient pas été personnalisées.
- `three/item-models.js` : 14 modèles légers (vis, écrou, rondelle, résistance, condensateur, circuit, câble, tournevis, pince, clé, marteau, profilé, roue, pièce), dessinés en instances. Type déduit du nom ou choisi sur la fiche ; les cotes du nom sont utilisées (« Vis M3 x 12 »). Petites pièces en vrac, outils et profilés rangés côte à côte, à l'échelle.
- **Le nombre de pièces visibles est une représentation** : il suit la quantité jusqu'à un plafond (70 en vrac, 36 rangés, limité aussi par la place). La quantité exacte reste celle du panneau.
- Armoire : façades avec bandeau étiqueté, fond de tiroir perforé, étiquettes sur les bacs, ouverture quasi complète, un seul tiroir ouvert par meuble, vue plongeante (double-clic ou « Ouvrir et regarder dedans »).
- Contenu d'un tiroir fermé construit à sa première ouverture. Mesuré sur l'armoire de test (78 rangements, tiroir plein ouvert) : 128 appels de rendu, 24 000 triangles, 43 rangements construits.
- Génération : liste de tiroirs nommés de hauteurs différentes (`stack`), grille posée au fond pour un tiroir, une boîte ou un bac (auparavant empilée en hauteur, ce qui était faux), étiquettes dans l'ordre de lecture.
- Un rangement divisé montre ses subdivisions : ses objets directs restent listés dans le panneau, pas dans la 3D.

**Tests** : `visual_test.php` (pile, grille, apparence, contenu selon les permissions). Rendu vérifié sur le banc local avec une armoire reprenant les intitulés des photos (données fictives, pas dans la base réelle).

**Pas encore fait** : disposition libre des bacs à la souris dans un tiroir (bacs de tailles variées) ; panneau de propriétés seulement pour le contenu d'un bac, pas de clic sur un objet précis. Rendu non essayé sur un vrai téléphone.

## Gizmo 3D et aperçu en direct (2026-11-05)

Demande de l'utilisateur, après avoir tapé `100` dans une salle réglée en mètres (refus incompréhensible « Valeur entre 1 et 50000 ») : manipuler en 3D et voir ce qu'on ajoute.

**Fait**
- Gizmo (`TransformControls` de Three.js) sur le rangement sélectionné : déplacer (W), pivoter (E, meubles seulement), taille (R), sélection seule (Q). Enregistré au relâchement ; refus du serveur : retour à l'emplacement d'origine.
- Aimantation à 12 mm aux murs, aux bords du parent et aux voisins ; pas de 5 mm, 5° en rotation. Contour rouge, cotes et message d'erreur en direct pendant le glisser. Le déplacement s'aimante seulement pour les meubles à angle droit.
- Ajout et modification dans un **panneau latéral** au lieu d'une fenêtre : le rangement apparaît dans la scène, le formulaire et le gizmo restent synchronisés, le bouton est bloqué tant que l'emplacement est impossible, avec la raison (mêmes règles que le serveur, qui reste seul juge).
- Unité modifiable dans le formulaire, messages dans cette unité, bouton « Ces cotes sont en cm » si les valeurs dépassent la salle.
- « Remplir » un rangement : aperçu en direct des tiroirs ou de la grille de bacs.
- Aperçus 3D dans les fenêtres : salle, ouvertures, objet, type de rangement.
- Corrigé : erreur `toLowerCase` de la recherche sur un événement clavier sans touche (autoremplissage).

**Limites**
- La taille s'applique des deux côtés du centre (comportement du gizmo). Rien ne descend sous le sol, mais un côté peut dépasser : le contour passe alors au rouge.
- Pendant un glisser en taille, le contenu s'étire jusqu'au relâchement.
- Vérifié sur le banc local avec de vrais glissers à la souris, sans CSP (non servie par le banc) ; sur le serveur, tests d'API et PWA. Gizmo tactile non essayé sur un vrai téléphone.

## Plan du bâtiment, raccourcis clavier, modèles et objets de l'atelier (2026-11-05)

Demande de l'utilisateur : Ctrl+Z, C, X ; tous les objets des photos en 3D ; plusieurs objets dans un rangement ; navigation très intuitive ; un plan avec étages façon logiciel d'aménagement, plusieurs portes déplaçables au gizmo, rangements enregistrés et réutilisables.

**Erreur 422 signalée (`PATCH /storage-units/446`)**
- Cause : le tiroir 446 fait toute la profondeur de son armoire (731 mm sur 731). Le moindre glissement en Z le faisait sortir ; le gizmo envoyait quand même la requête, le serveur la refusait à raison.
- Le gizmo s'arrête maintenant à la butée, et un emplacement impossible (chevauchement) n'est plus envoyé : le rangement revient à sa place avec la raison affichée.
- Second défaut trouvé en lisant le même code : `cos(90°)` vaut `6e-17`, donc un meuble tourné de 90° partait avec des positions décimales, refusées par le serveur. `spatial.js` : `rotation()` exact aux angles droits, positions arrondies.

**Fait**
- Migration `0006_plan_templates` : étages, place des salles sur le plan, modèles, deux types de rangement (boîte plastique, plateau à outils). Purement additive.
- Page **Plan** (`pages/plan.js`, `three/plan-scene.js`) : étages en onglets (et vue « Tous » empilée), 2D ou 3D, salles déplaçables, pivotables et redimensionnables au gizmo, aimantation mur contre mur, contour de l'étage du dessous en pointillés, double-clic pour entrer dans une salle. Un meuble tiré dans une autre salle y part avec tout son contenu.
- Portes et fenêtres : `three/room-shell.js` (murs percés et modèles partagés entre la salle et le plan). Sélection au clic, gizmo : l'ouverture suit le mur le plus proche et le mur se recalcule en direct. La porte principale reste sur le mur 1, car elle définit le repère de la salle.
- Raccourcis (`history.js`, `?` affiche l'aide-mémoire) : Ctrl+Z, Y, Maj+Z, C, X, V, D, S, Suppr, F, H, Q W E R, flèches. Annuler concerne les rangements, portes, salles ; **jamais le stock**, qui a son propre historique. Couper-coller déplace le rangement avec son contenu ; copier-coller reprend la structure sans le stock.
- Modèles : `POST /storage-units/tree` (arbre fourni ou `template_id`, chaque niveau passe par `SpaceValidator`, tout ou rien), `GET /storage-units/{id}/tree`, `POST /storage-units/{id}/move`, `/storage-templates`. Bibliothèque dans la salle et sur le plan, dessin de face pour reconnaître chaque modèle.
- Plusieurs objets dans un rangement : fenêtre « Ranger des objets » (recherche, liste, quantité de chacun ; chaque ajout reste un mouvement de stock). 3D : une case par objet en grille, au lieu de bandes de plus en plus fines. Un rangement divisé montre ses objets directs dans la plus grande zone restée libre, comme un marteau à côté des bacs d'un tiroir.
- 89 modèles d'objets au total, dressés d'après les 29 photos de l'atelier (`three/item-models.js`, `visuals.js`), reconnus d'après le nom ; dimensions réelles des piles d'après le nom.
- Ergonomie : infobulle et curseur au survol, notifications avec bouton Annuler, suppression d'un rangement sans fenêtre de confirmation puisqu'elle s'annule (celle d'une salle garde sa confirmation), nom libre proposé à la copie (« Armoire A 2 »), « Plan » dans les onglets mobiles à la place d'« Historique », qui reste dans le menu.

**Tests**
- `plan_test.php` : étages, place des salles, portes, arbres (refus tout ou rien, enfant qui dépasse), modèles, suppression annulée, déplacement entre salles avec le stock, permissions.
- Banc local avec une fausse API appliquant les règles de `spatial.js`, **CSP réelle servie** : vrais clics et glissers sur le scénario du 422, copie, annulation, portes, modèles, changement de salle, profil Élève, 375 px.

**Limites**
- Annuler une suppression de salle : impossible, d'où la confirmation. L'historique est celui de la page : il se vide en la quittant.
- Pas d'escaliers ; une porte principale ne quitte pas le mur 1. Le redimensionnement au gizmo ne concerne que les salles rectangulaires ; un meuble se redimensionne depuis sa salle.
- Le chevauchement de deux salles est signalé, pas interdit : deux plans peuvent se recouvrir le temps de les placer.
- Les modèles d'objets sont des représentations simplifiées, pas des copies fidèles. Rien n'a été ajouté au catalogue : les objets des photos restent à saisir avec leurs vraies quantités.

## Comptes importés depuis Excel et salle Atelier (2026-11-05)

Demande de l'utilisateur : importer une liste Excel dans les utilisateurs, avec un identifiant unique et un mot de passe pour chacun, imprimables par ceux qui ont accès à la page ; les personnes ne changent pas leur mot de passe, un gestionnaire le peut. Et une vraie salle avec ses rangements et des objets.

**Décisions de sécurité**
- **Le mot de passe n'est jamais conservé en clair** : seule son empreinte est stockée (`password_hash`). Il s'affiche à la création, le temps d'imprimer. Pour réimprimer une fiche, on en génère un nouveau, ce qui invalide l'ancien et ferme les sessions de la personne.
- **Données limitées** : le fichier est lu dans le navigateur (`spreadsheet.js`, sans dépendance : .xls BIFF8, .xlsx, .csv). Seuls nom, prénom et groupe partent au serveur ; date de naissance et sexe ne sont ni envoyés ni conservés. Le fichier réel n'est pas dans le dépôt, et les tests utilisent des personnes fictives.
- Pas de compte administrateur par import. Limitation à 30 échecs par adresse et 10 par compte sur 10 minutes. Même réponse et même temps de calcul qu'un compte existe ou non. Aucun mot de passe dans le journal.
- **Tant que le site est en HTTP, les mots de passe circulent en clair sur le réseau** : l'étape HTTPS, déjà prévue, devient plus importante.

**Fait**
- Migration `0007` : `users.email` facultatif, `username`, `password_hash`, `password_set_at`, `user_group`, `import_key` (groupe + nom + prénom : réimporter ne crée pas de doublon) ; types « Établi » et « Rayonnage ».
- `POST /auth/login`, `POST /users/import` (par lots de 25), `POST /users/{id}/password` (`users.manage` seulement : aucune route ne permet de changer le sien soi-même).
- Page Utilisateurs : import avec aperçu, filtre par groupe, mot de passe généré ou choisi, nouvelles fiches pour toute une liste. Fiches à découper, 8 par page A4 (`credentials.js`).
- `backend/bin/seed-atelier.php` : salle « Atelier », 4 armoires, 23 tiroirs, établis, rayonnage, objets d'après les 29 photos. **Quantités estimées**, signalées sur chaque objet et dans l'historique. Refuse de tourner si la salle existe déjà.

**Tests** : `users_import_test.php` (identifiants uniques, doublons, empreinte, permissions, sessions fermées, limitation). Lecteur testé sur le vrai fichier sans afficher ses données ; impression vérifiée sur un vrai PDF avec des personnes fictives.

**Limites** : les étiquettes illisibles sur les photos sont approchées ; les bacs sont en grille régulière, pas à la disposition exacte de chaque tiroir. Une personne perdant sa fiche doit en demander une nouvelle.


## Nouveau style, repris du site Trello 2.0 (2026-11-05)

Demande de l'utilisateur : le même style que les pages du site fourni (`trello2.0.tastytom.ch`). Seule son apparence est reprise : ni code, ni fonction.

**Fait**
- `app.css` : neutres froids et accent indigo en clair comme en sombre, corps de 14 px, contrôles de 36 px, badges en pastilles, tableaux et fenêtres (pied en retrait) du site de référence. Mêmes noms de classes : aucune page modifiée.
- Coque : le menu latéral occupe toute la hauteur et porte la marque, la barre supérieure est à sa droite. Page de connexion en carte centrée.
- Icônes PWA, manifeste et `theme-color` en indigo. Nouveau jeton `--primary-ink` pour le texte coloré.
- `DESIGN.md` mis à jour.

**Écarts volontaires avec la référence**
- Accessibilité conservée : contour des contrôles foncé pour rester à 3:1, cibles de 40 et 44 px au toucher, fondu court au changement de page.
- Inter n'est pas chargée depuis Google Fonts, que la CSP interdit : elle est hébergée dans `frontend/vendor/inter/` (licence OFL, intégrité du paquet npm vérifiée).
- Pas de sélecteur de thème, de palette de commandes ni de menu réductible : ce sont des fonctions, pas du style.

**Tests** : banc local avec fausse API et CSP réelle : accueil, catalogue, fiche, salles, éditeur 3D, plan, utilisateurs, rôles, historique, connexion, fenêtres en clair ; accueil et éditeur en sombre ; profil Élève et 375 px sans débordement ; tiroir à 768 px.

Avec Inter, contrôle automatique à 375 px : 14 pages en Administrateur et 7 en Élève, sans débordement ; police chargée sous la CSP réelle, sans erreur.

**Corrigé au passage** : à 375 px en Administrateur, la page de l'éditeur 3D débordait (470 px), car les libellés masqués des boutons segmentés échappaient à la barre défilante. Ce bug existait avant le changement de style et n'était pas visible avec le profil Élève. Les filtres des emprunts, plus larges avec Inter, sont désormais empilés sur mobile.

**Limites** : pas vérifié sur le serveur réel ni sur un vrai téléphone. Le précache de la police par le service worker a été déduit de la commande du `Dockerfile`, pas essayé sur un déploiement. Catégories, Types de rangement, Accès autorisés et Paramètres n'ont fait l'objet que du contrôle de débordement, sans relecture visuelle.

## Ajout un par un, fiches au choix, pages retirées, vignettes 3D (2026-11-05)

Demandes de l'utilisateur : ajouter un compte à la fois, ne pas devoir tout imprimer, retirer les pages Accès autorisés et Salles (le plan du bâtiment suffit), avoir un aperçu sur les images du catalogue.

**Fait**
- Utilisateurs : « Ajouter une personne » (nom, prénom, groupe, rôle). Passe par `POST /users/import` avec une seule personne : mêmes règles, donc pas de doublon ni de compte administrateur, et aucun changement côté serveur.
- Fiches : cases à cocher dans le tableau (nouvelles fiches pour les comptes cochés, plus pour toute la liste) et dans la fenêtre d'impression. Fermer avertit s'il reste des fiches jamais imprimées. Le mot de passe n'étant pas conservé, réimprimer une fiche en génère toujours un nouveau : c'est dit à l'écran.
- La sélection se limite aux lignes affichées : on ne change jamais le mot de passe d'un compte que l'écran ne montre pas.
- Page Accès autorisés retirée. **Seule la page disparaît** : la table et l'API restent, car `login-link.php`, la connexion Google prévue et les tests en dépendent. Plus d'interface pour autoriser une adresse e-mail : à revoir quand la connexion Google sera activée.
- Page Salles retirée : le plan propose déjà créer, dupliquer, supprimer et entrer dans une salle. L'éditeur `/rooms/{id}` reste, et l'accueil garde ses raccourcis de salles (`room-actions.js`). Les anciennes adresses redirigent vers le plan et vers Utilisateurs.
- Catalogue : sans photo, la vignette montre le modèle 3D de l'objet ; une vraie photo reste prioritaire. Un seul moteur de rendu chargé à la demande dessine chaque modèle une fois en image, puis se libère : un contexte WebGL par carte dépasserait la limite des navigateurs. Les petites pièces fines s'affichent en petit tas. Sans WebGL, l'icône reste.

**Tests** : banc local avec fausse API et CSP réelle : parcours complet d'ajout, doublon, champs vides, sélection partielle (seuls les comptes cochés reçoivent un nouveau mot de passe), impression partielle avec `window.print` simulé, avertissement à la fermeture, redirections, profil Élève, thème sombre, 375 px sans débordement. Corrigés en testant : boutons sur le titre à 375 px, bouton réactivé sans sélection, vignette de résistance presque vide.

**Limites** : pas vérifié sur le serveur réel, ni sur une vraie imprimante pour la sélection. Un objet dont le nom n'est reconnu par aucune règle (ex. « Multimètre ») a la vignette générique d'un boîtier : choisir un modèle sur sa fiche ou y mettre une photo.
