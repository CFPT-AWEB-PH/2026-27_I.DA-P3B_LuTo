<?php
/**
 * aide.php : guide d'utilisation, avec recherche.
 * N'affiche que les fiches de ce que le visiteur peut réellement faire
 * (permissions de son rôle, voir includes/guide.php).
 */
require_once __DIR__ . '/config/config.php';

$user = currentUser();
$fiches = guideFichesVisibles($pdo);
$sections = guideSections();

// Fiches regroupées par section (sections vides masquées)
$parSection = [];
foreach ($fiches as $f) $parSection[$f['section']][] = $f;
$sections = array_filter($sections, fn($cle) => !empty($parSection[$cle]), ARRAY_FILTER_USE_KEY);

/** Texte de recherche d'une fiche : titre, résumé, mots-clés et étapes, sans HTML. */
function texteRechercheFiche(array $f): string
{
    $morceaux = [$f['titre'], $f['resume'], $f['mots'] ?? ''];
    foreach (array_merge($f['etapes'] ?? [], $f['astuces'] ?? []) as $t) $morceaux[] = $t;
    foreach ($f['parcours'] ?? [] as [, $t]) $morceaux[] = $t;
    $texte = strip_tags(preg_replace('/\{\w+\}/', ' ', implode(' ', $morceaux)));
    return mb_strtolower(html_entity_decode($texte, ENT_QUOTES, 'UTF-8'));
}

// Raccourcis « Que voulez-vous faire ? » : les tâches les plus utiles pour ce compte
$raccourcisVoulus = ['parcours-tournoi', 'arbitrage-points', 'combats-generer', 'joueurs-importer', 'comptes-creer',
    'suivre-direct', 'se-connecter', 'ecran-salle', 'classement', 'mot-de-passe', 'installer-app'];
$raccourcis = array_slice(array_values(array_filter($raccourcisVoulus, fn($id) => isset($fiches[$id]))), 0, 6);

$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';

$pageTitle = 'Aide et guide';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Aide et guide</h1>
        <p class="sub">
            <?php if ($user): ?>
                Ce guide montre uniquement ce que votre rôle <?= badgeRole($pdo, $user['role']) ?> permet de faire : <strong><?= count($fiches) ?> fiches</strong>.
            <?php else: ?>
                Tout pour suivre le tournoi. Arbitres et organisateurs : <a href="<?= BASE_URL ?>login.php">connectez-vous</a> pour voir le guide de votre rôle.
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-row no-print">
        <button type="button" class="btn btn-secondary" id="guide-imprimer" title="Imprimer tout le guide, ou l'enregistrer en PDF (Ctrl+P)"><?= icon('printer') ?>Imprimer le guide</button>
    </div>
</div>

<?php /* Page de garde et sommaire : seulement à l'impression */ ?>
<div class="print-only guide-garde">
    <p class="guide-garde-app"><?= e($nomApplication) ?></p>
    <h1>Guide d'utilisation</h1>
    <p class="guide-garde-pour">
        <?php if ($user): ?>
            Pour <strong><?= e($user['username']) ?></strong> · rôle <strong><?= e(nomRole($pdo, $user['role'])) ?></strong>
        <?php else: ?>
            Pour les spectateurs (sans compte)
        <?php endif; ?>
    </p>
    <p class="guide-garde-note">Ce guide ne contient que ce que <?= $user ? 'ce rôle' : 'un visiteur sans compte' ?> peut faire : <?= count($fiches) ?> fiches. Édité le <?= date('d/m/Y à H:i') ?>.</p>
    <h2>Sommaire</h2>
    <ol class="guide-garde-sommaire">
        <?php foreach ($sections as $cle => [$titre]): ?>
            <li><strong><?= e($titre) ?></strong>
                <ul><?php foreach ($parSection[$cle] as $f): ?><li><?= e($f['titre']) ?></li><?php endforeach; ?></ul>
            </li>
        <?php endforeach; ?>
    </ol>
</div>

