# Journal de bord — Projet Sabre Laser Arbitrage

**Projet :** Plateforme web de gestion de tournoi de sabre laser (inscription, arbitrage, classement, affichage public)
**Équipe :** Tom & Lucas
**Infrastructure cible :** Raspberry Pi (Docker : Apache/PHP + MariaDB)

---

## 1. Chronologie du projet

```mermaid
timeline
    title Chronologie du projet
    17 Août : Rentrée scolaire
            : Choix du projet
            : Mise en place du poste de travail (WSL, disques flashés)
    20 Août : Conception de la maquette (index, arbre, classement, écran, admin, arbitre)
    24 Août : Brainstorming sur l'architecture (site inscription → CSV → Raspberry)
            : Début du CSS et du backend / API (Lucas)
            : Base générée par IA + recherche import CSV (Tom)
            : Création de la base MariaDB et du schéma (Tom)
    31 Août : Rédaction du README détaillé (Lucas)
            : Poursuite du développement backend (Lucas)
    07 Sept : Ajout du champ "Equipe" au CSV (demande du professeur)
            : Dockerisation complète du projet (Lucas)
            : Déploiement et tests sur le Raspberry (Tom)
    14 Sept : Intégration de Bootstrap et correction de la navbar (Lucas)
            : Correction de l'erreur 500 sur l'import CSV (Tom)
            : Tests multi-utilisateurs et début de la détection d'affichage (Tom)
            : Mise à jour du journal de bord et du README (équipe)
    17 Sept : Refonte complète UX/UI — Design System v3 (Tom)
            : Suppression de Bootstrap et corrections de bugs CSS (Lucas)
```

---

## 2. Architecture générale du système

```mermaid
flowchart LR
    A["Site web d'inscription"] -->|Export| B["Fichier CSV / Excel"]
    B -->|"Import par l'administrateur"| D["Serveur Raspberry<br/>(Docker : Apache + PHP)"]
    D <--> E[("Base de données MariaDB<br/>sabre_arbitrage")]

    D --> F["index.php<br/>Accueil public"]
    D --> G["arbre.php<br/>Bracket du tournoi"]
    D --> H["classement.php<br/>Classement public"]
    D --> I["ecran.php<br/>Écran d'affichage salle"]
    D --> J["admin.php<br/>Dashboard administrateur"]
    D --> K["arbitre.php<br/>Interface d'arbitrage"]

    style D fill:#1f2937,color:#fff
    style E fill:#0f766e,color:#fff
```

Le choix s'est porté sur une architecture en deux temps plutôt que sur une solution centralisée dès l'inscription : les participants s'inscrivent via un site web séparé, dont les données sont exportées en CSV, puis importées manuellement par l'administrateur sur le serveur du tournoi hébergé sur le Raspberry. Ce découplage simplifie la phase d'inscription (accessible sans dépendre de la disponibilité du Raspberry) et laisse à l'administrateur le contrôle du moment où le tournoi démarre.

---

## 3. Schéma de la base de données (MariaDB)

