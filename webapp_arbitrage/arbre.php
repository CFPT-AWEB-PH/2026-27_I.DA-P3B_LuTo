<?php
/**
 * arbre.php — Vue "arbre de tournoi" (bracket à élimination directe)
 *
 * Les combats affichés ici sont ceux auxquels l'admin a renseigné un tour et
 * une position (voir admin/combats.php). La position des matchs et des
 * connecteurs est calculée en pixels côté PHP : chaque tour reçoit une
 * "tranche" verticale deux fois plus grande que le tour précédent, ce qui
 * garantit que le connecteur reliant deux combats aboutit exactement au
 * centre vertical du combat suivant, quelle que soit la taille du tournoi.
 */

require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$rondes = getArbreCombats($pdo);
$roundsList = array_values($rondes);
$tourNumbers = array_keys($rondes);
$roundCount = count($roundsList);

// --- Normalisation : chaque combat reçoit une position 0..n-1 propre au sein
//     de son tour, même si l'admin a laissé le champ vide ou incohérent.
foreach ($roundsList as $t => $matches) {
    usort($matches, function ($a, $b) {
        return ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX);
    });
    $roundsList[$t] = array_values($matches);
}

// --- Constantes de mise en page (en pixels) ---
$matchH     = 96;   // hauteur d'une carte de combat
$gap0       = 30;   // espace vertical entre deux combats du 1er tour affiché
$roundWidth = 240;  // largeur d'une colonne de tour
$roundGap   = 70;   // espace horizontal entre deux colonnes (zone des connecteurs)
$unit       = $matchH + $gap0;

$n0 = $roundCount > 0 ? max(1, count($roundsList[0])) : 0;
$masterHeight = $unit * $n0;

// Hauteur réellement nécessaire pour chaque tour (au cas où un tour aurait
// plus de combats que ce que sa position dans l'arbre "devrait" contenir).
$columnHeight = $masterHeight;
for ($t = 0; $t < $roundCount; $t++) {
    $slot = $unit * (2 ** $t);
    $needed = $slot * max(1, count($roundsList[$t]));
    $columnHeight = max($columnHeight, $needed);
}

function slotHeight(int $t, int $unit): float { return $unit * (2 ** $t); }
function matchCenterY(int $t, int $p, int $unit): float
{
    return $p * slotHeight($t, $unit) + slotHeight($t, $unit) / 2;
}

$pageTitle = 'Arbre du tournoi';
require __DIR__ . '/includes/header.php';
?>

<h1><?= icon('bracket') ?> Arbre du tournoi</h1>

<?php if ($roundCount === 0): ?>
    <div class="empty-state">
        <?= icon('bracket', 'empty-state-icon') ?>
        Aucun combat n'est encore placé dans l'arbre.<br>
        <small class="hint">Un administrateur peut renseigner un « tour » et une « position » sur un combat depuis Admin → Combats pour le faire apparaître ici.</small>
    </div>
