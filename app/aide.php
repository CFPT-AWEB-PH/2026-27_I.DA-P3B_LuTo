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
$peutChangerImages = can('parametres.modifier');

// ---- IMAGE D'UNE FICHE : envoyer, retirer, ou revenir à la capture d'origine ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Version des captures, pour tout le monde : noir ou blanc
    if (isset($_POST['images_mode'])) {
        if (!$peutChangerImages) redirect('aide.php');
        if (!checkCsrf($_POST['csrf'] ?? null)) {
            flash('error', 'Session expirée, merci de réessayer.');
        } else {
            $modeChoisi = $_POST['images_mode'] === 'noir' ? 'noir' : 'blanc';
            setParametre($pdo, 'guide_images_mode', $modeChoisi);
            flash('success', 'Images du guide en ' . $modeChoisi . '.');
        }
        redirect('aide.php');
    }
    $ficheId =is_string($_POST['fiche_id'] ?? null) ? $_POST['fiche_id'] : '';
    if (!$peutChangerImages || !isset($fiches[$ficheId])) {
        redirect('aide.php');
    }
    if (!checkCsrf($_POST['csrf'] ?? null)) {
        flash('error', 'Session expirée, merci de réessayer.');
    } elseif (($_POST['image_action'] ?? '') === 'origine') {
        $pdo->prepare('DELETE FROM guide_images WHERE fiche_id = ?')->execute([$ficheId]);
        flash('success', 'Image d\'origine remise sur la fiche.');
    } elseif (($_POST['image_action'] ?? '') === 'aucune') {
        $pdo->prepare('REPLACE INTO guide_images (fiche_id, image, version) VALUES (?, NULL, NULL)')->execute([$ficheId]);
        flash('success', 'Image retirée de la fiche.');
    } else {
        $res = preparerImageJpeg($_FILES['image'] ?? [], 1920, 2400);
        if ($res['ok']) {
            $pdo->prepare('REPLACE INTO guide_images (fiche_id, image, version) VALUES (?, ?, ?)')
                ->execute([$ficheId, $res['image'], substr(hash('sha256', $res['image']), 0, 16)]);
            flash('success', 'Image enregistrée sur la fiche.');
        } else {
            flash('error', 'Image non enregistrée : ' . $res['erreur']);
        }
    }
    redirect('aide.php#' . $ficheId);
}
$imagesChoisies = guideImagesChoisies($pdo);
$imagesMode = getParametre($pdo, 'guide_images_mode', 'blanc') === 'noir' ? 'noir' : 'blanc';

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

