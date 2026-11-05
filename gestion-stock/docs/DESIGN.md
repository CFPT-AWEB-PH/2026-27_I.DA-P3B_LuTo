# Interface : audit, design system et refonte

Ce document décrit la direction visuelle, les jetons de design (implémentés dans `frontend/assets/css/app.css`), les composants communs (`frontend/assets/js/ui.js`) et l'audit de la deuxième passe de refonte (2026-11-03). Il sert de référence pour toute nouvelle page.

## 1. Direction

**Un outil d'atelier, pas une vitrine.** L'application sert à retrouver un objet en quelques secondes, souvent debout devant une armoire, téléphone en main. Personnalité : calme, précise, fiable. Ton : direct, concret, sans jargon.

- **Vert tableau sur fond béton** : une seule couleur de marque, réservée aux actions principales et à l'état « actif ». Tout le reste est neutre.
- **L'étiquette d'emplacement** (`Salle › Armoire › Tiroir`, filet vert à gauche, dernier niveau en gras) est l'élément signature : la réponse à « où est-ce ? » a toujours la même forme, partout.
- **Les chiffres comptent** : quantités en chiffres tabulaires, plus grands que leur unité.
- **Pas de décoration** : ni dégradé, ni verre dépoli décoratif, ni ombre portée sur les cartes. Les ombres expriment uniquement l'élévation (menus, boîtes de dialogue, notifications).
- Polices système (la CSP n'autorise que des polices locales, et elles s'affichent instantanément, sans décalage de mise en page).

## 2. Jetons

Tous définis dans `:root` (thème clair) et redéfinis sous `prefers-color-scheme: dark`. Aucune couleur en dur dans les composants, sauf les étiquettes de la scène 3D (toujours sur fond clair).

### Couleurs

| Rôle | Jeton | Clair | Sombre |
|---|---|---|---|
| Fond | `--bg` | #f3f4f1 | #0e1311 |
| Surface (cartes) | `--surface` | #ffffff | #151b18 |
| Surface creusée (champs de recherche, en-têtes) | `--surface-2` | #eceeea | #1b2320 |
| Survol | `--surface-hover` | #f5f6f3 | #1a211e |
| Élément posé (segment actif) | `--raised` | #ffffff | #2b3631 |
| Séparateurs | `--line` / `--line-strong` | #dde1db / #c3c9c2 | #26302b / #36423c |
| Contour des contrôles (≥ 3:1) | `--line-control` | #878f89 | #5d6a63 |
| Texte principal / secondaire / indication | `--ink` / `--ink-2` / `--ink-3` | 16:1 / 7:1 / ≥ 4,8:1 | 14:1 / 8:1 / ≥ 4,9:1 |
| Marque | `--primary` (+ `-hover`, `-press`, `-fg`, `-soft`, `-soft-ink`) | #1c6a51 | #4fbf93 |
| Succès, avertissement, erreur, info | `--success`, `--warning`, `--danger`, `--info` (+ `-soft`) | | |
| Texte sur rouge | `--danger-fg` | blanc | #2a0b08 (le blanc n'atteint que 2,7:1 sur le rouge clair du thème sombre) |
| Focus | `--focus` | #2457a6 (6,4:1) | #7fa8ee (7,8:1) |
| Voile, notifications | `--overlay`, `--toast-bg`, `--toast-fg`, `--toast-icon` | | |

États : survol = `--surface-hover` ou teinte `-hover` ; appui = `-press` + `translateY(1px) scale(.985)` ; focus = contour 2 px `--focus` décalé de 2 px ; désactivé = opacité 0,5 et curseur interdit ; erreur = bordure `--danger` + message sous le champ.

### Typographie

Deux familles système : `--font` (texte) et `--font-display` (titres).

