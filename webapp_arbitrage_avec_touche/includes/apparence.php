<?php
/**
 * includes/apparence.php : thèmes de couleurs et logos personnalisés.
 *
 * - Thème : réglage global (paramètre « theme »), appliqué via
 *   <html data-theme="…" data-mode="sombre|clair">. Les couleurs sont des
 *   variables CSS redéfinies par thème (assets/css/style.css, section 20).
 * - Logos : stockés EN BASE (table « logos »), car le code de l'application est
 *   monté en lecture seule dans le conteneur. À l'envoi, l'image est vérifiée
 *   puis RÉ-ENCODÉE en PNG avec GD (supprime tout contenu caché) et déclinée
 *   en toutes les tailles utiles : barre du site, favicon, icône iPhone,
 *   icônes de l'application installée (192 / 512 / maskable).
 *   Le logo actif est le paramètre « logo_actif » (0 = logo d'origine, la lame).
 */

/** Thèmes fournis : clé => [nom, description, mode, couleur de la barre du navigateur, aperçu]. */
const THEMES_FOURNIS = [
    'basique' => ['Basique', 'Sombre, sobre, couleurs de combat rouge / bleu.', 'sombre', '#08090b',
                  ['#0b0d10', '#181c21', '#3ee0b0', '#ff5a5f', '#4c9dff']],
    'clair'   => ['Clair', 'Le thème basique en version blanche.', 'clair', '#ffffff',
                  ['#ffffff', '#f3f4f6', '#0b8a68', '#d62f35', '#1f6fe0']],
    'tournoi' => ['Orange · blanc · bleu', 'Fond blanc, barre bleu marine, accents orange.', 'clair', '#0f2a5c',
                  ['#ffffff', '#0f2a5c', '#e8650a', '#d62f35', '#1f5fd1']],
];

/**
 * Couleurs de base des thèmes fournis (les mêmes que assets/css/style.css) :
 * servent de point de départ à un thème personnalisé et au portail Wi-Fi.
 */
const PALETTES_FOURNIES = [
    'basique' => ['fond' => '#0b0d10', 'surface' => '#121519', 'texte' => '#eceef1', 'primaire' => '#eceef1',
                  'accent' => '#3ee0b0', 'barre' => '#08090b', 'p1' => '#ff5a5f', 'p2' => '#4c9dff'],
    'clair'   => ['fond' => '#f4f5f7', 'surface' => '#ffffff', 'texte' => '#14171c', 'primaire' => '#14171c',
                  'accent' => '#0a7f60', 'barre' => '#ffffff', 'p1' => '#d0313a', 'p2' => '#1f66d1'],
    'tournoi' => ['fond' => '#f3f6fb', 'surface' => '#ffffff', 'texte' => '#0f2140', 'primaire' => '#14387a',
                  'accent' => '#bf4b08', 'barre' => '#0f2a5c', 'p1' => '#d0313a', 'p2' => '#1f5fd1'],
];

/** Couleurs réglables d'un thème personnalisé : clé => [libellé, aide]. */
const COULEURS_THEME = [
    'fond'     => ['Fond de page', 'Derrière les cartes.'],
    'surface'  => ['Cartes', 'Cartes, menus, fenêtres.'],
    'texte'    => ['Texte', 'Titres et texte courant (les nuances plus claires sont calculées).'],
    'primaire' => ['Bouton principal', 'Une action principale par écran.'],
    'accent'   => ['Accent', 'En direct, succès, liens actifs.'],
    'barre'    => ['Barre du haut', 'Barre de navigation et barre du navigateur sur téléphone.'],
    'p1'       => ['Joueur 1 (Aka)', 'À gauche, rouge par défaut.'],
    'p2'       => ['Joueur 2 (Ao)', 'À droite, bleu par défaut.'],
];

function ensureThemesSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS themes_perso (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nom         VARCHAR(40)  NOT NULL,
            mode        VARCHAR(10)  NOT NULL DEFAULT 'sombre',
            couleurs    TEXT         NOT NULL,
            created_by  INT          DEFAULT NULL,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Thèmes personnalisés (Admin › Apparence), clé « perso-ID » =>
 * ['id', 'nom', 'mode', 'couleurs' => [fond, surface, …]]. Une seule lecture par requête.
 */
function themesPerso(PDO $pdo, bool $recharger = false): array
{
    static $cache = null;
    if ($cache !== null && !$recharger) return $cache;
    $cache = [];
    try {
        ensureThemesSchema($pdo);
        foreach ($pdo->query('SELECT id, nom, mode, couleurs FROM themes_perso ORDER BY nom') as $r) {
            $c = normaliserCouleursTheme(json_decode((string)$r['couleurs'], true) ?: []);
            $cache['perso-' . (int)$r['id']] = ['id' => (int)$r['id'], 'nom' => $r['nom'],
                'mode' => $r['mode'] === 'clair' ? 'clair' : 'sombre', 'couleurs' => $c];
        }
    } catch (PDOException $e) {
        // base indisponible : seuls les thèmes fournis restent proposés
    }
    return $cache;
}

/** Garde uniquement des couleurs #rrggbb valides (sinon celles du thème basique). */
function normaliserCouleursTheme(array $c): array
{
    $res = [];
    foreach (PALETTES_FOURNIES['basique'] as $cle => $defaut) {
        $v = strtolower(trim((string)($c[$cle] ?? '')));
        $res[$cle] = preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $defaut;
    }
    return $res;
}

/** Tous les thèmes : clé => [nom, description, mode, couleur de la barre du navigateur, aperçu]. */
function themesDisponibles(?PDO $pdo = null): array
{
    $themes = THEMES_FOURNIS;
    if ($pdo) {
        foreach (themesPerso($pdo) as $cle => $t) {
            $c = $t['couleurs'];
            $themes[$cle] = [$t['nom'], 'Thème personnalisé.', $t['mode'], $c['barre'],
                             [$c['fond'], $c['barre'], $c['accent'], $c['p1'], $c['p2']]];
        }
    }
    return $themes;
}

function themeActif(PDO $pdo): string
{
    $t = getParametre($pdo, 'theme', 'basique');
    return array_key_exists($t, themesDisponibles($pdo)) ? $t : 'basique';
}

/** Attributs de la balise <html> pour le thème actif. */
function attributsTheme(PDO $pdo): string
{
    $t = themeActif($pdo);
    return 'data-theme="' . e($t) . '" data-mode="' . e(themesDisponibles($pdo)[$t][2]) . '"';
}

function couleurBarreNavigateur(PDO $pdo): string
{
    return themesDisponibles($pdo)[themeActif($pdo)][3];
}

/* ------------------------------------------------------------------ */
/* Calcul des couleurs d'un thème personnalisé                         */
/* ------------------------------------------------------------------ */

function hexVersRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rgbVersHex(array $c): string
{
    return sprintf('#%02x%02x%02x', ...array_map(fn($v) => max(0, min(255, (int)round($v))), $c));
}

/** Mélange $a et $b : $t = 0 → $a, $t = 1 → $b. */
function melangeCouleurs(string $a, string $b, float $t): string
{
    $x = hexVersRgb($a); $y = hexVersRgb($b);
    return rgbVersHex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
}

/** Luminance relative WCAG (0 = noir, 1 = blanc). */
function luminanceCouleur(string $hex): float
{
    $l = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, hexVersRgb($hex));
    return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
}