// Administrateurs : tous les identifiants et mots de passe (comptes de l'application et Wi-Fi)
$tousComptes = $tousWifi = [];
$monMdp = null;
if ($user) {
    // La page contient au moins le mot de passe du compte connecté : jamais gardée en cache
    header('Cache-Control: no-store');
    $st = $pdo->prepare('SELECT mdp_chiffre FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $monMdp = estCompteSysteme($user['username']) ? MDP_COMPTE_SYSTEME : dechiffrerMotDePasse($st->fetchColumn() ?: null);
}
if (estSuperAdmin()) {
    require_once __DIR__ . '/includes/wifi.php';
    ensureWifiSchema($pdo);
    $tousComptes = $pdo->query('SELECT username, role, actif, mdp_chiffre FROM users ORDER BY username ASC')->fetchAll();
    $tousWifi = $pdo->query('SELECT identifiant, description, actif, mdp_chiffre, ecran_mdp FROM wifi_comptes ORDER BY identifiant ASC')->fetchAll();
}

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
        <?php if ($peutChangerImages): ?>
            <form method="post" action="aide.php" class="btn-row" aria-label="Couleur des images du guide">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <span class="hint">Images :</span>
                <?php foreach (['noir' => 'Noir', 'blanc' => 'Blanc'] as $valeur => $libelle): ?>
                    <button type="submit" name="images_mode" value="<?= $valeur ?>" class="btn <?= $imagesMode === $valeur ? '' : 'btn-secondary' ?>" aria-pressed="<?= $imagesMode === $valeur ? 'true' : 'false' ?>"><?= $libelle ?></button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
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

<?php if ($user): ?>
<section class="card mb-5" aria-labelledby="h-mon-identifiant">
    <h2 id="h-mon-identifiant" class="h-inline"><?= icon('user') ?> Mon identifiant : <code><?= e($user['username']) ?></code></h2>
    <p class="print-only"><strong>Document confidentiel : il contient des mots de passe, ne le laissez pas traîner.</strong></p>
    <p><strong>Mon mot de passe :</strong><?= $monMdp !== null ? '<code>' . e($monMdp) . '</code>' : '<span class="muted">pas encore connu : il s\'affichera après votre prochaine connexion</span>' ?></p>
    <p class="hint">Rôle <?= e(nomRole($pdo, $user['role'])) ?>. Le mot de passe est donné par l'organisation et ne se change pas.</p>
    <?php if (estSuperAdmin()): ?>
    <p><strong>Compte système :</strong> identifiant <code><?= e(COMPTE_SYSTEME) ?></code> · mot de passe <code><?= e(MDP_COMPTE_SYSTEME) ?></code> <span class="hint">(imposé, il ne peut pas être modifié)</span></p>
    <div class="wifi-guide">
        <h3>Tous les identifiants et mots de passe (administrateurs seulement)</h3>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Comptes de l'application</caption>
                <thead><tr><th scope="col">Compte</th><th scope="col">Rôle</th><th scope="col">Mot de passe</th></tr></thead>
                <tbody>
                <?php foreach ($tousComptes as $c): $mdp = estCompteSysteme($c['username']) ? MDP_COMPTE_SYSTEME : dechiffrerMotDePasse($c['mdp_chiffre']); ?>
                    <tr class="<?= $c['actif'] ? '' : 'row-inactive' ?>">
                        <td><code><?= e($c['username']) ?></code></td>
                        <td><?= badgeRole($pdo, $c['role']) ?></td>
                        <td><?= $mdp !== null ? '<code>' . e($mdp) . '</code>' : '<span class="muted">Connu après sa prochaine connexion, ou à générer dans <a href="' . BASE_URL . 'admin/users.php">Comptes</a></span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-wrap">
            <table>
                <caption class="visually-hidden">Accès Wi-Fi</caption>
                <thead><tr><th scope="col">Accès Wi-Fi</th><th scope="col">Description</th><th scope="col">Mot de passe</th></tr></thead>
                <tbody>
                <?php foreach ($tousWifi as $c): $mdp = $c['ecran_mdp'] ?? dechiffrerMotDePasse($c['mdp_chiffre']); ?>
                    <tr class="<?= $c['actif'] ? '' : 'row-inactive' ?>">
                        <td><code><?= e($c['identifiant']) ?></code></td>
                        <td><?= e($c['description'] ?? '') ?></td>
                        <td><?= $mdp !== null ? '<code>' . e($mdp) . '</code>' : '<span class="muted">Inconnu : à générer dans <a href="' . BASE_URL . 'admin/wifi.php">Wi-Fi</a></span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tousWifi): ?><tr><td colspan="3" class="muted">Aucun accès Wi-Fi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

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
                $image = guideImageFiche($f, $imagesChoisies, $imagesMode);
                $voir = array_values(array_filter($f['voir'] ?? [], fn($id) => isset($fiches[$id])));
            ?>
            <details class="card guide-fiche" id="<?= e($f['id']) ?>" data-search="<?= e(texteRechercheFiche($f)) ?>">
                <summary>
                    <span class="guide-fiche-tete">
                        <span class="guide-fiche-titre"><?= e($f['titre']) ?></span>
                        <span class="guide-fiche-resume"><?= e($f['resume']) ?></span>
                    </span>
                    <?php if ($image): ?><img class="guide-fiche-miniature" src="<?= e($image['url']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    <span class="summary-arrow" aria-hidden="true"><?= icon('chevron-down') ?></span>
                </summary>

                <div class="guide-fiche-corps<?= $image ? ' avec-capture' : '' ?>">
                    <?php if ($image): ?>
                        <figure class="guide-capture">
                            <button type="button" class="guide-capture-btn" data-zoom="<?= e($image['url']) ?>" data-zoom-alt="<?= e($f['legende'] ?? $f['titre']) ?>" aria-label="Agrandir l'image : <?= e($f['legende'] ?? $f['titre']) ?>">
                                <img src="<?= e($image['url']) ?>" alt="<?= e($f['legende'] ?? $f['titre']) ?>" loading="lazy" decoding="async">
                                <span class="guide-capture-zoom" aria-hidden="true"><?= icon('maximize') ?></span>
                            </button>
                            <figcaption><?= e($image['legende']) ?></figcaption>
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

                        <?php if ($peutChangerImages): ?>
                            <details class="wifi-guide no-print">
                                <summary>Changer l'image</summary>
                                <form method="post" action="aide.php" enctype="multipart/form-data" novalidate>
                                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="fiche_id" value="<?= e($f['id']) ?>">
                                    <div class="field">
                                        <label for="image-<?= e($f['id']) ?>">Image <span class="req">(PNG, JPEG ou WebP, 2 Mo maximum)</span></label>
                                        <input type="file" id="image-<?= e($f['id']) ?>" name="image" accept="image/png,image/jpeg,image/webp">
                                    </div>
                                    <div class="btn-row">
                                        <button type="submit" class="btn btn-sm"><?= icon('upload') ?>Enregistrer cette image</button>
                                        <?php if ($image): ?><button type="submit" name="image_action" value="aucune" class="btn btn-sm btn-ghost">Aucune image</button><?php endif; ?>
                                        <?php if (array_key_exists($f['id'], $imagesChoisies)): ?><button type="submit" name="image_action" value="origine" class="btn btn-sm btn-ghost">Image d'origine</button><?php endif; ?>
                                    </div>
                                </form>
                            </details>
                        <?php endif; ?>

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
