<?php
/**
 * includes/ui.php : composants d'interface réutilisables.
 *
 * Un composant = une seule façon d'afficher une information dans toute
 * l'application (tableau de score, badge de statut, état vide, menu d'actions).
 * Les pages appellent ces fonctions au lieu de recopier le HTML.
 */

/** Badge de statut d'un combat (en cours / à venir / terminé / annulé). */
function badgeStatut(string $statut): string
{
    return '<span class="badge badge-' . e($statut) . '">' . e(libelleStatut($statut)) . '</span>';
}

/**
 * Tableau de score Aka (rouge, à gauche) / Ao (bleu, à droite).
 * Options :
 *   live   => true  : classes js-score1 / js-score2 pour la mise à jour en direct
 *   lg     => true  : grande taille (page du combat)
 *   result => true  : combat terminé, le vainqueur est mis en avant
 */
function duelScore(array $c, array $opt = []): string
{
    $live = !empty($opt['live']);
    $j1 = $c['joueur1_pseudo'] ?? 'Joueur 1';
    $j2 = $c['joueur2_pseudo'] ?? 'Joueur 2';
    // Avant le début, aucun score n'est affiché (pas de faux « 0 » ni de tiret coloré)
    $avant = ($c['statut'] ?? '') === 'a_venir';
    $s1 = fmtScore($c['score_joueur1'] ?? 0);
    $s2 = fmtScore($c['score_joueur2'] ?? 0);
    $etat1 = $etat2 = '';
    if (!empty($opt['result']) && ($c['statut'] ?? '') === 'termine' && !empty($c['vainqueur_id'])) {
        $gagne1 = (int)$c['vainqueur_id'] === (int)$c['joueur1_id'];
        $etat1 = $gagne1 ? ' is-winner' : ' is-loser';
        $etat2 = $gagne1 ? ' is-loser' : ' is-winner';
    }
    ob_start(); ?>
    <div class="duel<?= !empty($opt['lg']) ? ' duel-lg' : '' ?><?= $avant ? ' duel-avant' : '' ?>">
        <div class="duel-side duel-aka<?= $etat1 ?>">
            <span class="side-label aka">Aka</span>
            <span class="duel-name" title="<?= e($j1) ?>"><?= e($j1) ?></span>
            <?php if (!$avant): ?><span class="score-big<?= $live ? ' js-score1' : '' ?>" aria-label="Score de <?= e($j1) ?>"><?= $s1 ?></span><?php endif; ?>
        </div>
        <div class="vs-label" aria-hidden="true">contre</div>
        <div class="duel-side duel-ao<?= $etat2 ?>">
            <span class="side-label ao">Ao</span>
            <span class="duel-name" title="<?= e($j2) ?>"><?= e($j2) ?></span>
            <?php if (!$avant): ?><span class="score-big<?= $live ? ' js-score2' : '' ?>" aria-label="Score de <?= e($j2) ?>"><?= $s2 ?></span><?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

/** État vide : ce qui se passe, et ce qui va se passer ou quoi faire. */
function etatVide(string $titre, string $texte = '', string $action = '', string $ic = '', string $classe = ''): string
{
    return '<div class="empty-state ' . e($classe) . '">'
        . ($ic ? icon($ic) : '')
        . '<p>' . e($titre) . '</p>'
        . ($texte !== '' ? '<p>' . e($texte) . '</p>' : '')
        . $action
        . '</div>';
}

/**
 * Menu d'actions secondaires « ⋯ » (modifier, supprimer…).
 * $contenu = liens / formulaires déjà rendus (classe is-danger pour une destruction).
 */
function menuActions(string $contenu, string $libelle = 'Plus d\'actions'): string
{
    if (trim($contenu) === '') return '';
    return '<details class="menu">'
        . '<summary class="btn btn-icon btn-sm" aria-label="' . e($libelle) . '" title="' . e($libelle) . '">' . icon('more') . '</summary>'
        . '<div class="menu-panel" role="menu">' . $contenu . '</div>'
        . '</details>';
}

/** Formulaire POST d'une seule action (dans un menu ou une ligne), protégé CSRF. */
function boutonPost(string $action, string $formAction, array $champs, string $libelle, string $ic = '', array $attrs = []): string
{
    $html = '<form method="post" action="' . e($action) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'
        . '<input type="hidden" name="form_action" value="' . e($formAction) . '">';
    foreach ($champs as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . e($k) . '="' . e((string)$v) . '"';
    return $html . '<button type="submit" role="menuitem"' . $a . '>' . ($ic ? icon($ic) : '') . e($libelle) . '</button></form>';
}

/** Ligne de résultat d'un combat terminé (accueil, page Résultats). */
function ligneResultat(array $c, ?PDO $pdo = null): string
{
    $tour = $c['tour'] !== null ? ($pdo ? nomTour((int)$c['tour'], nombreToursArbre($pdo)) : 'Tour ' . (int)$c['tour']) : '';
    $g1 = !empty($c['vainqueur_id']) && (int)$c['vainqueur_id'] === (int)$c['joueur1_id'];
    $g2 = !empty($c['vainqueur_id']) && (int)$c['vainqueur_id'] === (int)$c['joueur2_id'];
    $pv = (int)($c['points_victoire'] ?? 0);
    $j1 = $c['joueur1_pseudo'];
    $j2 = $c['joueur2_pseudo'];
    $resume = $g1 || $g2
        ? 'Victoire de ' . ($g1 ? $j1 : $j2) . ', ' . fmtScore($c['score_joueur1']) . ' à ' . fmtScore($c['score_joueur2'])
        : 'Match nul, ' . fmtScore($c['score_joueur1']) . ' partout';
    ob_start(); ?>
    <li class="result-row">
        <a class="stretched" href="<?= BASE_URL ?>combat.php?id=<?= (int)$c['id'] ?>"><span class="visually-hidden"><?= e($j1 . ' contre ' . $j2 . ' : ' . $resume) ?></span></a>
        <span class="r-side r-aka<?= $g1 ? ' is-winner' : '' ?>" aria-hidden="true"><span><?= e($j1) ?></span></span>
        <span class="r-score" aria-hidden="true">
            <span class="s1<?= $g2 ? ' lose' : '' ?>"><?= fmtScore($c['score_joueur1']) ?></span>
            <span class="sep"></span>
            <span class="s2<?= $g1 ? ' lose' : '' ?>"><?= fmtScore($c['score_joueur2']) ?></span>
        </span>
        <span class="r-side r-ao<?= $g2 ? ' is-winner' : '' ?>" aria-hidden="true"><span><?= e($j2) ?></span></span>
        <span class="r-meta" aria-hidden="true">
            <strong><?= $g1 || $g2 ? $pv . ' pt' . ($pv > 1 ? 's' : '') . ' de victoire' : 'Match nul' ?></strong>
            <span><?= $c['temps_fin'] ? 'Fin à ' . date('H:i', strtotime($c['temps_fin'])) : '' ?><?= $tour !== '' ? ' · ' . e($tour) : '' ?></span>
        </span>
    </li>
    <?php return ob_get_clean();
}

/** Carte d'un combat en cours (accueil, arbitrage) : entièrement cliquable. */
function carteCombatLive(PDO $pdo, array $c, string $lien, string $cta, string $contexte = ''): string
{
    $reste = tempsRestant($c);
    ob_start(); ?>
    <article class="card card-link combat-tile" data-combat-id="<?= (int)$c['id'] ?>" data-statut="en_cours">
        <div class="tile-top">
            <span class="badge badge-en_cours">En cours</span>
            <?php if ($contexte !== ''): ?><span class="hint"><?= e($contexte) ?></span><?php endif; ?>
            <span class="chip-timer" title="Temps restant"><?= icon('clock') ?><span class="timer js-timer" <?= attrsChrono($c) ?> role="timer" aria-label="Temps restant"><?= gmdate('i:s', $reste) ?></span></span>
        </div>
        <?= duelScore($c, ['live' => true]) ?>
        <?= barreAvantage(etatScore($pdo, $c), $c['joueur1_pseudo'], $c['joueur2_pseudo']) ?>
        <p class="visually-hidden js-live-status" aria-live="polite"></p>
        <a class="stretched tile-foot" href="<?= e($lien) ?>"><span><?= e($cta) ?></span><?= icon('arrow-right') ?></a>
    </article>
    <?php return ob_get_clean();
}

/**
 * Ligne d'un combat à venir (ou en direct) dans une liste .upcoming :
 * l'heure, les deux combattants, le compte à rebours. Accueil, arbitrage, admin.
 * Options :
 *   sous      => HTML sous les noms (rôle de l'arbitre, alerte…), déjà échappé
 *   droite    => HTML de la colonne de droite (remplace le compte à rebours)
 *   retard    => texte affiché quand l'heure prévue est passée
 *   sansHeure => texte de la colonne de droite quand aucun horaire n'est fixé
 */
function ligneAVenir(array $c, string $lien, array $opt = []): string
{
    $live = ($c['statut'] ?? '') === 'en_cours';
    $prevue = !empty($c['date_prevue']) ? strtotime($c['date_prevue']) : null;
    $j1 = $c['joueur1_pseudo'];
    $j2 = $c['joueur2_pseudo'];
    ob_start(); ?>
    <li data-combat-id="<?= (int)$c['id'] ?>" data-statut="<?= e($c['statut']) ?>"<?= $live ? ' class="is-live"' : '' ?>>
        <?php if ($live): ?>
            <span class="when"><span class="badge badge-en_cours">Direct</span></span>
        <?php else: ?>
            <span class="when"><?= $prevue ? date('H:i', $prevue) : '<span class="muted">--:--</span>' ?><small><?= formatDuree((int)$c['duree_secondes']) ?></small></span>
        <?php endif; ?>
        <a class="who" href="<?= e($lien) ?>">
            <span class="who-p aka"><?= e($j1) ?></span><span class="vs">contre</span><span class="who-p ao"><?= e($j2) ?></span>
            <?= $opt['sous'] ?? '' ?>
        </a>
        <?php if (isset($opt['droite'])): ?>
            <span class="eta"><?= $opt['droite'] ?></span>
        <?php elseif ($prevue): ?>
            <span class="eta" data-countdown-to="<?= $prevue * 1000 ?>" data-late-text="<?= e($opt['retard'] ?? 'imminent') ?>"></span>
        <?php else: ?>
            <span class="eta"><?= e($opt['sansHeure'] ?? 'horaire à venir') ?></span>
        <?php endif; ?>
    </li>
    <?php return ob_get_clean();
}

/**
 * Nombre de tours de l'arbre, déduit du 1er tour (4 combats = quarts → 3 tours).
 * Permet de nommer les tours comme le public les connaît.
 */
function nombreToursArbre(PDO $pdo): int
{
    static $n = null;
    if ($n === null) {
        $premier = (int)$pdo->query('SELECT COUNT(*) FROM combats WHERE tour = 1')->fetchColumn();
        $n = $premier > 0 ? (int)ceil(log($premier * 2, 2)) : 0;
    }
    return $n;
}

/** « Finale », « Demi-finales », « Quarts de finale »… ou « Tour N ». */
function nomTour(int $tour, int $nbTours): string
{
    $restants = $nbTours - $tour + 1;
    return match (true) {
        $nbTours < 2 || $restants < 1 => 'Tour ' . $tour,
        $restants === 1 => 'Finale',
        $restants === 2 => 'Demi-finales',
        $restants === 3 => 'Quarts de finale',
        $restants === 4 => 'Huitièmes de finale',
        default => 'Tour ' . $tour,
    };
}

/**
 * Rangs du classement (déjà trié) : ex æquo au même rang (1, 2, 2, 4), aucun
 * rang pour un joueur qui n'a pas encore combattu, et une médaille seulement
 * pour qui a gagné des points de victoire (pas de « bronze » à 0 point).
 * Retourne, dans le même ordre : ['rang' => ?int, 'podium' => 0..3].
 */
function rangsClassement(array $classement): array
{
    $out = [];
    $rang = 0;
    $precedent = null;
    foreach ($classement as $i => $j) {
        if ((int)$j['matchs_joues'] === 0) {
            $out[] = ['rang' => null, 'podium' => 0];
            continue;
        }
        $cle = (int)$j['points_victoire'] . '|' . (int)$j['victoires'] . '|' . (float)$j['points_cumules'];
        if ($cle !== $precedent) $rang = $i + 1;
        $precedent = $cle;
        $out[] = ['rang' => $rang, 'podium' => $rang <= 3 && (int)$j['points_victoire'] > 0 ? $rang : 0];
    }
    return $out;
}

/** Pastille de rang (médaille pour le podium, tiret si pas encore classé). */
function pastilleRang(array $r): string
{
    if ($r['rang'] === null) return '<span class="rank is-unranked" title="Pas encore classé">–</span>';
    return '<span class="rank' . ($r['podium'] ? ' rank-' . $r['podium'] : '') . '">' . $r['rang'] . '</span>';
}

/** Arbitres d'un combat en pastilles (page publique d'un combat à venir). */
function pastillesArbitres(array $arbitres): string
{
    $libelles = ['central' => 'Central', 'coin1' => 'Coin 1', 'coin2' => 'Coin 2'];
    $html = '';
    foreach ($arbitres as $a) {
        $html .= '<span class="arbitre-chip"><span class="avatar avatar-xs" aria-hidden="true">' . e(mb_substr($a['username'], 0, 1)) . '</span>'
            . e($a['username']) . ' <small>' . e($libelles[$a['role']] ?? $a['role']) . '</small></span>';
    }
    return $html;
}