| Niveau | Taille | Interligne | Graisse | Approche |
|---|---|---|---|---|
| Display (accueil, connexion) | `--text-2xl` 32 px | `--lh-tight` 1,2 | `--fw-bold` 680 | `--tracking-display` −0,02em |
| H1 (titre de page) | `--text-xl` 26 px (24 px mobile) | 1,2 | 680 | −0,02em |
| H2 (section) | `--text-lg` 20 px ; titres de carte `--text-md` 17 px | `--lh-snug` 1,35 | `--fw-semibold` 620 | `--tracking-title` −0,01em |
| H3 | `--text-base` 15 px | 1,35 | 620 | – |
| Corps | `--text-base` 15 px (16 px mobile) | `--lh-body` 1,55 | `--fw-regular` 400 | – |
| Corps petit, métadonnées | `--text-sm` 13 px | 1,45 | 400 | – |
| Légende | `--text-xs` 12 px | 1,4 | 400–620 | – |
| Libellé (champ, bouton, navigation) | `--text-sm` / `--text-base` | 1,2 | `--fw-medium` 550 | – |

Quatre graisses seulement (`--fw-regular`, `--fw-medium`, `--fw-semibold`, `--fw-bold`) ; aucune valeur numérique dans les composants.

### Espacements

Base 4 px : `--s0` 2 · `--s1` 4 · `--s1-5` 6 · `--s2` 8 · `--s3` 12 · `--s4` 16 · `--s5` 20 · `--s6` 24 · `--s8` 32 · `--s10` 40 · `--s12` 48. Cible tactile : `--tap` 44 px.

### Rayons

