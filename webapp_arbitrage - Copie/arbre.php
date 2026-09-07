<?php
/**
 * arbre.php — Arbre de tournoi (bracket à élimination directe)
 */
require_once __DIR__ . '/config/config.php';

purgerCombatsExpires($pdo);

$rondes      = getArbreCombats($pdo);
$roundsList  = array_values($rondes);
$tourNumbers = array_keys($rondes);
$roundCount  = count($roundsList);

foreach ($roundsList as $t => $matches) {
    usort($matches, fn($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));
    $roundsList[$t] = array_values($matches);
}

$matchH     = 100;  // hauteur d'une carte combat (px)
$gap0       = 32;   // espace vertical entre deux combats au 1er tour
$roundWidth = 240;  // largeur d'une colonne
$roundGap   = 64;   // espace horizontal entre colonnes (zone connecteurs)
$unit       = $matchH + $gap0;

$n0           = $roundCount > 0 ? max(1, count($roundsList[0])) : 0;
$masterHeight = $unit * $n0;
$columnHeight = $masterHeight;
for ($t = 0; $t < $roundCount; $t++) {
    $slot   = $unit * (2 ** $t);
    $needed = $slot * max(1, count($roundsList[$t]));
    $columnHeight = max($columnHeight, $needed);
}

function slotH(int $t, int $unit): float { return $unit * (2 ** $t); }
function centerY(int $t, int $p, int $unit): float { return $p * slotH($t, $unit) + slotH($t, $unit) / 2; }

$pageTitle  = 'Arbre du tournoi';
$extraHead  = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" crossorigin="anonymous">';
require __DIR__ . '/includes/header.php';
?>

<style>
/* ── Structure bracket (Bootstrap ne peut pas gérer les px absolus) ── */
.bk-wrap  { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch;
            padding-block: 1rem 2rem; margin-inline: calc(-1 * var(--content-pad-inline, 1rem));
            padding-inline: var(--content-pad-inline, 1rem); }
