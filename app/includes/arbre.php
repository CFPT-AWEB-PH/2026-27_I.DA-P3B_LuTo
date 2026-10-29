<?php
/**
 * includes/arbre.php : arbre du tournoi (élimination directe), partagé par
 * la page Arbre (arbre.php) et l'écran de salle (ecran.php).
 *
 * Les tours pas encore créés sont affichés en « à déterminer » : on voit tout
 * de suite le chemin jusqu'à la finale. Les positions sont calculées ici en
 * pixels « taille réelle » ; assets/js/arbre.js agrandit ou réduit ensuite
 * l'ensemble pour qu'il tienne dans l'écran (ou sur la feuille imprimée).
 */

const ARBRE_HAUTEUR_CARTE = 100;
const ARBRE_ECART = 32;

/**
 * Données de l'arbre : une colonne par tour, une case par combat attendu
 * (combat réel, ou null = à déterminer).
 */
function donneesArbre(PDO $pdo): array
{
    $rondes = getArbreCombats($pdo);
    $roundsList = array_values($rondes);
    $tourNumbers = array_keys($rondes);
    $roundCount = count($roundsList);

    foreach ($roundsList as $t => $matches) {
        usort($matches, fn($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));
        $roundsList[$t] = array_values($matches);
    }

    // Nombre de combats attendus à chaque tour, à partir du 1er (4, 2, 1…)
    $n0 = $roundCount > 0 ? max(1, count($roundsList[0])) : 0;
    $attendus = [];
    for ($n = $n0; $n >= 1; $n = (int)ceil($n / 2)) {
        $attendus[] = $n;
        if ($n === 1) break;
    }
    $nbColonnes = max($roundCount, count($attendus));

    $colonnes = [];
    for ($t = 0; $t < $nbColonnes; $t++) {
        $reels = $roundsList[$t] ?? [];
        $taille = max(count($reels), $attendus[$t] ?? 0);
        $cases = array_fill(0, $taille, null);
        $libres = [];
        foreach ($reels as $c) {
            $pos = $c['position'] !== null ? (int)$c['position'] : -1;
            if ($pos >= 0 && $pos < $taille && $cases[$pos] === null) $cases[$pos] = $c;
            else $libres[] = $c;
        }
        foreach ($libres as $c) $cases[array_search(null, $cases, true)] = $c;
        $colonnes[] = $cases;
    }

    // Combats en trop par rapport au tour précédent (saisie manuelle incohérente)
    $incoherences = [];
    for ($t = 1; $t < $roundCount; $t++) {
        $attendu = (int)ceil(count($roundsList[$t - 1]) / 2);
        $reel = count($roundsList[$t]);
        if ($reel > $attendu) {
            $incoherences[] = "Tour {$tourNumbers[$t]} : {$reel} combat(s) au lieu de {$attendu} attendu(s).";
        }
    }

    return [
        'colonnes'     => $colonnes,
        'nbColonnes'   => $nbColonnes,
        'premierTour'  => $tourNumbers[0] ?? 1,
        'incoherences' => $incoherences,
    ];
}

/** Hauteur d'une case au tour $t (elle double à chaque tour). */
function arbreHauteurCase(int $t): float
{
    return (ARBRE_HAUTEUR_CARTE + ARBRE_ECART) * (2 ** $t);
}

/** Centre vertical du combat $p du tour $t. */
function arbreCentre(int $t, int $p): float
{
    return $p * arbreHauteurCase($t) + arbreHauteurCase($t) / 2;
}

/**
 * HTML de l'arbre. Options :
 *   liens => true  : chaque combat mène à sa page (false sur l'écran de salle)
 *   live  => true  : scores mis à jour en direct par live.js
 *   fit   => 'tout' | 'largeur' | 'reel' : ajustement initial (arbre.js)
 *   cadre => 'page' | 'conteneur' : place disponible = fenêtre ou bloc parent
 *   zoomMax => agrandissement maximal (écran de salle : > 1)
 */