<section class="guide-hero" aria-label="Rechercher dans le guide">
    <label for="guide-q" class="guide-hero-titre">Que voulez-vous faire ?</label>
    <div class="guide-search">
        <?= icon('search') ?>
        <input type="search" id="guide-q" value="<?= e($q) ?>" placeholder="ex. donner des points, importer des joueurs, mot de passe…"
               autocomplete="off" spellcheck="false" aria-describedby="guide-count" aria-controls="guide-fiches">
        <kbd class="guide-search-kbd" aria-hidden="true">/</kbd>
    </div>
    <p class="guide-count" id="guide-count" aria-live="polite"></p>
    <?php if ($raccourcis): ?>
        <div class="guide-raccourcis" aria-label="Fiches les plus utiles">
            <?php foreach ($raccourcis as $id): $f = $fiches[$id]; ?>
                <a class="guide-chip" href="#<?= e($id) ?>"><?= icon(guideSections()[$f['section']][1]) ?><?= e($f['titre']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="guide-layout">
    <nav class="guide-toc" aria-label="Sommaire du guide">
        <p class="guide-toc-titre">Sommaire</p>
        <ul>
            <?php foreach ($sections as $cle => [$titre, $ic]): ?>
                <li>
                    <a href="#section-<?= e($cle) ?>" data-toc-section="<?= e($cle) ?>"><?= icon($ic) ?><span><?= e($titre) ?></span><span class="count"><?= count($parSection[$cle]) ?></span></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="guide-fiches" id="guide-fiches">
        <div class="guide-vide" id="guide-vide" hidden>
            <?= etatVide('Aucune fiche ne correspond', 'Essayez un autre mot (par exemple « points », « joueur », « wifi »), ou parcourez le sommaire.', '<button type="button" class="btn btn-secondary" id="guide-reset">Effacer la recherche</button>', 'search') ?>
        </div>

        <?php foreach ($sections as $cle => [$titre, $ic, $intro]): ?>
        <section class="guide-section" id="section-<?= e($cle) ?>" data-section="<?= e($cle) ?>" aria-labelledby="h-section-<?= e($cle) ?>">
            <header class="guide-section-head">
                <span class="guide-section-ic" aria-hidden="true"><?= icon($ic) ?></span>
                <div>
                    <h2 id="h-section-<?= e($cle) ?>"><?= e($titre) ?></h2>
                    <p><?= e($intro) ?></p>
                </div>
            </header>

            <?php foreach ($parSection[$cle] as $f):
                $capture = !empty($f['capture']) && is_file(__DIR__ . '/assets/guide/' . $f['capture']) ? $f['capture'] : null;
                $voir = array_values(array_filter($f['voir'] ?? [], fn($id) => isset($fiches[$id])));
            ?>
            <details class="card guide-fiche" id="<?= e($f['id']) ?>" data-search="<?= e(texteRechercheFiche($f)) ?>">
                <summary>
                    <span class="guide-fiche-tete">
                        <span class="guide-fiche-titre"><?= e($f['titre']) ?></span>
                        <span class="guide-fiche-resume"><?= e($f['resume']) ?></span>
                    </span>
                    <?php if ($capture): ?><span class="guide-fiche-tag" title="Avec capture d'écran"><?= icon('eye') ?></span><?php endif; ?>
                    <span class="summary-arrow" aria-hidden="true"><?= icon('chevron-down') ?></span>
                </summary>

                <div class="guide-fiche-corps<?= $capture ? ' avec-capture' : '' ?>">
                    <?php if ($capture): ?>
                        <figure class="guide-capture">
                            <button type="button" class="guide-capture-btn" data-zoom="<?= e(asset('guide/' . $capture)) ?>" data-zoom-alt="<?= e($f['legende'] ?? $f['titre']) ?>" aria-label="Agrandir la capture : <?= e($f['legende'] ?? $f['titre']) ?>">
                                <img src="<?= e(asset('guide/' . $capture)) ?>" alt="<?= e($f['legende'] ?? $f['titre']) ?>" loading="lazy" decoding="async">
                                <span class="guide-capture-zoom" aria-hidden="true"><?= icon('maximize') ?></span>
                            </button>
                            <?php if (!empty($f['legende'])): ?><figcaption><?= e($f['legende']) ?> Les repères rouges correspondent à ceux des étapes.</figcaption><?php endif; ?>
                        </figure>
                    <?php endif; ?>

                    <div class="guide-texte">
                        <?php if (!empty($f['etapes'])): ?>
                            <ol class="guide-etapes">
                                <?php foreach ($f['etapes'] as $etape): ?><li><?= guideTexte($etape) ?></li><?php endforeach; ?>
                            </ol>
                        <?php endif; ?>

                        <?php if (!empty($f['parcours'])):
                            $etapesParcours = array_values(array_filter($f['parcours'], fn($p) => isset($fiches[$p[0]])));
                        ?>
                            <p class="hint">Cochez chaque étape une fois faite (mémorisé sur cet appareil). Seules les étapes autorisées pour votre rôle sont listées.</p>
                            <ol class="guide-parcours" data-parcours="<?= e($f['id']) ?>">
                                <?php foreach ($etapesParcours as $i => [$id, $texte]): ?>
                                    <li>
                                        <label class="guide-parcours-check">
                                            <input type="checkbox" data-etape="<?= e($id) ?>">
                                            <span class="visually-hidden">Étape <?= $i + 1 ?> faite</span>
                                        </label>
                                        <span class="guide-parcours-texte"><?= e($texte) ?></span>
                                        <a class="link-more" href="#<?= e($id) ?>"><?= e($fiches[$id]['titre']) ?> <?= icon('chevron-right') ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <p class="guide-parcours-bilan js-parcours-bilan" aria-live="polite"></p>
                        <?php endif; ?>

                        <?php if (!empty($f['astuces'])): ?>
                            <div class="guide-astuces">
                                <?= icon('info') ?>
                                <ul><?php foreach ($f['astuces'] as $a): ?><li><?= guideTexte($a) ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>

                        <div class="guide-fiche-pied">
                            <?php if (!empty($f['page'])): ?>
                                <a class="btn btn-sm" href="<?= e(BASE_URL . $f['page']) ?>">Ouvrir la page <?= icon('arrow-right') ?></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-ghost" data-copier-lien="<?= e($f['id']) ?>"><?= icon('link') ?>Copier le lien</button>
                        </div>

                        <?php if ($voir): ?>
                            <p class="guide-voir"><span>Voir aussi :</span>
                                <?php foreach ($voir as $id): ?><a href="#<?= e($id) ?>"><?= e($fiches[$id]['titre']) ?></a><?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>
</div>

<dialog class="guide-lightbox" id="guide-lightbox" aria-label="Capture d'écran agrandie">
    <button type="button" class="guide-lightbox-close" aria-label="Fermer"><?= icon('x') ?></button>
    <img alt="">
    <p class="guide-lightbox-legende"></p>
</dialog>

<?= scriptJs('aide.js') ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
