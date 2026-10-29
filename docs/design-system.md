# Design system — Sabre Laser Arbitrage (v6)

Direction : **tableau d'arbitrage broadcast**. Sombre, précis, sobre. Le premium vient
de la cohérence (espacements, typographie, états), pas de la décoration.

## Règles d'or

1. **La couleur porte du sens, jamais de la décoration.**
   rouge = Aka (joueur 1, à gauche) · bleu = Ao (joueur 2, à droite) ·
   menthe = en direct / succès · ambre = attention / retard · rouge vif = destruction ·
   or / argent / bronze = podium (seulement avec des points de victoire).
2. **Une seule action principale par écran**, en blanc (`.btn`). Le reste en
   secondaire (`.btn-secondary`) ou dans un menu « ⋯ » (`menuActions()`).
3. **Chaque action a un retour** : bouton en chargement, toast de succès/erreur,
   confirmation pour toute destruction (`data-confirm`). Sur le pupitre, le chiffre
   qui bouge à chaque appui est celui de l'arbitre (jamais un chiffre qui peut rester figé).
4. **On n'affiche pas une information fausse ou vide** : pas de score avant le début
   d'un combat, pas de médaille à 0 point, pas de tableau de zéros, pas de « mène »
   une fois le combat fini. Chaque liste a un état vide qui dit quoi attendre (`etatVide()`).
5. **Rien n'est coupé « … » quand c'est l'information principale** : les noms passent
   sur deux lignes (duel sur téléphone, écran de salle).
6. **Le mouvement explique un changement d'état**, il ne décore pas. Tout est coupé
   par `prefers-reduced-motion`.

## Tokens (assets/css/style.css, section 02)

| Famille | Tokens |
|---|---|
| Surfaces | `--bg` → `--surface` → `--surface-2` → `--surface-3` |
| Traits | `--border-subtle`, `--border`, `--border-mid`, `--border-strong` |
| Texte | `--text` (titres), `--text-2` (courant), `--text-3` (légendes, ≥ 4.6:1) |
| Action | `--primary` / `--on-primary` (blanc), `--accent` (menthe) |
| Combattants | `--p1*` (Aka), `--p2*` (Ao) : `-dim`, `-line`, `-soft` |
| États | `--success`, `--warning`, `--danger`, `--info` (+ `-dim`, `-line`) |
| Podium | `--gold*`, `--silver*`, `--bronze*` (redéfinis par chaque thème) |
| Espacement | `--space-1` 4 px … `--space-8` 64 px (pas de 4) |
| Rayons | badges 4 · contrôles 8 · cartes 12 · modales 16 |
| Mouvement | `--dur-1` 120 ms (survol) · `--dur-2` 200 ms (état) · `--dur-3` 320 ms (entrée) · `--ease-out` |

Typographie : **Barlow** (texte, UI) et **Barlow Condensed** (titres, chiffres, chronos),
embarquées dans `assets/fonts/` pour fonctionner sans internet.
Échelle : `--fs-2xs` 11 (capitales) · `xs` 12 · `sm` 14 · `base` 16 · `md` 18 · `lg` 22 · `xl` 28 · `2xl` 36.

Les thèmes (Admin › Apparence) ne font que redéfinir ces tokens : un composant
n'écrit jamais une couleur en dur.

## Composants PHP (includes/ui.php)

| Fonction | Rôle |
|---|---|
| `duelScore($c, ['live','lg','result'])` | Tableau de score Aka / Ao (sans score avant le début) |
| `carteCombatLive($pdo, $c, $lien, $cta)` | Carte d'un combat en cours, entièrement cliquable |
| `ligneAVenir($c, $lien, $opt)` | Ligne d'un combat à venir / en direct (accueil, arbitrage, admin) |
| `ligneResultat($c, $pdo)` | Ligne d'un combat terminé (vainqueur mis en avant) |
| `nomTour($tour, $nbTours)` / `nombreToursArbre($pdo)` | « Quarts de finale », « Demi-finales », « Finale » |
| `rangsClassement($classement)` / `pastilleRang($r)` | Rangs ex æquo, médailles, « pas encore classé » |
| `pastillesArbitres($arbitres)` | Arbitres d'un combat en pastilles |
| `badgeStatut($statut)` | Badge en cours / à venir / terminé |
| `etatVide($titre, $texte, $action, $icone)` | État vide |
| `menuActions($html)` | Menu « ⋯ » des actions secondaires |
| `boutonPost(...)` | Formulaire POST protégé CSRF pour une action de menu |
| `asset($chemin)` / `scriptJs($fichier)` | URL versionnée / balise script (une seule `ASSETS_VERSION`) |

## Comportements JS (assets/js/app.js)

- `window.Toast.success|error|warning|info(msg)` ; `flash($type, $msg, $persistant)` côté PHP.
- `window.Modal.show({message, text, confirmLabel, type})` → Promise<bool>.
- `data-confirm="Question ?" data-confirm-text="Conséquence." data-confirm-label="Verbe"` sur un lien ou bouton.
- Validation : `required`, `minlength`, `pattern` (+ `data-pattern-msg`), `min/max`, `data-match="id"`.
- `form[data-autosubmit]` (interrupteurs), `form[data-no-busy]` (téléchargements),
  `input[data-filter="#tbody"]` (recherche instantanée, raccourci « / »).
- `window.ConnStatus` : un seul indicateur « Connexion perdue » pour toute la page.
- `window.bumpScore(el)` : impulsion visuelle quand un score change.
- Pupitre (`scoring.js`) : « +N » qui s'envole du bouton pressé (`.pad-float`).

## Écrans

- Public : Direct (`index.php`), Arbre (tous les tours jusqu'à la finale, « à déterminer »
  pour ceux qui n'existent pas encore), Résultats (`scores.php`), Classement, Écran de salle
  (s'adapte à la largeur ET à la hauteur : jamais de barre de défilement sur la TV).
- Combat : en-tête de contexte (tour + statut), le tableau de score est la pièce maîtresse ;
  avant le début, l'heure de départ et les arbitres remplacent le score.
- Arbitre : le grand chiffre = mes points (retour immédiat), le score officiel juste dessous.
- Admin : un combat sans ses 3 arbitres propose « Affecter » au lieu d'un « Démarrer » voué à l'échec.
- Mobile : barre de navigation basse (5e onglet : Admin, sinon Arbitrage), modales en feuille
  basse, tableaux en cartes (`.table-cards` + `data-label`), filtres sur une ligne qui défile,
  cibles tactiles ≥ 44 px.