<?php else: ?>
    <?php
    $finalOk = $roundCount > 0 && count($roundsList[$roundCount - 1]) === 1;
    $totalWidth = $roundCount * $roundWidth + max(0, $roundCount - 1) * $roundGap + ($finalOk ? $roundWidth + $roundGap : 0);

    // --- Détection d'un arbre incohérent : si un tour n'a pas à peu près la
    //     moitié des combats du tour précédent, les connecteurs peuvent
    //     pointer dans le vide (positions/tours mal renseignés côté admin).
    //     On prévient plutôt que de laisser un rendu visuellement cassé sans
    //     explication.
    $incoherences = [];
    for ($t = 1; $t < $roundCount; $t++) {
        $attendu = (int)ceil(count($roundsList[$t - 1]) / 2);
        $reel = count($roundsList[$t]);
        if ($reel !== $attendu) {
            $incoherences[] = "Tour {$tourNumbers[$t]} : {$reel} combat(s) au lieu de {$attendu} attendu(s) d'après le tour {$tourNumbers[$t - 1]}.";
        }
    }
    ?>
    <?php if ($incoherences): ?>
        <div class="flash flash-error" role="alert" style="margin-bottom: var(--space-4);">
            <?= icon('alert-triangle') ?>
            L'arbre semble incohérent, les connecteurs peuvent être mal alignés :
            <ul style="margin: var(--space-2) 0 0; padding-inline-start: var(--space-5);">
                <?php foreach ($incoherences as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
            </ul>
            <small class="hint">Vérifie les champs « Tour » et « Position » des combats concernés dans Admin → Combats.</small>
        </div>
    <?php endif; ?>
    <?php if ($roundCount > 1): ?>
        <p class="bracket-scroll-hint"><?= icon('swap') ?> Fais défiler horizontalement pour voir tous les tours.</p>
    <?php endif; ?>
    <div class="bracket-scroll" role="region" aria-label="Arbre du tournoi (défilement horizontal)" tabindex="0">
        <div class="bracket" style="width: <?= $totalWidth ?>px;">
            <?php for ($t = 0; $t < $roundCount; $t++): $roundLabelId = 'bracket-round-label-' . $t; ?>
                <div class="bracket-round" style="width: <?= $roundWidth ?>px;" role="group" aria-labelledby="<?= $roundLabelId ?>">
                    <div class="bracket-round-label" id="<?= $roundLabelId ?>">Tour <?= (int)$tourNumbers[$t] ?></div>
                    <div class="bracket-round-body" style="height: <?= $columnHeight ?>px;">
                        <?php foreach ($roundsList[$t] as $p => $combat):
                            $y = matchCenterY($t, $p, $unit) - $matchH / 2;
                            $j1Win = $combat['vainqueur_id'] == $combat['joueur1_id'];
                            $j2Win = $combat['vainqueur_id'] == $combat['joueur2_id'];
                        ?>
                            <div class="bracket-match badge-<?= e($combat['statut']) ?>-border"
                                 style="top: <?= $y ?>px; height: <?= $matchH ?>px;"
                                 <?= $combat['statut'] === 'en_cours' ? 'data-combat-id="' . (int)$combat['id'] . '"' : '' ?>>
                                <a href="combat.php?id=<?= (int)$combat['id'] ?>" class="bracket-match-link">
                                    <span class="bracket-status">
                                        <?php if ($combat['statut'] === 'en_cours'): ?>
                                            <?= icon('broadcast', 'text-live') ?> En cours
                                        <?php elseif ($combat['statut'] === 'termine'): ?>
                                            <?= icon('check-circle') ?> Terminé
                                        <?php else: ?>
                                            <?= icon('history') ?> À venir
                                        <?php endif; ?>
                                    </span>
                                    <span class="bracket-row <?= $j1Win ? 'is-winner' : '' ?>">
                                        <span class="bracket-name"><?= e($combat['joueur1_pseudo']) ?></span>
                                        <span class="bracket-score js-score1"><?= $combat['statut'] === 'a_venir' ? '–' : number_format($combat['score_joueur1'], 1) ?></span>
                                    </span>
                                    <span class="bracket-row <?= $j2Win ? 'is-winner' : '' ?>">
                                        <span class="bracket-name"><?= e($combat['joueur2_pseudo']) ?></span>
                                        <span class="bracket-score js-score2"><?= $combat['statut'] === 'a_venir' ? '–' : number_format($combat['score_joueur2'], 1) ?></span>
                                    </span>
                                    <?php if ($combat['statut'] === 'en_cours'): ?>
                                        <span class="visually-hidden js-live-status" aria-live="polite"></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($t + 1 < $roundCount): ?>
                            <?php foreach ($roundsList[$t + 1] as $q => $nextCombat):
                                $topFeederY = matchCenterY($t, 2 * $q, $unit);
                                $bottomFeederY = matchCenterY($t, 2 * $q + 1, $unit);
                                if (!isset($roundsList[$t][2 * $q + 1])) { $bottomFeederY = $topFeederY; }
                                $connH = max(2, $bottomFeederY - $topFeederY);
                            ?>
                                <span class="bracket-connector"
                                      style="top: <?= $topFeederY ?>px; height: <?= $connH ?>px; width: <?= $roundGap / 2 ?>px;"
                                      aria-hidden="true"></span>
                                <span class="bracket-connector-stub"
                                      style="top: <?= matchCenterY($t + 1, $q, $unit) ?>px; width: <?= $roundGap / 2 ?>px; left: 100%; margin-left: <?= $roundGap / 2 ?>px;"
                                      aria-hidden="true"></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <?php if ($finalOk):
                $final = $roundsList[$roundCount - 1][0];
                $championY = matchCenterY($roundCount - 1, 0, $unit);
            ?>
                <div class="bracket-round" style="width: <?= $roundWidth ?>px;" role="group" aria-labelledby="bracket-champion-label">
                    <div class="bracket-round-label" id="bracket-champion-label"><?= icon('trophy') ?> Champion</div>
                    <div class="bracket-round-body" style="height: <?= $columnHeight ?>px;">
                        <span class="bracket-connector-stub" style="top: <?= $championY ?>px; width: <?= $roundGap / 2 ?>px; left: -<?= $roundGap / 2 ?>px;" aria-hidden="true"></span>
                        <div class="bracket-champion" style="top: <?= $championY - $matchH / 2 ?>px; height: <?= $matchH ?>px;">
                            <?php if ($final['statut'] === 'termine' && $final['vainqueur_id']): ?>
                                <?= icon('trophy') ?>
                                <span><?= $final['vainqueur_id'] == $final['joueur1_id'] ? e($final['joueur1_pseudo']) : e($final['joueur2_pseudo']) ?></span>
                            <?php else: ?>
                                <?= icon('help') ?>
                                <span>À déterminer</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