function arbreHtml(array $arbre, array $opt = []): string
{
    $liens = $opt['liens'] ?? true;
    $live = $opt['live'] ?? true;
    $colonnes = $arbre['colonnes'];
    $nbColonnes = $arbre['nbColonnes'];
    if ($nbColonnes === 0) return '';

    $matchH = ARBRE_HAUTEUR_CARTE;
    $hauteur = 0;
    foreach ($colonnes as $t => $cases) {
        $hauteur = max($hauteur, arbreHauteurCase($t) * max(1, count($cases)));
    }
    $finalOk = count($colonnes[$nbColonnes - 1]) === 1;

    ob_start(); ?>
<div class="bk-wrap" role="region" aria-label="Arbre du tournoi" tabindex="0"
     data-bk-fit="<?= e($opt['fit'] ?? 'tout') ?>" data-bk-cadre="<?= e($opt['cadre'] ?? 'page') ?>" data-bk-zoom-max="<?= (float)($opt['zoomMax'] ?? 1) ?>">
<div class="bk-inner" style="--bk-n:<?= $nbColonnes + ($finalOk ? 1 : 0) ?>; min-height:<?= $hauteur + 48 ?>px;">

<?php foreach ($colonnes as $t => $cases):
    $nom = nomTour($arbre['premierTour'] + $t, $nbColonnes);
?>
    <div class="bk-col">
        <div class="bk-col-head">
            <span class="round-badge<?= $nom === 'Finale' ? ' final' : '' ?>"><?= e($nom) ?></span>
        </div>

        <div class="bk-body" style="height:<?= $hauteur ?>px;">
            <?php foreach ($cases as $p => $combat):
                $y = arbreCentre($t, $p) - $matchH / 2;
                if ($combat === null): ?>
                <div class="bk-card s-tbd" role="group" style="top:<?= round($y) ?>px; height:<?= $matchH ?>px;" aria-label="<?= e($nom) ?> : combat à déterminer">
                    <div class="bk-status tbd">À déterminer</div>
                    <div class="bk-row"><span class="bk-name">Qualifié du tour précédent</span></div>
                    <div class="bk-row"><span class="bk-name">Qualifié du tour précédent</span></div>
                </div>
            <?php else:
                $j1W = $combat['vainqueur_id'] && $combat['vainqueur_id'] == $combat['joueur1_id'];
                $j2W = $combat['vainqueur_id'] && $combat['vainqueur_id'] == $combat['joueur2_id'];
                $st = $combat['statut'];
                $cls = $st === 'en_cours' ? 's-live' : ($st === 'termine' ? 's-done' : 's-waiting');
                $statusCls = $st === 'en_cours' ? 'live' : ($st === 'termine' ? 'done' : 'wait');
                $statusLabel = match ($st) {
                    'en_cours' => 'En cours',
                    'termine'  => 'Terminé',
                    default    => 'À venir' . ($combat['date_prevue'] ? ' · ' . date('H:i', strtotime($combat['date_prevue'])) : ''),
                };
                $balise = $liens ? 'a' : 'div';
                $attrs = $liens ? ' href="' . BASE_URL . 'combat.php?id=' . (int)$combat['id'] . '"' : '';
                if ($live && $st !== 'termine') $attrs .= ' data-combat-id="' . (int)$combat['id'] . '" data-statut="' . e($st) . '"';
            ?>
                <<?= $balise ?> class="bk-card <?= $cls ?>"<?= $attrs ?> style="top:<?= round($y) ?>px; height:<?= $matchH ?>px;">
                    <div class="bk-status <?= $statusCls ?>"><?= e($statusLabel) ?></div>
                    <div class="bk-row <?= $j1W ? 'winner' : ($j2W ? 'loser' : '') ?>">
                        <span class="bk-name" title="<?= e($combat['joueur1_pseudo']) ?>"><?= e($combat['joueur1_pseudo']) ?></span>
                        <span class="bk-score js-score1"><?= $st === 'a_venir' ? '' : fmtScore($combat['score_joueur1']) ?></span>
                    </div>
                    <div class="bk-row <?= $j2W ? 'winner' : ($j1W ? 'loser' : '') ?>">
                        <span class="bk-name" title="<?= e($combat['joueur2_pseudo']) ?>"><?= e($combat['joueur2_pseudo']) ?></span>
                        <span class="bk-score js-score2"><?= $st === 'a_venir' ? '' : fmtScore($combat['score_joueur2']) ?></span>
                    </div>
                    <?php if ($live && $st === 'en_cours'): ?>
                        <span class="visually-hidden js-live-status" aria-live="polite"></span>
                    <?php endif; ?>
                </<?= $balise ?>>
            <?php endif; endforeach; ?>

            <?php if ($t + 1 < $nbColonnes):
                foreach ($colonnes[$t + 1] as $q => $_):
                    $topY = arbreCentre($t, 2 * $q);
                    $botY = array_key_exists(2 * $q + 1, $cases) ? arbreCentre($t, 2 * $q + 1) : $topY;
            ?>
                    <span class="bk-conn" style="top:<?= round($topY) ?>px; height:<?= round(max(2, $botY - $topY)) ?>px;" aria-hidden="true"></span>
                    <span class="bk-stub" style="top:<?= round(arbreCentre($t + 1, $q)) ?>px;" aria-hidden="true"></span>
            <?php endforeach; endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($finalOk):
    $final = $colonnes[$nbColonnes - 1][0];
    $champY = arbreCentre($nbColonnes - 1, 0);
    $champName = null;
    if ($final && $final['statut'] === 'termine' && $final['vainqueur_id']) {
        $champName = $final['vainqueur_id'] == $final['joueur1_id'] ? $final['joueur1_pseudo'] : $final['joueur2_pseudo'];
    }
?>
    <div class="bk-col">
        <div class="bk-col-head">
            <span class="round-badge final">Champion</span>
        </div>
        <div class="bk-body" style="height:<?= $hauteur ?>px;">
            <span class="bk-stub bk-stub-in" style="top:<?= round($champY) ?>px;" aria-hidden="true"></span>
            <div class="bk-champion<?= $champName ? ' is-crowned' : '' ?>" style="top:<?= round($champY - $matchH / 2) ?>px; height:<?= $matchH ?>px;">
                <?php if ($champName): ?>
                    <span class="champ-icon"><?= icon('trophy') ?>Champion</span>
                    <span class="champ-name"><?= e($champName) ?></span>
                <?php else: ?>
                    <span class="champ-tbd"><?= icon('trophy') ?>À l'issue de la finale</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>
</div>
    <?php return ob_get_clean();
}