`--r-xs` 4 (pastilles, cases) · `--r-sm` 6 (badges, éléments d'arbre) · `--r-md` 8 (boutons, champs) · `--r-lg` 12 (cartes, panneaux) · `--r-xl` 16 (boîtes de dialogue). Élément imbriqué : rayon intérieur = rayon extérieur − marge (ex. segment `calc(var(--r-md) - 2px)` dans un groupe à 3 px de marge).

### Ombres

`--shadow-sm` (élément sélectionné, carte survolée) et `--shadow-pop` (menus, suggestions, boîtes de dialogue, notifications). Rien d'autre.

### Mise en page

- Coque : barre supérieure `--topbar-h` 60 px (56 mobile), menu latéral `--sidebar-w` 248 px, barre d'onglets mobile `--tabbar-h` 64 px.
- Contenu : `--content-max` 1120 px (1240 au-delà de 1600 px).
- Points de rupture : mobile < 768 px (barre d'onglets, panneaux glissants, tableaux en cartes) · tablette 768–1023 px (menu en tiroir) · bureau ≥ 1024 px · grand écran ≥ 1600 px.

### Mouvement

| Jeton | Durée | Usage |
|---|---|---|
| `--dur-1` | 120 ms | micro-interactions : survol, appui, case à cocher |
| `--dur-2` | 180 ms | changements d'état, sorties |
| `--dur-3` | 240 ms | composants : menus, panneaux |
| `--dur-4` | 300 ms | entrées : boîtes de dialogue, panneaux glissants, tiroir |

Entrée `--ease-out`, sortie `--ease-in` (toujours plus courte), déplacement `--ease-in-out`. Uniquement `transform` et `opacity`, distances de 4 à 16 px. `prefers-reduced-motion` : animations ramenées à un fondu instantané ; seuls les indicateurs de chargement continuent, ralentis.

## 3. Composants communs (`ui.js`)

| Composant | Rôle |
|---|---|
| `pageHeader` | Titre, description, une action principale, lien de retour. Sur mobile : titre et action sur une ligne, description dessous. |
| `menuButton` | Menu d'actions (motif ARIA APG) : flèches, Début/Fin, Échap rend le focus au bouton. Utilisé pour le compte, les salles, la fiche objet. |
| `filterToggle` | Bouton « Filtres » mobile avec le nombre de filtres actifs (catalogue, historique). |
| `openDialog` / `confirmDialog` | Boîte de dialogue modale, panneau glissant sur mobile, focus piégé et restitué, erreurs affichées au-dessus des boutons. |
| `toast` | Notification ; `action` pour « Annuler ». Reste affichée au survol ou au focus. |
| `busy` | Bouton en cours : libellé conservé (pas de saut), indicateur superposé, double envoi impossible. |
| `emptyState` / `errorState` / `skeleton` | États vide (explication + action), erreur (cause + « Réessayer »), chargement (squelettes). |
| `field` / `checkbox` / `stepper` | Champs avec libellé associé, aide reliée par `aria-describedby`, erreurs par champ. |
| `locationLabel` | Étiquette d'emplacement, lien vers la vue 3D. |
| `searchBox` (`search-box.js`) | Recherche avec suggestions, en barre ou en grand (accueil). |

Règle : les pages ajoutent leurs enfants avec `append(view, …)` ou `h(…)` (de `dom.js`), jamais avec `Element.append`, qui écrirait « null » ou « false » à l'écran pour une condition fausse.

## 4. Audit (deuxième passe, 2026-11-03)

La première refonte (commit c31bb0b) avait posé un socle solide. Cet audit a été mené sur une copie locale de l'application avec des données réalistes, à 1440 px, 768 px et 375 px, en clair et en sombre.

| Écran / composant | Problème | Impact utilisateur | Priorité | Solution |
|---|---|---|---|---|
| Catalogue | Tous les objets affichés « Archivé » et bouton « Prendre » absent : la liste de l'API ne renvoie pas `is_active` | Information fausse ; l'action principale du catalogue n'existe pas | Critique | L'état découle du filtre « Archivés » de la requête |
| Historique (et toute page avec condition fausse) | « null » écrit au-dessus des filtres (`Element.append` convertit `null`/`false` en texte) | Interface cassée, perte de confiance | Haute | `append()` sûr exporté par `dom.js`, utilisé par toutes les pages |
| Accueil | Deux champs de recherche identiques à l'écran (barre + accueil) ; celui de l'accueil sans suggestions | Doublon, hésitation ; la recherche principale est la moins efficace | Haute | Composant `searchBox` réutilisé en grand ; champ de la barre masqué sur l'accueil |
| Fiche objet | Cinq actions de même poids avant « Où le trouver » ; sur mobile, deux lignes de boutons | L'action principale et l'emplacement sont repoussés | Haute | Prendre / Ajouter du stock / Modifier, le reste (photo, supprimer) dans un menu « … » ; « Restaurer » devient principal pour un objet archivé |
| Fiche objet | « Supprimer » proposé alors que le stock n'est pas à zéro, puis refusé par le serveur | Action vouée à l'échec, message après coup | Moyenne | La condition est expliquée avant, avec la marche à suivre |
| Focus clavier | `:focus-visible` remplaçait le rayon des éléments (8 → 6 px) et écrasait leurs ombres | Saut visuel au focus, focus peu soigné | Haute | Contour 2 px décalé, qui épouse le rayon |
| Contraste | `--ink-3` à 4,0–4,3:1 sur les surfaces claires ; contours de champs et boutons à 1,7:1 ; texte blanc à 2,7:1 sur le rouge du thème sombre | Non conforme WCAG AA (1.4.3, 1.4.11) | Haute | `--ink-3` foncé (≥ 4,8:1), jeton `--line-control` (3,3:1), `--danger-fg` foncé en sombre |
| Menus d'actions | Trois implémentations différentes ; pas de navigation aux flèches ; écouteurs jamais retirés | Incohérence, accessibilité partielle | Moyenne | Composant unique `menuButton` |
| Historique mobile | Cinq filtres empilés avant le premier résultat ; chaque mouvement sur six lignes étiquetées | Il faut faire défiler un écran entier pour voir une donnée | Haute | Filtres repliés (`filterToggle`), carte compacte de quatre lignes |
| Historique | Pas de moyen de revenir aux filtres par défaut ; état vide identique avec ou sans filtre | Impasse après un filtrage sans résultat | Moyenne | « Effacer les filtres » + états vides distincts |
| Utilisateurs | Confirmation à chaque changement de rôle | Friction sur une action fréquente et réversible | Moyenne | Changement immédiat, « Annuler » dans la notification |
| Accès autorisés | Confirmation pour retirer une entrée | Idem | Basse | Retrait immédiat, « Annuler » recrée l'entrée |
| Éditeur 3D | Barre d'outils sur deux lignes à 1440 px ; sur mobile, libellés en `display: none` (boutons sans nom pour les lecteurs d'écran) | Vue 3D réduite ; accessibilité | Moyenne | Icônes seules sous 1600 px avec libellé masqué visuellement (nom accessible conservé) |
| En-têtes mobiles | Bouton « Nouvel objet » pleine largeur au-dessus de la recherche | L'action rare domine la tâche principale (chercher) | Moyenne | Titre et action sur une ligne |
| Accueil, catalogue | Focus automatique sur la recherche même au toucher | Clavier virtuel ouvert d'office sur certains téléphones | Moyenne | Focus automatique seulement avec une souris |
| Accueil | « 6 / 10 » sans unité ni sens explicite | Lecture ambiguë | Basse | « 6 / min. 10 pièces » |
| Design system | Graisses arbitraires (500, 550, 600, 620, 650), petits écarts en pixels, durées sans niveau « entrée », couleurs en dur (notifications, scène) | Incohérences fines, maintenance | Moyenne | Jetons `--fw-*`, `--lh-*`, `--s0`/`--s1-5`, `--dur-4`, `--ease-in-out`, `--overlay`, `--toast-*`, `--scene-overlay-*` |

### Synthèse

- **À conserver** : la direction « vert tableau », l'étiquette d'emplacement, la navigation (menu latéral, tiroir, barre d'onglets), les squelettes, les états vides avec action, les boîtes de dialogue en panneau glissant sur mobile, les erreurs par champ, les boutons en cours sans saut.
- **Friction** : la fiche objet surchargée, la recherche en double, l'historique sur mobile, les confirmations pour des actions réversibles.
- **Ce qui faisait amateur** : le « null » à l'écran, les « Archivé » partout, le focus qui change la forme des boutons.
- **Ce qui manquait** : un composant de menu accessible, un moyen d'effacer les filtres de l'historique, l'annulation.
- **Inutile** : le second champ de recherche de l'accueil.

## 5. Auto-critique

| Point de vue | Constat après refonte |
|---|---|
| Débutant | Une seule action dominante par écran ; textes de boutons explicites (« Créer l'objet », « Enregistrer le retour »). |
| Utilisateur régulier | Suggestions dès l'accueil, « / » ou Ctrl+K partout, prise en un clic depuis le catalogue, annulation au lieu de confirmation. |
| Designer | Jetons partout ; reste quelques marges internes en pixels (2–3 px) dans des composants très denses (pastilles, arbre). |
| UX researcher | Friction restante : choisir un emplacement demande toujours deux étapes (salle puis arbre). |
| Mobile | Cibles de 44 px, recherche et actions principales au pouce, panneaux glissants, aucun défilement horizontal. |
| Clavier, lecteur d'écran | Focus visible sur tout, menus aux flèches, focus piégé dans les boîtes de dialogue et restitué, noms accessibles conservés sur les boutons icônes. |
| Développeur | Trois menus remplacés par un composant ; `append` sûr ; recherche réutilisable ; aucune modification de l'API. |

## 6. Pistes pour la suite

1. **API** : renvoyer `is_active` dans la liste des objets (le correctif actuel en déduit l'état du filtre, ce qui reste exact mais implicite).
2. **Choix d'emplacement** : recherche directe d'un rangement (« Tiroir 2 ») dans le sélecteur, au lieu de salle puis arbre.
3. **Emprunts** : date de retour prévue et mise en avant des retards.
4. **Annulation des mouvements de stock** (prise, ajout) : nécessite une opération inverse côté serveur.
5. **Vignettes des salles** : dessiner les meubles sur le plan miniature (l'API de liste ne renvoie pas encore les emprises).
6. **Tests d'interface** automatisés (Playwright) sur les parcours prendre / rendre / ajouter, aux trois points de rupture.