```mermaid
erDiagram
    USERS ||--o{ JOUEURS : "compte optionnel"
    GRADES ||--o{ JOUEURS : "grade"
    JOUEURS ||--o{ COMBATS : "joueur1"
    JOUEURS ||--o{ COMBATS : "joueur2"
    JOUEURS ||--o{ COMBATS : "vainqueur"
    COMBATS ||--o{ COMBAT_ARBITRES : "arbitres assignés"
    USERS ||--o{ COMBAT_ARBITRES : "arbitre"
    COMBATS ||--o{ POINTS : "notes par manche"
    USERS ||--o{ POINTS : "arbitre notant"
    COMBATS ||--o{ MANCHES_RESULTATS : "résultat par manche"

    USERS {
        int id PK
        varchar username
        varchar password_hash
        enum role "admin, arbitre, joueur, public"
        varchar nom
        varchar prenom
        boolean actif
    }
    GRADES {
        int id PK
        varchar nom
        int ordre
        varchar couleur
    }
    JOUEURS {
        int id PK
        int user_id FK
        varchar pseudo
        varchar nom
        varchar prenom
        enum sexe
        date date_naissance
        decimal poids
        int grade_id FK
        int victoires
        int defaites
        int matchs_joues
        decimal points_cumules
    }
    COMBATS {
        int id PK
        int joueur1_id FK
        int joueur2_id FK
        int duree_secondes
        enum statut "a_venir, en_cours, termine, annule"
        datetime date_prevue
        decimal score_joueur1
        decimal score_joueur2
        int vainqueur_id FK
        int manche_actuelle
        int tour
        int position
    }
    COMBAT_ARBITRES {
        int id PK
        int combat_id FK
        int user_id FK
        enum role "central, coin1, coin2"
    }
    POINTS {
        int id PK
        int combat_id FK
        int manche
        int arbitre_user_id FK
        decimal joueur1_points
        decimal joueur2_points
    }
    MANCHES_RESULTATS {
        int id PK
        int combat_id FK
        int manche
        decimal moyenne_joueur1
        decimal moyenne_joueur2
    }
    PARAMETRES {
        varchar cle PK
        varchar valeur
        varchar description
    }
```

Points clés du schéma :
- La table `joueurs` est **indépendante** du compte utilisateur (`users`) : un joueur peut exister sans compte, ce qui correspond au flux d'inscription par CSV.
- Chaque combat est jugé par **3 arbitres** (1 central + 2 coins), via `combat_arbitres`, et chaque arbitre note indépendamment dans `points` avant qu'une moyenne soit calculée dans `manches_resultats`.
- La table `parametres` permet de configurer l'application (durée des combats, écarts de poids/âge/grade pour l'appariement automatique, intervalle de rafraîchissement, retard cumulé, etc.) **sans toucher au code**, directement depuis le dashboard admin.
- Les champs `tour` et `position` dans `combats` permettent de reconstituer l'arbre du tournoi (bracket) affiché sur `arbre.php`.

---

## 4. Journal détaillé

### 17.08 — Rentrée scolaire

**Choix du projet**
Nous avons choisi ce projet car nous le trouvons particulièrement intéressant. Il présente aussi l'avantage de pouvoir intégrer le projet de l'atelier Raspberry directement au site web, ce qui a été un facteur déterminant dans notre motivation à le sélectionner.

**Mise en place du poste de travail**
- Installation des disques durs flashés
- Installation de WSL (Windows Subsystem for Linux)
- Configuration générale de l'environnement de développement

---

### 20.08 — Conception de la maquette

**Lucas :** absent.

**Tom :** mise en place de la maquette générale du site, définissant les six pages principales de l'application et leurs niveaux d'accès :

- `index.php` (page principale), accessible à tout le monde :
  ![maquette](images/maquette.png)
- `arbre.php` (visualisation en style "arbre"), accessible à tout le monde, inspiré des images de grands tournois (coupe du monde, etc.) :
  ![arbres](images/tournoiEnArbre.jpg)
- `classement.php`, accessible à tout le monde :
  ![classement](images/classement.drawio.png)
- `ecran.php`, écran d'affichage (ex : écran principal de la salle) qui affiche en continu les matchs en cours, ceux à venir et les scores
- `admin.php` / dashboard, page accessible uniquement à l'admin, permet de gérer le tournoi, lancer des matchs, modifier les participants, changer les paramètres du tournoi :
  ![dashboard](images/dashboard.drawio.png)
- `arbitre.php`, accessible uniquement aux admins ainsi qu'aux arbitres, sert à arbitrer les matchs, donner des points aux participants.

---

### 24.08 — Brainstorming architecture & débuts du développement

**Réflexion sur l'infrastructure**
La première idée envisagée consistait en une architecture centralisée sur une seule machine (tout sur le Raspberry). Après discussion, nous avons retenu une architecture en deux étapes :

> **Site web d'inscription → Fichier Excel/CSV → Serveur Raspberry du tournoi**

