# Interface : audit, design system et refonte

Ce document décrit la direction visuelle, les jetons de design (implémentés dans `frontend/assets/css/app.css`), les composants communs (`frontend/assets/js/ui.js`) et les audits successifs. Il sert de référence pour toute nouvelle page.

État actuel (2026-11-09) : troisième refonte, « Établi ». Elle garde l'accent indigo et la police Inter choisis le 2026-11-05 (style repris du site Trello 2.0), mais refait la hiérarchie, les fonds, les composants de liste, le mouvement et les parcours. Les sections 1 à 4 décrivent l'état actuel ; les sections 5 et suivantes sont l'historique des audits et citent parfois des styles abandonnés.

## 1. Direction

**Un instrument de précision, pas une vitrine.** L'application sert à retrouver un objet en quelques secondes, souvent debout devant une armoire, téléphone en main. « Calme, précis, dense quand il le faut. » Ton : direct, concret, sans jargon.

- **Un seul accent, et rare.** L'indigo est réservé à l'action principale de l'écran (une seule par zone), à la sélection et au focus. Les actions répétées sur chaque ligne d'une liste (« Prendre », « Rendre ») sont *teintées* (`btn-tinted`) : visibles sans transformer la liste en mur de boutons de même poids.
- **La hiérarchie passe d'abord par la taille, la graisse et l'espace**, la couleur ensuite. Chaque écran a un élément dominant : la recherche (accueil, catalogue), la quantité en stock et « Où le trouver » (fiche objet), la quantité à rendre (emprunts).
- **L'étiquette d'emplacement** (`Salle › Armoire › Tiroir`, filet indigo à gauche, dernier niveau en gras) est l'élément signature. Variante compacte dans les listes : une ligne, niveaux intermédiaires raccourcis, chemin complet au survol et pour les lecteurs d'écran.
- **Les chiffres comptent** : chiffres tabulaires, plus grands que leur unité ; la quantité en stock d'une fiche est le plus grand texte de la page (36 px).
- **La profondeur passe par la bordure**. Ombre uniquement pour ce qui flotte (menus, fenêtres, notifications) et au survol des cartes cliquables. Seul dégradé : le halo de la page de connexion.
- **Fonds lumineux et calmes** : fond de page `#f6f7f9` (au lieu d'un gris bleuté plus sombre), cartes blanches, filets à peine marqués. La barre supérieure se fond dans la page et ne se détache (filet) qu'au défilement.
- **Inter**, hébergée dans `frontend/vendor/inter/` (la CSP n'autorise que les polices du site). Références d'objets en police à chasse fixe système (`--font-mono`) : elles se recopient et se comparent caractère par caractère.

## 2. Jetons

Tous définis dans `:root` (thème clair) et redéfinis sous `prefers-color-scheme: dark`. Aucune couleur en dur dans les composants, sauf les étiquettes de la scène 3D (toujours sur fond clair).

### Couleurs

| Rôle | Jeton | Clair | Sombre |
|---|---|---|---|
| Fond de page et menu latéral | `--bg` | #f6f7f9 | #0b0c0f |
| Cartes, champs, fenêtres | `--surface` | #ffffff | #14161b |
| Zone en retrait dans une carte (en-têtes de tableau, pieds de fenêtre) | `--surface-2` | #f3f4f7 | #191b21 |
| Survol sur le fond, piste des contrôles segmentés, badges neutres | `--surface-3` | #e9ecf1 | #22252c |
| Survol sur une carte | `--surface-hover` | #f7f8fa | #1a1c22 |
| Séparateurs, contours de cartes | `--line` | #e3e6eb | #23262d |
| Contours des boutons secondaires | `--line-strong` | #cdd2da | #343842 |
| Contours des champs et cases (≥ 3:1, WCAG 1.4.11) | `--line-control` | #7f8796 | #626977 |
| Texte principal / secondaire / indication | `--ink` / `--ink-2` / `--ink-3` | 17:1 / 9,9:1 / 5,9:1 | ≥ 12:1 / ≥ 8:1 / ≥ 5:1 |
| Marque | `--primary` (+ `-hover`, `-press`, `-fg`) | #4f46e5 | #5d5cf0 |
| Teinte de marque (sélection, actions de ligne) | `--primary-soft`, `--primary-soft-2`, `--primary-soft-ink` | | |
| Liens et texte coloré | `--primary-ink` | #4338ca (7,4:1) | #a9abff |
| Succès, avertissement, erreur, info | `--success`, `--warning`, `--danger`, `--info` (+ `-soft`) | | |
| Focus | `--focus`, `--focus-ring` (halo des champs) | #4f46e5 | #8b8dff |

États : survol = `--surface-hover` / `--surface-3` ou teinte `-hover` ; appui = `scale(.97)` sans ombre ; focus = contour 2 px `--focus` décalé de 2 px (champs : bordure `--focus` + halo `--focus-ring`) ; désactivé = opacité 0,5 ; erreur = bordure `--danger` + message sous le champ ; en cours = indicateur dans le bouton, libellé conservé.

### Typographie

| Niveau | Jeton | Taille | Graisse | Usage |
|---|---|---|---|---|
| Chiffre principal | `--text-3xl` | 36 px | 680 | Quantité en stock d'une fiche |
| Display | `--text-2xl` | 28 px | 680 | Salutation de l'accueil, chiffres clés |
| H1 | `--text-xl` | 24 px (22 mobile) | 680, −0,025em | Titre de page |
| H2 | `--text-lg` | 17 px | 600 | Titre de fenêtre ; titres de carte en `--text-md` 15 px |
| Corps | `--text-base` | 14 px (15 mobile, champs 16) | 400, interligne 1,5 | Texte courant |
| Petit | `--text-sm` | 13 px | 400–500 | Métadonnées, libellés de champ |
| Légende | `--text-xs` | 12 px | 400–500 | Indications, en-têtes de tableau |
| Titre de groupe | `--text-2xs` | 11 px, capitales | 600, +0,06em | Groupes du menu, onglets mobiles |

Quatre graisses (`--fw-regular` 400, `--fw-medium` 500, `--fw-semibold` 600, `--fw-bold` 680).

### Espacements, rayons, ombres

- Espacements, base 4 px : `--s0` 2 · `--s1` 4 · `--s1-5` 6 · `--s2` 8 · `--s2-5` 10 · `--s3` 12 · `--s4` 16 · `--s5` 20 · `--s6` 24 · `--s8` 32 · `--s10` 40 · `--s12` 48 · `--s16` 64. Cible tactile `--tap` 44 px.
- Rayons : `--r-xs` 4 (puces, touches) · `--r-sm` 6 (petits boutons, menus) · `--r-md` 8 (boutons, champs) · `--r-lg` 12 (cartes) · `--r-xl` 16 (fenêtres). Élément imbriqué : rayon extérieur − marge.
- Ombres : `--shadow-xs` (boutons secondaires), `--shadow-sm` (carte survolée, grande recherche), `--shadow-md` (menus, suggestions), `--shadow-lg` (fenêtres, notifications). `--highlight` : reflet interne d'un pixel sur les boutons pleins.

### Mise en page

- Coque : menu latéral 240 px ; barre supérieure 56 px fondue dans la page ; barre d'onglets mobile 64 px.
- Contenu : 1200 px (1360 au-delà de 1600 px), marges `--gutter` 32 px.
- Fiche objet : deux colonnes au-delà de 1024 px (contenu + colonne de 20 rem collante) ; une colonne en dessous, réordonnée « combien → où → qui l'a → description → historique ».
- Points de rupture : mobile < 768 px · tablette 768–1023 px (menu en tiroir) · bureau ≥ 1024 px · grand écran ≥ 1600 px.

### Mouvement

| Jeton | Durée | Usage |
|---|---|---|
| `--dur-1` | 100 ms | survol, appui |
| `--dur-2` | 160 ms | changements d'état, sorties |
| `--dur-3` | 220 ms | menus, entrée de page, éléments de liste |
| `--dur-4` | 280 ms | fenêtres, panneaux glissants, notifications |
| `--stagger` | 24 ms | décalage entre éléments d'une liste (8 premiers seulement) |

Entrée `--ease-out` (décélérée), sortie `--ease-in` (plus courte), déplacement `--ease-in-out`, rebond léger `--ease-spring` (coche, notification). Uniquement `transform` et `opacity`, distances de 4 à 12 px. Rien ne tourne en boucle sauf les indicateurs de chargement et le reflet des squelettes. `prefers-reduced-motion` : plus de déplacement ni de décalage, reflet des squelettes supprimé, indicateurs ralentis.

Ce que le mouvement explique : la page change (fondu + 4 px), une liste se remplit (cascade), une fenêtre vient de l'action qui l'a ouverte (montée + échelle), une notification arrive (rebond) et part sur le côté, un menu s'ouvre depuis son bouton (origine en haut à droite), un chevron indique l'état ouvert, une barre de progression se remplit.

## 3. Composants communs (`ui.js`)

| Composant | Rôle |
|---|---|
| `pageHeader` | Titre, description d'une phrase, une action principale, lien de retour. Sur mobile : titre et action sur une ligne. |
| `menuButton` | Menu d'actions (motif ARIA APG) : flèches, Début/Fin, Échap rend le focus au bouton. |
| `filterToggle` | Bouton « Filtres » mobile avec le nombre de filtres actifs. |
| `openDialog` / `confirmDialog` | Fenêtre modale, panneau glissant sur mobile (geste de fermeture), focus piégé et restitué, erreurs au-dessus des boutons. |
| `operationDialog` (stock) | Fenêtre d'opération dont le bouton dit ce qui va se passer : « Prendre 3 pièces », « Rendre 1 pièce ». |
| `toast` | Notification ; `action` pour défaire. Reste au survol ou au focus ; les erreurs restent jusqu'à fermeture. |
| `busy` | Bouton en cours : libellé conservé, indicateur superposé, double envoi impossible. |
| `emptyState` | Explication + action. `compact` : une ligne dans une carte ; `tone: 'success'` : « tout va bien » plutôt que « rien ». |
| `errorState` / `skeleton` | Erreur (cause + « Réessayer ») ; chargement (squelettes avec reflet). |
| `field` / `checkbox` / `stepper` | Libellé associé, aide reliée par `aria-describedby`, erreurs par champ ; le pas à pas a un seul contour et un seul anneau de focus. |
| `locationLabel` | Étiquette d'emplacement, lien vers la vue 3D ; `compact` pour les listes et tableaux. |
| `searchBox` | Recherche avec suggestions et texte saisi mis en évidence, en barre ou en grand. « / » ou Ctrl+K vise la recherche de la page s'il y en a une. |

Boutons : `btn-primary` (une action principale par zone) · `btn` (secondaire) · `btn-tinted` (action répétée sur chaque ligne) · `btn-ghost` · `btn-danger` / `btn-danger-ghost` · `icon-btn` (+ `-bordered`).

Règle : les pages ajoutent leurs enfants avec `append(view, …)` ou `h(…)` (de `dom.js`), jamais avec `Element.append`, qui écrirait « null » ou « false » à l'écran pour une condition fausse.

## 4. Troisième refonte « Établi » (2026-11-09)

Audit mené sur une pile Docker locale avec les données de l'atelier (362 objets, 396 rangements), des emprunts et des stocks bas, à 1440, 900 et 375 px, en clair et en sombre, avec un compte administrateur puis un compte Élève.

| Écran / composant | Problème | Impact | Solution |
|---|---|---|---|
| Catalogue | Bouton « Prendre » plein indigo sur chacune des cartes | Tout a le même poids ; l'indigo ne signale plus rien | Bouton teinté `btn-tinted` ; l'indigo reste à « Nouvel objet » |
| Catalogue | Deux champs de recherche (barre + page) | Hésitation, doublon déjà corrigé sur l'accueil mais pas ici | Barre masquée sur les pages qui ont leur recherche ; « / » vise celle de la page |
| Accueil | Cinq tuiles de chiffres aussi grandes que les cartes, libellés à parenthèses sur deux lignes, tuile orpheline | Sur mobile, un écran entier avant le premier emprunt | Bandeau unique découpé en cellules ; libellé court + précision dessous ; rangée qui défile au doigt sur mobile |
| Accueil | États vides en grand (icône, 3 lignes) dans de petites cartes | Cartes gonflées par l'absence d'information | État vide compact, ton « succès » quand il n'y a rien à faire |
| Accueil (élève) | Carte et chiffre « À réapprovisionner » montrés à qui ne peut pas réapprovisionner | Bruit sans action possible | Réservés à la permission `stock.add` |
| Fiche objet | Une colonne à 1440 px ; boîte « En stock » étirée sur 700 px ; deux boutons « Prendre » quand il n'y a qu'un emplacement ; pas de visuel sans photo | Espace perdu, hiérarchie plate, doublon | Deux colonnes, quantité en 36 px dans une carte latérale, « Prendre » une seule fois (en tête si un emplacement, sur chaque ligne sinon), modèle 3D quand il n'y a pas de photo |
| Étiquette d'emplacement | Bloc gris de 3 à 4 lignes sur mobile dans chaque carte | La réponse « où » noie le reste | Variante compacte : une ligne, niveaux intermédiaires raccourcis, le parent de l'emplacement exact raccourci en dernier |
| Historique | Date et heure sur deux lignes cassées ; colonne d'emplacement qui fait déborder le tableau de la fiche | Lecture hachée, défilement horizontal | Date puis heure en sous-ligne ; colonne d'emplacement bornée, étiquette compacte |
| Fenêtres de stock | Boutons génériques (« Prendre », « Enregistrer le retour ») ; « (facultatif) » dans les exemples de saisie | L'utilisateur vérifie la quantité deux fois | Bouton qui annonce l'action : « Prendre 2 pièces » ; mention « facultatif » dans le libellé |
| Pas à pas | Trois contours et un anneau de focus sur le seul champ central | Composant qui paraît bricolé | Un contour pour le groupe, anneau autour de l'ensemble |
| Connexion | « Pas de compte Google ? » alors que la connexion Google n'est pas proposée ; aucun retour pendant l'envoi | Message incompréhensible ; doute « ça a marché ? » | Texte adapté (« Fiche perdue ? ») ; indicateur dans le bouton ; focus sur le champ vide |
| Fond et barre | Gris bleuté assez sombre ; barre supérieure blanche toujours détachée et vide sur l'accueil | Impression terne et lourde | Fond plus clair, barre fondue dans la page, filet au défilement seulement |
| Mouvement | Seul un fondu de page ; squelettes qui clignotent | Changements d'état peu lisibles | Cascade des listes, montée des fenêtres, rebond des notifications, reflet des squelettes, chevrons qui pivotent ; tout coupé en mouvement réduit |
| Vignettes 3D | Rendus enchaînés sans laisser passer d'image | Saccades pendant l'entrée de la liste | Une image affichée entre deux rendus |

**Conservé** : la navigation (menu latéral, tiroir, barre d'onglets), l'étiquette d'emplacement, les fenêtres en panneau glissant, l'annulation plutôt que la confirmation, les contrastes AA, `prefers-contrast` et `prefers-reduced-transparency`, les squelettes et états d'erreur. **Non touché** : l'éditeur 3D et le plan (outils denses, cohérents avec les nouveaux jetons sans changement de structure), l'API.

### Auto-critique

| Point de vue | Constat |
|---|---|
| Débutant | Chaque écran a un élément dominant ; les boutons disent ce qu'ils font, quantité comprise. |
| Utilisateur régulier | « / » partout, prise en un clic depuis le catalogue, état vide de l'accueil qui confirme « rien à rendre » d'un coup d'œil. |
| Designer | Plus aucune couleur en dur hors scène 3D ; un seul bouton plein par zone ; graisses et tailles tirées des jetons. Reste : les panneaux de l'éditeur 3D gardent une densité propre, à revoir dans une passe dédiée. |
| UX researcher | Friction restante : choisir un emplacement demande toujours deux étapes (salle puis arbre). |
| Mobile | Cibles de 44 px, bandeau de chiffres qui défile au doigt, étiquettes sur deux lignes au plus, aucun défilement horizontal de page. |
| Clavier, lecteur d'écran | Focus visible partout (y compris les cases des filtres), chemin complet des étiquettes compactes lu en entier, noms accessibles sur les boutons de ligne (« Prendre Accus 18650 »). |
| Performance | Animations en `transform`/`opacity` seulement ; cascade limitée aux 8 premiers éléments ; vignettes 3D étalées sur plusieurs images. |

## 5. Audit (deuxième passe, 2026-11-03)

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

## 6. Auto-critique (deuxième passe)

| Point de vue | Constat après refonte |
|---|---|
| Débutant | Une seule action dominante par écran ; textes de boutons explicites (« Créer l'objet », « Enregistrer le retour »). |
| Utilisateur régulier | Suggestions dès l'accueil, « / » ou Ctrl+K partout, prise en un clic depuis le catalogue, annulation au lieu de confirmation. |
| Designer | Jetons partout ; reste quelques marges internes en pixels (2–3 px) dans des composants très denses (pastilles, arbre). |
| UX researcher | Friction restante : choisir un emplacement demande toujours deux étapes (salle puis arbre). |
| Mobile | Cibles de 44 px, recherche et actions principales au pouce, panneaux glissants, aucun défilement horizontal. |
| Clavier, lecteur d'écran | Focus visible sur tout, menus aux flèches, focus piégé dans les boîtes de dialogue et restitué, noms accessibles conservés sur les boutons icônes. |
| Développeur | Trois menus remplacés par un composant ; `append` sûr ; recherche réutilisable ; aucune modification de l'API. |

## 7. Pistes pour la suite

1. **API** : renvoyer `is_active` dans la liste des objets (le correctif actuel en déduit l'état du filtre, ce qui reste exact mais implicite).
2. **Choix d'emplacement** : recherche directe d'un rangement (« Tiroir 2 ») dans le sélecteur, au lieu de salle puis arbre.
3. **Emprunts** : date de retour prévue et mise en avant des retards.
4. **Annulation des mouvements de stock** (prise, ajout) : nécessite une opération inverse côté serveur.
5. **Vignettes des salles** : dessiner les meubles sur le plan miniature (l'API de liste ne renvoie pas encore les emprises).
6. **Tests d'interface** automatisés (Playwright) sur les parcours prendre / rendre / ajouter, aux trois points de rupture.

## 8. Revue selon les Human Interface Guidelines (skill apple-design)

Revue menée avec le skill [dickwu/apple-design-skill](https://github.com/dickwu/apple-design-skill) (pages HIG d'Apple). L'application est une PWA web : les principes et fondamentaux s'appliquent (accessibilité, couleur, typographie, mise en page, rédaction), les conventions iOS comme principes pour l'usage sur téléphone. Les majuscules suivent l'usage français (casse de phrase), appliqué partout : c'est un choix assumé, pas un écart.

**Résumé** : Bien. Thèse : retrouver un objet et son emplacement exact en quelques secondes. Élément signature : l'étiquette d'emplacement à filet coloré. Aucun point critique après correction.

| Constat | Référence HIG | Correction | Priorité |
|---|---|---|---|
| Notifications minutées : l'« Annuler » disparaissait après 4 s, les erreurs après 8 s | `accessibility.md › Cognitive` : « Minimize use of time-boxed interface elements » | Erreurs affichées jusqu'à fermeture ; notification annulable 10 s ; pause au survol et au focus | Haute |
| Onglet « Plus » sur mobile | `tab-bars.md` : « Avoid overflow tabs », « Use a tab bar to support navigation, not to provide actions » | Cinq onglets (Historique ajouté) ; l'administration, rare sur téléphone, passe par le menu de la barre supérieure | Haute |
| Panneau glissant avec poignée mais sans geste | `sheets.md` : « Support swiping to dismiss a sheet » | Le panneau suit le doigt depuis l'en-tête et se ferme vers le bas (> 96 px ou geste rapide) | Haute |
| Rouge utilisé à la fois pour les erreurs, la suppression et les sorties de stock | `color.md › Best practices` : « Avoid using the same color to mean different things » | Sorties de stock en neutre (6,4:1), le signe « − » porte l'information ; le rouge ne signifie plus que erreur ou destruction | Moyenne |
| Trois icônes ambiguës par emplacement (déplacer, crayon pour « corriger », cube en doublon du lien de l'étiquette) | `buttons.md` : « Consider using text when a short label communicates more clearly than an icon » | Menu « … » avec « Déplacer… » et « Corriger la quantité… » ; bouton 3D supprimé (l'étiquette y mène déjà) | Moyenne |
| Éléments de menu qui ouvrent une fenêtre sans le signaler | `menus.md` : « Append an ellipsis to a menu item's label when the action requires more information » | Points de suspension sur ces éléments | Basse |
| Libellé « Annuler » ambigu (annuler une saisie ou défaire une action) | `undo-and-redo.md` : « Briefly and precisely describe the operation to be undone » | « Rétablir l'accès », « Annuler le changement » | Basse |
| Pas de réponse au contraste accru ni à la transparence réduite | `accessibility.md › Vision`, `color.md` : variantes « increased contrast » | `prefers-contrast: more` (textes et contours renforcés) ; `prefers-reduced-transparency` (barres opaques) | Moyenne |
| Animation de translation à chaque changement de page | `motion.md` : « generally avoid adding motion to UI interactions that occur frequently » | Simple fondu de 120 ms | Basse |
| Petits boutons à 38 px et contrôles d'arbre à 24 × 32 px sur mobile ; libellés d'onglet en px fixes | `accessibility.md › Mobility` (44 × 44 par défaut) ; `typography.md › Supporting Dynamic Type` | 44 px sur mobile ; libellés en rem (suivent la taille de texte choisie) | Moyenne |
| Clavier virtuel ouvert d'office à l'ouverture d'un panneau | Jugement (portée du pouce, `designing-for-ios.md`) | Focus automatique dans les fenêtres seulement à la souris | Basse |

**Ce qui fonctionne (à conserver)** : apparences claire et sombre suivant le système sans réglage propre à l'application (`dark-mode.md`) ; recherche immédiate avec suggestions (`search-fields.md`) ; avertissement réservé aux suppressions irréversibles, annulation pour le reste (`feedback.md`) ; bouton principal unique, jamais destructif (`buttons.md`) ; contrastes AA calculés sur les valeurs réelles.