function contrasteCouleurs(string $a, string $b): float
{
    $la = luminanceCouleur($a); $lb = luminanceCouleur($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Texte lisible posé sur $fond : presque noir ou blanc. */
function texteSurCouleur(string $fond): string
{
    return contrasteCouleurs($fond, '#ffffff') >= contrasteCouleurs($fond, '#0b0d10') ? '#ffffff' : '#0b0d10';
}

/**
 * Nuance de $texte rapprochée de $fond autant que possible ($t maximum)
 * tout en gardant un contraste d'au moins $min (texte secondaire lisible).
 */
function nuanceLisible(string $texte, string $fond, float $t, float $min = 4.6): string
{
    for (; $t > 0; $t -= 0.02) {
        $c = melangeCouleurs($texte, $fond, $t);
        if (contrasteCouleurs($c, $fond) >= $min) return $c;
    }
    return $texte;
}

/** rgba() d'une couleur #rrggbb. */
function rgbaCouleur(string $hex, float $alpha): string
{
    [$r, $g, $b] = hexVersRgb($hex);
    return "rgba($r, $g, $b, $alpha)";
}

/**
 * Toutes les variables CSS d'un thème personnalisé, calculées à partir de ses
 * 8 couleurs de base (mêmes noms que la section 02 de style.css).
 */
function variablesThemePerso(array $c, string $mode): array
{
    $sombre = $mode !== 'clair';
    $fond = $c['fond']; $surf = $c['surface']; $txt = $c['texte'];
    // Variante survol : plus claire sur fond sombre, plus foncée sur fond clair
    $survol = fn(string $x) => $sombre ? melangeCouleurs($x, '#ffffff', 0.18) : melangeCouleurs($x, '#000000', 0.14);
    $v = [
        'color-scheme' => $sombre ? 'dark' : 'light',
        '--bg' => $fond,
        '--bg-deep' => $sombre ? melangeCouleurs($fond, '#000000', 0.25) : melangeCouleurs($fond, '#ffffff', 0.6),
        '--surface' => $surf,
        '--surface-2' => melangeCouleurs($surf, $txt, 0.04),
        '--surface-3' => melangeCouleurs($surf, $txt, 0.08),
        '--surface-hover' => melangeCouleurs($surf, $txt, 0.03),
        '--overlay' => $sombre ? 'rgba(5, 6, 8, .72)' : rgbaCouleur(melangeCouleurs($txt, '#000000', 0.3), 0.45),
        '--border-subtle' => melangeCouleurs($surf, $txt, 0.07),
        '--border' => melangeCouleurs($surf, $txt, 0.12),
        '--border-mid' => melangeCouleurs($surf, $txt, 0.17),
        '--border-strong' => melangeCouleurs($surf, $txt, 0.26),
        '--text' => $txt,
        '--text-2' => nuanceLisible($txt, $surf, 0.3, 6),
        '--text-3' => nuanceLisible($txt, $surf, 0.45, 4.6),
        '--primary' => $c['primaire'],
        '--primary-hover' => $survol($c['primaire']),
        '--primary-press' => melangeCouleurs($c['primaire'], $surf, 0.15),
        '--on-primary' => texteSurCouleur($c['primaire']),
        '--accent' => $c['accent'],
        '--accent-hover' => $survol($c['accent']),
        '--accent-dim' => melangeCouleurs($surf, $c['accent'], $sombre ? 0.14 : 0.1),
        '--accent-line' => melangeCouleurs($surf, $c['accent'], 0.38),
        '--on-accent' => texteSurCouleur($c['accent']),
    ];
    foreach (['p1', 'p2'] as $p) {
        $v["--$p"] = $c[$p];
        $v["--$p-hover"] = $survol($c[$p]);
        $v["--$p-soft"] = $sombre ? melangeCouleurs($c[$p], '#ffffff', 0.35) : melangeCouleurs($c[$p], '#000000', 0.15);
        $v["--$p-dim"] = melangeCouleurs($surf, $c[$p], $sombre ? 0.14 : 0.1);
        $v["--$p-line"] = melangeCouleurs($surf, $c[$p], 0.38);
        $v["--on-$p"] = texteSurCouleur($c[$p]);
    }
    if (!$sombre) {
        // États lisibles sur fond clair (les valeurs du thème basique sont faites pour le sombre)
        $v += [
            '--warning' => '#9a6100', '--warning-soft' => '#7a4d00', '--warning-dim' => '#fff4dc', '--warning-line' => '#efd394',
            '--danger' => '#c8323b', '--danger-soft' => '#a1222a', '--danger-dim' => '#fdecee', '--danger-line' => '#f2bfc4',
            '--info' => '#1f66d1', '--info-soft' => '#1854b0', '--info-dim' => '#e8f1fe', '--info-line' => '#b9d3f7',
            '--gold' => '#94700a', '--gold-dim' => '#fbf3d6', '--gold-line' => '#e6d18a',
            '--silver' => '#3a424d', '--silver-dim' => '#eef1f5', '--silver-line' => '#cfd5dd',
            '--bronze' => '#8a4b1a', '--bronze-dim' => '#fbeee2', '--bronze-line' => '#efc9a4',
            '--highlight' => 'inset 0 1px 0 rgba(255, 255, 255, .7)',
            '--shadow-sm' => '0 1px 2px rgba(16, 24, 40, .08)',
            '--shadow-pop' => '0 20px 48px -16px rgba(16, 24, 40, .25), 0 2px 8px rgba(16, 24, 40, .08)',
        ];
    }
    return $v;
}

/** Feuille de style du thème personnalisé actif (vide pour un thème fourni). */
function styleThemePerso(PDO $pdo): string
{
    $cle = themeActif($pdo);
    $t = themesPerso($pdo)[$cle] ?? null;
    if (!$t) return '';
    $c = $t['couleurs'];
    $sel = ':root[data-theme="' . $cle . '"]';
    $decl = fn(array $v) => implode(' ', array_map(fn($k, $x) => "$k: $x;", array_keys($v), $v));

    $css = "$sel { " . $decl(variablesThemePerso($c, $t['mode'])) . " }\n";
    // Barre du haut et bandeau de l'écran de salle : couleur propre, texte lisible dessus
    $barre = $c['barre'];
    $txtBarre = texteSurCouleur($barre);
    $barreSombre = $txtBarre === '#ffffff';
    $accentBarre = contrasteCouleurs($c['accent'], $barre) >= 3 ? $c['accent'] : $txtBarre;
    $varsBarre = [
        '--bg-deep' => $barre,
        '--surface-2' => rgbaCouleur($txtBarre, .10), '--surface-3' => rgbaCouleur($txtBarre, .16),
        '--border-subtle' => $barre, '--border' => rgbaCouleur($txtBarre, .18), '--border-strong' => rgbaCouleur($txtBarre, .32),
        '--text' => $txtBarre,
        '--text-2' => nuanceLisible($txtBarre, $barre, 0.25, 6),
        '--text-3' => nuanceLisible($txtBarre, $barre, 0.4, 4.6),
        '--accent' => $accentBarre,
    ];
    $css .= "$sel .topbar { " . $decl($varsBarre) . " }\n";
    // Les menus déroulants de la barre gardent les couleurs des cartes
    $css .= "$sel .topbar .menu-panel { " . $decl(array_intersect_key(variablesThemePerso($c, $t['mode']),
        array_flip(['--bg-deep', '--surface-2', '--surface-3', '--border-subtle', '--border', '--border-strong', '--text', '--text-2', '--text-3', '--accent']))) . " }\n";
    $css .= "$sel .ec-top { background: $barre; color: $txtBarre; border-bottom-color: $barre; " . $decl(array_intersect_key($varsBarre,
        array_flip(['--text', '--text-2', '--text-3', '--accent']))) . " }\n";
    if ($barreSombre !== ($t['mode'] !== 'clair')) {
        $css .= "$sel .topbar { color-scheme: " . ($barreSombre ? 'dark' : 'light') . "; }\n";
    }
    return '<style id="theme-perso">' . $css . '</style>';
}

/**
 * Couleurs principales du thème actif, pour les pages hors de l'application
 * (portail de connexion du Wi-Fi) : toutes au format #rrggbb.
 */
function paletteThemeActif(PDO $pdo): array
{
    $cle = themeActif($pdo);
    $perso = themesPerso($pdo)[$cle] ?? null;
    if ($perso) {
        $c = $perso['couleurs'];
        $mode = $perso['mode'];
        $v = variablesThemePerso($c, $mode);
    } else {
        $c = PALETTES_FOURNIES[$cle];
        $mode = THEMES_FOURNIS[$cle][2];
        $v = variablesThemePerso($c, $mode);
        if ($cle === 'basique') {   // valeurs exactes de style.css
            $v = array_merge($v, ['--surface-2' => '#181c21', '--border' => '#252b33', '--text-2' => '#a7afba', '--text-3' => '#848d99', '--on-primary' => '#0b0d10']);
        }
    }
    return [
        'mode' => $mode, 'fond' => $c['fond'], 'surface' => $c['surface'], 'surface2' => $v['--surface-2'],
        'bordure' => $v['--border'], 'texte' => $c['texte'], 'texte2' => $v['--text-2'], 'texte3' => $v['--text-3'],
        'primaire' => $c['primaire'], 'surPrimaire' => $v['--on-primary'], 'accent' => $c['accent'],
        'barre' => $c['barre'], 'danger' => $mode === 'clair' ? '#c8323b' : '#ff6b6b',
    ];
}

/** Nom court (15 caractères au plus) : sous l'icône de l'app installée. */
function nomCourtApplication(string $nom): string
{
    if ($nom === 'Sabre Laser Arbitrage') return 'Sabre Arbitrage';
    if (mb_strlen($nom) <= 15) return $nom;
    $court = '';
    foreach (preg_split('/\s+/u', $nom) as $mot) {        // mots entiers tant que ça tient
        $essai = ltrim($court . ' ' . $mot);
        if (mb_strlen($essai) > 15) break;
        $court = $essai;
    }
    return $court !== '' ? $court : mb_substr($nom, 0, 15);
}

/* ------------------------------------------------------------------ */
/* Logos                                                               */
/* ------------------------------------------------------------------ */

/** Formats générés à l'envoi : clé => colonne. */
const LOGO_FORMATS = ['marque', 'f32', 'f180', 'f192', 'f512', 'maskable'];

function ensureLogosSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS logos (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nom         VARCHAR(60)  NOT NULL,
            largeur     INT          NOT NULL DEFAULT 0,
            hauteur     INT          NOT NULL DEFAULT 0,
            marque      MEDIUMBLOB   NOT NULL,
            f32         MEDIUMBLOB   NOT NULL,
            f180        MEDIUMBLOB   NOT NULL,
            f192        MEDIUMBLOB   NOT NULL,
            f512        MEDIUMBLOB   NOT NULL,
            maskable    MEDIUMBLOB   NOT NULL,
            created_by  INT          DEFAULT NULL,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Identifiant du logo actif (0 = logo d'origine). Vérifié en base une fois par requête. */
function logoActifId(PDO $pdo): int
{
    static $id = null;
    if ($id !== null) return $id;
    $id = getParametreInt($pdo, 'logo_actif', 0);
    if ($id > 0) {
        try {
            $st = $pdo->prepare('SELECT 1 FROM logos WHERE id = ?');
            $st->execute([$id]);
            if (!$st->fetch()) $id = 0;
        } catch (PDOException $e) {
            $id = 0;   // table pas encore créée
        }
    }
    return $id;
}

/** URL d'une déclinaison du logo actif (null = logo d'origine). */
function urlLogo(PDO $pdo, string $format): ?string
{
    $id = logoActifId($pdo);
    return $id ? BASE_URL . 'logo.php?id=' . $id . '&f=' . $format : null;
}

/**
 * Marque affichée (barre du site, pied de page, connexion, écran de salle) :
 * le logo personnalisé s'il y en a un, sinon la lame d'origine.
 */
function marqueHtml(PDO $pdo, string $classe = ''): string
{
    $url = urlLogo($pdo, 'marque');
    if ($url) {
        return '<img class="brand-logo ' . e($classe) . '" src="' . e($url) . '" alt="" aria-hidden="true">';
    }
    return '<span class="brand-blade ' . e($classe) . '" aria-hidden="true"></span>';
}

/** Balises <head> : favicon, icône iPhone, manifeste de l'app, couleur de la barre. */
function balisesIcones(PDO $pdo): string
{
    $id = logoActifId($pdo);
    $theme = themeActif($pdo);
    if ($id) {
        $icone = '<link rel="icon" type="image/png" sizes="32x32" href="' . e(urlLogo($pdo, 'f32')) . '">'
               . '<link rel="apple-touch-icon" href="' . e(urlLogo($pdo, 'f180')) . '">';
    } else {
        $icone = '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 32 32\'%3E%3Crect width=\'32\' height=\'32\' rx=\'7\' fill=\'%230b0d10\'/%3E%3Crect x=\'6\' y=\'14.5\' width=\'5\' height=\'4\' rx=\'1\' fill=\'%23848d99\'/%3E%3Crect x=\'12\' y=\'15\' width=\'15\' height=\'3\' rx=\'1.5\' fill=\'%233ee0b0\'/%3E%3C/svg%3E">'
               . '<link rel="apple-touch-icon" href="' . BASE_URL . 'assets/pwa/apple-touch-icon.png">';
    }
    // La version dans l'URL force la mise à jour de l'icône de l'app installée
    $barre = couleurBarreNavigateur($pdo);
    return $icone
        . '<link rel="manifest" href="' . BASE_URL . 'manifest.php?v=' . $id . '-' . e($theme) . '-' . substr($barre, 1) . '">'
        . '<meta name="theme-color" content="' . e($barre) . '">'
        . styleThemePerso($pdo);
}

/* ------------------------------------------------------------------ */
/* Traitement d'une image envoyée                                      */
/* ------------------------------------------------------------------ */

/** Encode une image GD en PNG (chaîne binaire). */
function gdVersPng(GdImage $img): string
{
    ob_start();
    imagepng($img, null, 9);
    return (string)ob_get_clean();
}

/** Toile transparente (ou colorée) de $l × $h pixels. */
function gdToile(int $l, int $h, ?array $fond = null): GdImage
{
    $t = imagecreatetruecolor($l, $h);
    imagealphablending($t, false);
    imagesavealpha($t, true);
    $c = $fond ? imagecolorallocatealpha($t, $fond[0], $fond[1], $fond[2], 0)
               : imagecolorallocatealpha($t, 0, 0, 0, 127);
    imagefilledrectangle($t, 0, 0, $l - 1, $h - 1, $c);
    imagealphablending($t, true);
    return $t;
}

/** Logo centré dans un carré de $taille px, avec une marge (fraction) et un fond optionnel. */
function gdCarre(GdImage $src, int $taille, float $marge = 0.0, ?array $fond = null): string
{
    $sl = imagesx($src); $sh = imagesy($src);
    $zone = (int)round($taille * (1 - 2 * $marge));
    $r = min($zone / $sl, $zone / $sh);
    $l = max(1, (int)round($sl * $r)); $h = max(1, (int)round($sh * $r));
    $t = gdToile($taille, $taille, $fond);
    imagecopyresampled($t, $src, intdiv($taille - $l, 2), intdiv($taille - $h, 2), 0, 0, $l, $h, $sl, $sh);
    return gdVersPng($t);
}

/** Logo pour la barre du site : 96 px de haut maximum, proportions conservées. */
function gdMarque(GdImage $src): string
{
    $sl = imagesx($src); $sh = imagesy($src);
    $r = min(96 / $sh, 480 / $sl, 1.0);
    $l = max(1, (int)round($sl * $r)); $h = max(1, (int)round($sh * $r));
    $t = gdToile($l, $h);
    imagecopyresampled($t, $src, 0, 0, 0, 0, $l, $h, $sl, $sh);
    return gdVersPng($t);
}

/**
 * Vérifie une image envoyée ($_FILES[...]) et produit toutes ses déclinaisons.
 * Retourne ['ok' => true, 'formats' => [...], 'l' => .., 'h' => ..] ou ['ok' => false, 'erreur' => '…'].
 */
function preparerLogo(array $fichier): array
{
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'erreur' => 'Le module d\'images (GD) manque : relancez « docker compose up -d --build ».'];
    }
    if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = match ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image trop lourde (2 Mo maximum).',
            UPLOAD_ERR_NO_FILE => 'Choisissez une image.',
            default => 'L\'envoi de l\'image a échoué, réessayez.',
        };
        return ['ok' => false, 'erreur' => $msg];
    }
    if (!is_uploaded_file($fichier['tmp_name']) || filesize($fichier['tmp_name']) > 2 * 1024 * 1024) {
        return ['ok' => false, 'erreur' => 'Image trop lourde (2 Mo maximum).'];
    }
    $info = @getimagesize($fichier['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return ['ok' => false, 'erreur' => 'Format non accepté : PNG, JPEG ou WebP uniquement.'];
    }
    [$l, $h] = $info;
    if ($l < 32 || $h < 32) return ['ok' => false, 'erreur' => 'Image trop petite (32 × 32 pixels minimum).'];
    if ($l > 4000 || $h > 4000) return ['ok' => false, 'erreur' => 'Image trop grande (4000 × 4000 pixels maximum).'];

    $src = @imagecreatefromstring((string)file_get_contents($fichier['tmp_name']));
    if (!$src) return ['ok' => false, 'erreur' => 'Image illisible ou abîmée.'];
    imagealphablending($src, true);
    imagesavealpha($src, true);

    $blanc = [255, 255, 255];
    $formats = [
        'marque'   => gdMarque($src),
        'f32'      => gdCarre($src, 32),
        'f180'     => gdCarre($src, 180, 0.08, $blanc),   // iPhone : fond plein (sinon noir)
        'f192'     => gdCarre($src, 192, 0.04),
        'f512'     => gdCarre($src, 512, 0.04),
        'maskable' => gdCarre($src, 512, 0.18, $blanc),   // Android découpe en rond / goutte
    ];
    return ['ok' => true, 'formats' => $formats, 'l' => $l, 'h' => $h];
}