.bk-wrap:focus-visible { outline: 2px solid #4af0c8; outline-offset: -2px; border-radius: 8px; }
.bk-inner { display: flex; align-items: flex-start; gap: <?= $roundGap ?>px; position: relative; }
.bk-col   { display: flex; flex-direction: column; flex: none; width: <?= $roundWidth ?>px; }
.bk-body  { position: relative; }

/* Carte combat */
.bk-card {
    position: absolute; left: 0; right: 0;
    border-radius: 10px; overflow: hidden;
    background: rgba(255,255,255,.04);
    border: 1.5px solid rgba(255,255,255,.12);
    transition: border-color .18s, box-shadow .18s, transform .18s;
    text-decoration: none !important; display: flex; flex-direction: column;
}
.bk-card:hover { border-color: rgba(255,255,255,.35); box-shadow: 0 6px 24px rgba(0,0,0,.45); transform: translateY(-1px); }
.bk-card.s-live    { border-left: 3px solid #4af0c8; }
.bk-card.s-done    { border-left: 3px solid rgba(108,117,125,.7); }
.bk-card.s-waiting { border-left: 3px solid rgba(255,255,255,.18); }

/* En-tête statut */
.bk-status {
    padding: 3px 10px;
    font-size: .6rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
    background: rgba(0,0,0,.25); border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: center; gap: 5px;
}
.bk-status.live  { color: #4af0c8; }
.bk-status.done  { color: #6c757d; }
.bk-status.wait  { color: rgba(255,255,255,.35); }

/* Lignes joueurs */
.bk-row {
    flex: 1; display: flex; align-items: center; justify-content: space-between;
    gap: 6px; padding: 0 10px;
    color: rgba(255,255,255,.45); font-size: .82rem; font-weight: 600;
    border-bottom: 1px solid rgba(255,255,255,.05);
    font-family: var(--font-ui, sans-serif);
    min-height: 0;
}
.bk-row:last-child { border-bottom: none; }
.bk-row.winner { color: #fff; background: rgba(74,240,200,.08); }
.bk-row.winner .bk-name::before { content: "▶ "; font-size: .55rem; opacity: .7; vertical-align: middle; }
.bk-name  { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; }
.bk-score { font-family: 'Orbitron', monospace; font-size: .75rem; font-weight: 700; flex: none;
            letter-spacing: .02em; color: rgba(255,255,255,.6); }
.winner .bk-score { color: #4af0c8; }

/* Connecteurs */
.bk-conn {
    position: absolute; left: 100%;
    border-right: 2px solid rgba(255,255,255,.15);
    border-top:   2px solid rgba(255,255,255,.15);
    border-bottom:2px solid rgba(255,255,255,.15);
    border-top-right-radius: 5px;
    border-bottom-right-radius: 5px;
    width: <?= $roundGap / 2 ?>px;
    pointer-events: none;
}
.bk-stub {
    position: absolute; height: 2px;
    background: rgba(255,255,255,.15);
    width: <?= $roundGap / 2 ?>px;
    transform: translateY(-1px);
    pointer-events: none;
}

/* Carte Champion */
.bk-champion {
    position: absolute; left: 0; right: 0;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
    background: linear-gradient(135deg, rgba(255,215,0,.15), rgba(255,165,0,.08));
    border: 2px solid rgba(255,215,0,.5);
    border-radius: 10px;
    box-shadow: 0 0 24px rgba(255,215,0,.2);
    text-align: center; padding: 0 12px;
}
.bk-champion .champ-icon { font-size: 1.6rem; line-height: 1; }
.bk-champion .champ-name { font-family: 'Orbitron', monospace; font-size: .78rem; font-weight: 800;
                            color: gold; letter-spacing: .04em; text-transform: uppercase; }
.bk-champion .champ-tbd  { font-size: .75rem; color: rgba(255,255,255,.3); }

/* Badge tour */
.round-badge {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-size: .62rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    background: rgba(255,255,255,.07); color: rgba(255,255,255,.45);
    border: 1px solid rgba(255,255,255,.1); white-space: nowrap;
}
.round-badge.final { background: rgba(255,215,0,.12); color: gold; border-color: rgba(255,215,0,.3); }
</style>

<!-- ── En-tête page ── -->
<div class="d-flex align-items-center gap-3 mb-4">
    <h1 class="mb-0" style="font-family:'Orbitron',sans-serif; font-size:1.3rem; font-weight:800; color:#fff; letter-spacing:.04em; text-transform:uppercase;">
        <?= icon('bracket') ?> Arbre du tournoi
    </h1>
</div>

<?php if ($roundCount === 0): ?>

    <!-- ── État vide ── -->
    <div class="text-center py-5">
        <div class="mb-3" style="opacity:.3; font-size:3rem;">🎯</div>
        <p class="mb-1" style="color:rgba(255,255,255,.5);">Aucun combat n'est encore placé dans l'arbre.</p>
        <small style="color:rgba(255,255,255,.3);">
            Un administrateur peut renseigner un « tour » et une « position » dans
            <strong style="color:rgba(255,255,255,.5);">Admin → Combats</strong>.
        </small>
    </div>

<?php else:
    $finalOk    = count($roundsList[$roundCount - 1]) === 1;
    $totalWidth = $roundCount * $roundWidth + max(0, $roundCount - 1) * $roundGap + ($finalOk ? $roundWidth + $roundGap : 0);

    // Détection incohérences
    $incoherences = [];
    for ($t = 1; $t < $roundCount; $t++) {
        $attendu = (int)ceil(count($roundsList[$t - 1]) / 2);
        $reel    = count($roundsList[$t]);
        if ($reel !== $attendu) {
            $incoherences[] = "Tour {$tourNumbers[$t]} : {$reel} combat(s) au lieu de {$attendu} attendu(s).";
        }
    }
?>

<?php if ($incoherences): ?>
    <div class="alert d-flex gap-2 mb-4" style="background:rgba(255,193,7,.08); border:1px solid rgba(255,193,7,.3); color:rgba(255,255,255,.8); border-radius:10px;">
        <span style="font-size:1.1rem; flex:none;">⚠️</span>
        <div>
            <strong style="color:#ffc107;">Arbre incohérent</strong> — les connecteurs peuvent être mal alignés :
            <ul class="mb-0 mt-1 ps-3 small" style="color:rgba(255,255,255,.6);">
                <?php foreach ($incoherences as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
            </ul>
            <small style="color:rgba(255,255,255,.4);">Vérifie les champs « Tour » et « Position » dans Admin → Combats.</small>
        </div>
    </div>
<?php endif; ?>

<?php if ($roundCount > 1): ?>
    <p class="small mb-3 d-flex align-items-center gap-2" style="color:rgba(255,255,255,.35);">
        <?= icon('swap') ?> Fais défiler horizontalement pour voir tous les tours.
    </p>
<?php endif; ?>

<!-- ── Bracket scroll ── -->
<div class="bk-wrap" role="region" aria-label="Arbre du tournoi (défilement horizontal)" tabindex="0">
<div class="bk-inner" style="width:<?= $totalWidth ?>px; min-height:<?= $columnHeight + 48 ?>px;">

<?php for ($t = 0; $t < $roundCount; $t++): ?>
    <div class="bk-col">

        <!-- Label tour -->
        <div class="text-center mb-3">
            <span class="round-badge">Tour <?= (int)$tourNumbers[$t] ?></span>
        </div>

        <!-- Corps du tour -->
        <div class="bk-body" style="height:<?= $columnHeight ?>px;">

            <?php foreach ($roundsList[$t] as $p => $combat):
                $y    = centerY($t, $p, $unit) - $matchH / 2;
                $j1W  = $combat['vainqueur_id'] && $combat['vainqueur_id'] == $combat['joueur1_id'];
                $j2W  = $combat['vainqueur_id'] && $combat['vainqueur_id'] == $combat['joueur2_id'];
                $st   = $combat['statut'];
                $cls  = $st === 'en_cours' ? 's-live' : ($st === 'termine' ? 's-done' : 's-waiting');
                $statusCls = $st === 'en_cours' ? 'live' : ($st === 'termine' ? 'done' : 'wait');
                $statusLabel = match($st) {
                    'en_cours' => icon('broadcast', 'text-live') . ' En cours',
                    'termine'  => icon('check-circle') . ' Terminé',
                    default    => icon('history') . ' À venir',
                };
            ?>
                <a href="combat.php?id=<?= (int)$combat['id'] ?>"
                   class="bk-card <?= $cls ?>"
                   style="top:<?= round($y) ?>px; height:<?= $matchH ?>px;"
                   <?= $st === 'en_cours' ? 'data-combat-id="' . (int)$combat['id'] . '"' : '' ?>>

                    <!-- Statut -->
                    <div class="bk-status <?= $statusCls ?>"><?= $statusLabel ?></div>

                    <!-- Joueur 1 -->
                    <div class="bk-row <?= $j1W ? 'winner' : '' ?>">
                        <span class="bk-name"><?= e($combat['joueur1_pseudo']) ?></span>
                        <span class="bk-score js-score1">
                            <?= $st === 'a_venir' ? '–' : number_format((float)$combat['score_joueur1'], 1) ?>
                        </span>
                    </div>

                    <!-- Joueur 2 -->
                    <div class="bk-row <?= $j2W ? 'winner' : '' ?>">
                        <span class="bk-name"><?= e($combat['joueur2_pseudo']) ?></span>
                        <span class="bk-score js-score2">
                            <?= $st === 'a_venir' ? '–' : number_format((float)$combat['score_joueur2'], 1) ?>
                        </span>
                    </div>

                    <?php if ($st === 'en_cours'): ?>
                        <span class="visually-hidden js-live-status" aria-live="polite"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <!-- Connecteurs vers le tour suivant -->
            <?php if ($t + 1 < $roundCount): ?>
                <?php foreach ($roundsList[$t + 1] as $q => $nc):
                    $topY = centerY($t, 2 * $q,     $unit);
                    $botY = centerY($t, 2 * $q + 1, $unit);
                    if (!isset($roundsList[$t][2 * $q + 1])) $botY = $topY;
                    $connH = max(2, $botY - $topY);
                ?>
                    <span class="bk-conn"
                          style="top:<?= round($topY) ?>px; height:<?= round($connH) ?>px;"
                          aria-hidden="true"></span>
                    <span class="bk-stub"
                          style="top:<?= round(centerY($t + 1, $q, $unit)) ?>px; left:<?= $roundWidth ?>px;"
                          aria-hidden="true"></span>
                <?php endforeach; ?>
            <?php endif; ?>

        </div><!-- /.bk-body -->
    </div><!-- /.bk-col -->
<?php endfor; ?>

<!-- ── Colonne Champion ── -->
<?php if ($finalOk):
    $final    = $roundsList[$roundCount - 1][0];
    $champY   = centerY($roundCount - 1, 0, $unit);
    $champName = null;
    if ($final['statut'] === 'termine' && $final['vainqueur_id']) {
        $champName = $final['vainqueur_id'] == $final['joueur1_id']
            ? $final['joueur1_pseudo'] : $final['joueur2_pseudo'];
    }
?>
    <div class="bk-col">
        <div class="text-center mb-3">
            <span class="round-badge final">🏆 Champion</span>
        </div>
        <div class="bk-body" style="height:<?= $columnHeight ?>px;">
            <!-- Stub entrant -->
            <span class="bk-stub"
                  style="top:<?= round($champY) ?>px; left:-<?= $roundGap / 2 ?>px;"
                  aria-hidden="true"></span>
            <!-- Carte champion -->
            <div class="bk-champion" style="top:<?= round($champY - $matchH / 2) ?>px; height:<?= $matchH ?>px;">
                <?php if ($champName): ?>
                    <span class="champ-icon">🏆</span>
                    <span class="champ-name"><?= e($champName) ?></span>
                <?php else: ?>
                    <span class="champ-icon" style="opacity:.35;">❓</span>
                    <span class="champ-tbd">À déterminer</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

</div><!-- /.bk-inner -->
</div><!-- /.bk-wrap -->

<?php endif; ?>

<script src="<?= BASE_URL ?>assets/js/live.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