Cette approche facilite l'inscription des participants : l'administrateur n'a plus qu'à importer le fichier CSV final pour démarrer le tournoi, sans dépendre de la disponibilité du Raspberry pendant toute la phase d'inscription.

Infrastructure :
![schema](images/shema.png)

*(voir aussi le schéma Mermaid équivalent en section 2)*

**Lucas :**
- Reprise et modification du CSS initialement généré par Tom, avec passage à un thème monochrome plus professionnel, en cohérence avec la maquette.
- Développement du backend, notamment de l'API : celle-ci retourne, pour un combat identifié par son `id`, son statut, le score des deux joueurs, le temps restant, le vainqueur, etc.

**Tom :**
- Génération, via l'intelligence artificielle, d'une base de code exploitable pour le projet. Cette base contenait de nombreux problèmes et a nécessité d'être largement reprise, mais a fourni un point de départ utile, notamment pour le style CSS.
- **Problème rencontré :** l'importation d'un fichier CSV vers PHP. La documentation officielle de PHP a permis d'avancer, en particulier :
  - les [fonctions du système de fichiers](https://www.php.net/manual/en/ref.filesystem.php)
  - la fonction [`fgetcsv`](https://www.php.net/manual/en/function.fgetcsv.php)
- Démarrage du développement du backend et de la base de données : création de la base **MariaDB** et conception du schéma (voir section 3).

---

### 31.08 — Documentation & backend

**Lucas :**
- Rédaction plus poussée du `README.md` : ajout d'une documentation détaillée sur les technologies utilisées et l'architecture du projet.
- Poursuite du développement du backend.

**Tom :** absent.

---

### 07.09 — Retours du professeur & industrialisation

**Brainstorming avec le professeur**
Trois points d'amélioration ont été identifiés :
1. Ajouter un champ **"Equipe"** au CSV d'inscription.
2. Adapter l'UX/UI de l'interface web à la **version Raspberry** (actuellement non optimisée).
3. **Automatiser la gestion des retards** : puisque l'heure de démarrage de chaque combat est connue, il devient possible de décaler automatiquement les matchs suivants plutôt que de saisir manuellement le temps de retard (voir le paramètre `retard_minutes` en base).

**Lucas :**
- Implémentation de **Docker** pour le projet : ajout d'un `Dockerfile`, d'un `docker-compose.yml` et d'un `apache.conf`.
- Tâche longue (une grande partie de l'après-midi), la dernière utilisation de Docker par l'équipe remontant à un certain temps.

**Tom :**
- Déploiement du site web sur le Raspberry, avec constat que l'interface n'est pas encore adaptée à ce format.
- Installation de Docker sur le Raspberry et lancement de l'installation du projet.
- **Problèmes rencontrés :**
  - Impossible de cloner le dépôt (privé) directement depuis le Raspberry → résolu en se connectant au Raspberry avec les identifiants appropriés.
  - Complications réseau : nécessité d'alterner entre le port du PC et celui du Raspberry → résolu en utilisant un poste de travail inutilisé dédié.

---

### 14.09 — Bootstrap, corrections & tests multi-utilisateurs

**Lucas :**
- Mise en place des éléments **Bootstrap** sur le site.
- Tentative de correction de la navbar : un bug fait apparaître en format ordinateur un bouton qui ne devrait être visible qu'en format téléphone.
- Amélioration générale de la navigation du site.

**Tom :**
- Vérification du fonctionnement du site avec plusieurs utilisateurs connectés simultanément.
- Correction de l'erreur 500 causée par des soucis lors de l'importation du fichier CSV.
- Démarrage du système de détection (touche du sabre laser) et d'affichage sur le site, synchronisé avec la même base de données.

**Ensemble :**
- Amélioration du journal de bord et du README.

---

### 17.09 — Refonte UX/UI complète & corrections de bugs CSS

**Tom :**

**Refonte complète de l'interface (Design System v3)**
Avec l'aide de l'IA, refonte totale de l'UX/UI du site autour d'un design system CSS maison (`style.css` v3, ~2 100 lignes) : thème sombre, palette de tokens CSS (`--accent`, `--surface`, `--text`, etc.), typographies Orbitron / Rajdhani / Inter, animations fluides, système de couches CSS (`@layer reset, tokens, base, layout, components, utilities`). Cette approche garantit que tous les styles du site sont cohérents et maintenables sans dépendre d'un framework externe. Fichiers modifiés : `assets/css/style.css`, `assets/js/app.js`, `login.php`, `arbre.php`.

**Suppression de Bootstrap sur `arbre.php`**
Bootstrap était chargé via `$extraHead` avant `style.css` dans le `<head>`. Comme Bootstrap n'utilise pas les couches CSS (`@layer`), il est considéré comme un style "non layered" par le navigateur et écrase automatiquement tous nos styles en `@layer components`, quelle que soit la spécificité. La topbar et les tokens de design étaient donc ignorés sur cette page. Correction : suppression totale de Bootstrap sur `arbre.php` et remplacement de toutes ses classes utilitaires (`d-flex`, `gap-2`, `alert`, etc.) par des classes CSS natives utilisant les tokens du design system.

**Correction du bouton menu mobile visible sur desktop**
La navbar affichait un petit carré parasite sur desktop. Le bug venait d'un conflit de priorité entre les couches CSS :
- `.nav-toggle { display: none; }` était défini dans `@layer layout`
- `button { display: inline-flex; }` était défini dans `@layer components`
**Lucas :**
Dans la cascade CSS, `@layer components` est prioritaire sur `@layer layout` — la spécificité du sélecteur ne compte plus quand les couches sont différentes. Le bouton `inline-flex` écrasait donc le `display: none`, rendant le bouton visible partout. Correction : ajout de `display: none;` directement dans le bloc `.nav-toggle` déjà présent dans `@layer components`. À spécificité égale dans la même couche, `.nav-toggle` (0,1,0) bat `button` (0,0,1) — le bouton est maintenant masqué sur desktop et visible uniquement sur mobile (< 700 px).

**Correction de la fenêtre modale positionnée en haut à gauche**
Les fenêtres modales (`<dialog>` natif HTML, ouvertes via `showModal()`) apparaissaient en haut à gauche de l'écran au lieu d'être centrées. La cause : le reset CSS `@layer reset { * { margin: 0; } }` supprimait le `margin: auto` de la feuille de style navigateur (UA stylesheet) qui est responsable du centrage automatique des `<dialog>`. Correction : ajout de `dialog { margin: auto; }` dans `@layer reset`, juste après `* { margin: 0; }`. Le sélecteur `dialog` (spécificité 0,0,1) l'emporte sur `*` (spécificité 0,0,0) dans la même couche, ce qui restaure le centrage natif.

**Correction de la fermeture modale par la touche Échap**
Quand l'utilisateur fermait une modale formulaire avec la touche Échap, la modale se fermait visuellement mais l'URL conservait `?action=new`, ce qui pouvait provoquer une réouverture involontaire. Correction dans `app.js` : ajout d'un écouteur sur l'événement `close` du `<dialog>` pour rediriger vers l'URL d'annulation dès que la modale est fermée, quelle qu'en soit la cause (Échap, clic sur le fond, bouton Annuler).

---

## 5. Prochaines étapes

- [ ] Ajouter le champ `Equipe` à l'import CSV et à la table `joueurs`
- [ ] Adapter l'UX/UI pour l'affichage sur le format Raspberry
- [ ] Automatiser le décalage des matchs en cas de retard (utilisation du paramètre `retard_minutes`)
- [ ] Finaliser l'interface `arbitre.php` (saisie des points par manche, moyenne des 3 arbitres)
- [ ] Finaliser l'affichage `arbre.php` à partir des champs `tour` / `position`
- [ ] Terminer la refonte UX/UI des pages restantes (`index.php`, `classement.php`, `scores.php`, `admin/combats.php`, `admin/parametres.php`, `arbitrage/saisie.php`)
