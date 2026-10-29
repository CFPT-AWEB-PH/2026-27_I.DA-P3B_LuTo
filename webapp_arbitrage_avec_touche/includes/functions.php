<?php
/**
 * Fonctions utilitaires + logique métier des combats
 */

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Icône SVG inline : référence un symbole du sprite chargé dans includes/icons.php.
 * Remplace toute icône fonte/CDN et tout emoji : un seul jeu d'icônes cohérent,
 * traits 1.5px, grille 24x24. Usage : <?= icon('trophy') ?> ou <?= icon('check', 'icon-sm') ?>.
 */
function icon(string $name, string $class = ''): string
{
    $cls = trim('icon ' . $class);
    return '<svg class="' . e($cls) . '" aria-hidden="true" focusable="false"><use href="#icon-' . e($name) . '"></use></svg>';
}

/**
 * Version unique des fichiers statiques (CSS, JS). La changer ici, puis dans
 * VERSION et PRECACHE de sw.js, force les navigateurs et l'app installée à
 * télécharger les nouveaux fichiers.
 */
const ASSETS_VERSION = '16';

/** URL versionnée d'un fichier de assets/ : asset('js/live.js'). */
function asset(string $chemin): string
{
    return BASE_URL . 'assets/' . ltrim($chemin, '/') . '?v=' . ASSETS_VERSION;
}

/** Balise <script> d'un fichier de assets/js/, avec le nonce CSP. */
function scriptJs(string $fichier): string
{
    return '<script nonce="' . e(cspNonce()) . '" src="' . e(asset('js/' . $fichier)) . '"></script>';
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/**
 * Message affiché en toast à la page suivante. $persistant = true : le toast
 * reste jusqu'à ce que l'utilisateur le ferme (information à recopier, etc.).
 */
function flash(string $type, string $message, bool $persistant = false): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'persistant' => $persistant];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/**
 * Conserve les données d'un formulaire qui a échoué à la validation, pour
 * les réafficher après le redirect (pattern "old input") plutôt que de
 * renvoyer l'utilisateur vers un formulaire vidé de tout ce qu'il avait saisi.
 */
function stashOldInput(array $data): void
{
    $_SESSION['old_input'] = $data;
}

/**
 * Récupère (et consomme) les données précédemment stashées. Usage unique,
 * comme les flashs : un rechargement de page normal ne les revoit pas.
 */
function oldInput(): array
{
    $old = $_SESSION['old_input'] ?? [];
    unset($_SESSION['old_input']);
    return $old;
}

/**
 * Affiche une page d'erreur habillée (même en-tête/pied que le reste du
 * site) plutôt qu'un die() brut hors-charte, puis termine le script.
 */
function renderErrorPage(int $httpCode, string $titre, string $message): never
{
    http_response_code($httpCode);
    global $pdo;
    $pageTitle = $titre;
    require __DIR__ . '/header.php';
    ?>
    <div class="error-page">
        <p class="code"><?= (int)$httpCode ?></p>
        <h1><?= e($titre) ?></h1>
        <p><?= e($message) ?></p>
        <a href="<?= BASE_URL ?>index.php" class="btn">Voir les combats en direct</a>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * Enregistre (ou met à jour) le vote de points d'un arbitre pour la manche en cours d'un combat.
 * Si les 3 arbitres ont voté pour cette manche, calcule la moyenne et fait avancer le combat.
 */
function soumettrePoints(PDO $pdo, int $combatId, int $arbitreUserId, float $pointsJ1, float $pointsJ2, ?int $mancheAttendue = null): array
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat) {
        return ['success' => false, 'error' => 'Combat introuvable.'];
    }
    if ($combat['statut'] !== 'en_cours') {
        return ['success' => false, 'error' => 'Ce combat n\'est pas en cours.'];
    }

    // Vérifie que cet arbitre est bien assigné à ce combat
    $stmt = $pdo->prepare('SELECT role FROM combat_arbitres WHERE combat_id = ? AND user_id = ?');
    $stmt->execute([$combatId, $arbitreUserId]);
    $assignment = $stmt->fetch();
    if (!$assignment) {
        return ['success' => false, 'error' => 'Vous n\'êtes pas arbitre sur ce combat.'];
    }

    $manche = (int)$combat['manche_actuelle'];

    // Le formulaire de vote transporte la manche qu'affichait l'arbitre au
    // moment de la saisie. Si elle diffère de la manche réellement en cours
    // (les autres arbitres ont voté et clos la manche pendant qu'il votait),
    // on refuse d'enregistrer un vote qui serait silencieusement rattaché à
    // la mauvaise manche, et on lui demande de recharger la page.
    if ($mancheAttendue !== null && $mancheAttendue !== $manche) {
        return [
            'success' => false,
            'error' => 'La manche a changé pendant votre saisie (les autres arbitres ont déjà voté). Merci de recharger la page et de revoter pour la manche ' . $manche . '.',
        ];
    }

    $pointsMax = getParametreFloat($pdo, 'points_max', 10);

    $pointsJ1 = max(0, min($pointsMax, $pointsJ1));
    $pointsJ2 = max(0, min($pointsMax, $pointsJ2));

    $stmt = $pdo->prepare(
        'INSERT INTO points (combat_id, manche, arbitre_user_id, joueur1_points, joueur2_points)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE joueur1_points = VALUES(joueur1_points), joueur2_points = VALUES(joueur2_points)'
    );
    $stmt->execute([$combatId, $manche, $arbitreUserId, $pointsJ1, $pointsJ2]);

    verifierEtClorreManche($pdo, $combatId, $manche);
    verifierFinCombat($pdo, $combatId);

    return ['success' => true, 'error' => null];
}

/**
 * Si les 3 arbitres assignés ont voté pour la manche donnée, calcule la moyenne
 * et l'enregistre dans manches_resultats + met à jour le score cumulé du combat.
 */
function verifierEtClorreManche(PDO $pdo, int $combatId, int $manche): void
{
    // Déjà clôturée ?
    $stmt = $pdo->prepare('SELECT id FROM manches_resultats WHERE combat_id = ? AND manche = ?');
    $stmt->execute([$combatId, $manche]);
    if ($stmt->fetch()) {
        return;
    }

    $nbArbitres = (int)$pdo->query('SELECT COUNT(*) c FROM combat_arbitres WHERE combat_id = ' . (int)$combatId)->fetch()['c'];
    if ($nbArbitres < 1) return;

    $stmt = $pdo->prepare('SELECT joueur1_points, joueur2_points FROM points WHERE combat_id = ? AND manche = ?');
    $stmt->execute([$combatId, $manche]);
    $votes = $stmt->fetchAll();

    if (count($votes) < $nbArbitres) {
        return; // tout le monde n'a pas encore voté
    }

    $moyJ1 = array_sum(array_column($votes, 'joueur1_points')) / count($votes);
    $moyJ2 = array_sum(array_column($votes, 'joueur2_points')) / count($votes);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO manches_resultats (combat_id, manche, moyenne_joueur1, moyenne_joueur2) VALUES (?, ?, ?, ?)'
        )->execute([$combatId, $manche, $moyJ1, $moyJ2]);

        $pdo->prepare(
            'UPDATE combats SET score_joueur1 = score_joueur1 + ?, score_joueur2 = score_joueur2 + ?, manche_actuelle = manche_actuelle + 1 WHERE id = ?'
        )->execute([$moyJ1, $moyJ2, $combatId]);

        $pdo->commit();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        // Code 23000 = violation de contrainte d'unicité : deux votes concurrents
        // ont déclenché la clôture de la même manche en même temps (dernier
        // arbitre + double-clic, requête réseau lente rejouée, etc.). Celui qui
        // perd la course n'est pas une erreur réelle : la manche est déjà
        // correctement clôturée par l'autre requête, donc on ignore simplement.
        if ($ex->getCode() === '23000') {
            return;
        }
        throw $ex;
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Vérifie si le temps imparti au combat est écoulé ; si oui, le termine et détermine le vainqueur.
 */
function verifierFinCombat(PDO $pdo, int $combatId): void
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat || $combat['statut'] !== 'en_cours' || !$combat['temps_debut']) {
        return;
    }

    if (!empty($combat['en_pause_depuis'])) {
        return; // chrono arrêté : le combat ne se termine pas pendant une pause
    }

    if (tempsRestant($combat) <= 0) {
        terminerCombat($pdo, $combatId);
    }
}

function terminerCombat(PDO $pdo, int $combatId): void
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat || $combat['statut'] === 'termine') return;

    // Score officiel = le plus bas des totaux des 3 arbitres (boutons +1/+2/+3)
    $etat = majScoreCombat($pdo, $combatId);
    $scoreJ1 = (float)$etat['j1'];
    $scoreJ2 = (float)$etat['j2'];
    $pv = pointsVictoire(abs($scoreJ1 - $scoreJ2));

    $vainqueurId = null;
    if ($scoreJ1 > $scoreJ2) $vainqueurId = $combat['joueur1_id'];
    elseif ($scoreJ2 > $scoreJ1) $vainqueurId = $combat['joueur2_id'];
    // égalité => pas de vainqueur (null)

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE combats SET statut = "termine", temps_fin = NOW(), en_pause_depuis = NULL, vainqueur_id = ?, points_victoire = ? WHERE id = ?'
        )->execute([$vainqueurId, $pv, $combatId]);

        foreach ([$combat['joueur1_id'] => $scoreJ1, $combat['joueur2_id'] => $scoreJ2] as $joueurId => $score) {
            $isVictoire = ($vainqueurId == $joueurId);
            $isDefaite = ($vainqueurId !== null && $vainqueurId != $joueurId);

            $pdo->prepare(
                'UPDATE joueurs SET matchs_joues = matchs_joues + 1,
                    victoires = victoires + ?,
                    defaites = defaites + ?,
                    points_cumules = points_cumules + ?,
                    points_victoire = points_victoire + ?
                 WHERE id = ?'
            )->execute([$isVictoire ? 1 : 0, $isDefaite ? 1 : 0, $score, $isVictoire ? $pv : 0, $joueurId]);
        }

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function getCombat(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo, jv.pseudo AS vainqueur_pseudo
         FROM combats c
         JOIN joueurs j1 ON j1.id = c.joueur1_id
         JOIN joueurs j2 ON j2.id = c.joueur2_id
         LEFT JOIN joueurs jv ON jv.id = c.vainqueur_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $combat = $stmt->fetch();
    return $combat ?: null;
}

function getArbitresDuCombat(PDO $pdo, int $combatId): array
{
    $stmt = $pdo->prepare(
        'SELECT ca.role, u.id AS user_id, u.username, u.nom, u.prenom
         FROM combat_arbitres ca
         JOIN users u ON u.id = ca.user_id
         WHERE ca.combat_id = ?
         ORDER BY FIELD(ca.role, "central", "coin1", "coin2")'
    );
    $stmt->execute([$combatId]);
    return $stmt->fetchAll();
}

function getVotesManche(PDO $pdo, int $combatId, int $manche): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, u.username FROM points p JOIN users u ON u.id = p.arbitre_user_id
         WHERE p.combat_id = ? AND p.manche = ?'
    );
    $stmt->execute([$combatId, $manche]);
    return $stmt->fetchAll();
}

function tempsRestant(array $combat): int
{
    if ($combat['statut'] !== 'en_cours' || !$combat['temps_debut']) {
        return (int)$combat['duree_secondes'];
    }
    // En pause : le chrono est figé à l'instant de la mise en pause
    $maintenant = !empty($combat['en_pause_depuis']) ? strtotime($combat['en_pause_depuis']) : time();
    $ecoule = $maintenant - strtotime($combat['temps_debut']);
    return max(0, (int)$combat['duree_secondes'] - $ecoule);
}

/** Attributs HTML d'un chrono .js-timer (temps restant + état de pause), lus par live.js. */
function attrsChrono(array $combat): string
{
    return 'data-remaining="' . tempsRestant($combat) . '"' . (!empty($combat['en_pause_depuis']) ? ' data-paused="1"' : '');
}

/** Score lisible : 12 plutôt que 12.00, 7.5 si demi-point. */
function fmtScore($v): string
{
    $f = (float)$v;
    return floor($f) == $f ? (string)(int)$f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

function formatDateFr(?string $datetime): string
{
    if (!$datetime) return '-';
    $ts = strtotime($datetime);
    return date('d/m/Y H:i', $ts);
}

function getClassement(PDO $pdo): array
{
    return $pdo->query(
        'SELECT *
         FROM joueurs
         ORDER BY (matchs_joues = 0), points_victoire DESC, victoires DESC, points_cumules DESC, pseudo ASC'
    )->fetchAll();
}

/**
 * Retourne les combats organisés en arbre de tournoi : un tableau associatif
 * [numéro_de_tour => [combat triés par position, ...], ...], prêt à être
 * affiché en colonnes par la vue arbre.php. Seuls les combats auxquels un
 * tour a été assigné (via l'admin) apparaissent dans l'arbre.
 */
function getArbreCombats(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT c.*, j1.pseudo AS joueur1_pseudo, j2.pseudo AS joueur2_pseudo
         FROM combats c
         JOIN joueurs j1 ON j1.id = c.joueur1_id
         JOIN joueurs j2 ON j2.id = c.joueur2_id
         WHERE c.tour IS NOT NULL
         ORDER BY c.tour ASC, c.position ASC"
    );

    $parTour = [];
    foreach ($stmt->fetchAll() as $combat) {
        $parTour[(int)$combat['tour']][] = $combat;
    }
    ksort($parTour);
    return $parTour;
}

/**
 * Parcourt tous les combats "en_cours" et clôture ceux dont le temps est écoulé.
 * À appeler en tête des pages publiques / admin pour garder les données à jour
 * sans dépendre d'une tâche cron.
 */
function purgerCombatsExpires(PDO $pdo): void
{
    // Démarre d'abord les combats dont l'heure prévue est arrivée (heure du serveur)
    demarrerCombatsPlanifies($pdo);

    $stmt = $pdo->query('SELECT id FROM combats WHERE statut = "en_cours"');
    foreach ($stmt->fetchAll() as $row) {
        verifierFinCombat($pdo, (int)$row['id']);
    }
}

/* =====================================================================
 * PARAMÈTRES DE L'APPLICATION : clé/valeur, 100% configurables depuis
 * Admin → Paramètres. Aucune valeur ici n'est censée être codée en dur
 * ailleurs dans l'appli : tout passe par getParametre()/setParametre().
 * ===================================================================== */

/**
 * Charge tous les paramètres en une seule requête et les met en cache pour
 * le reste de la requête HTTP (évite une requête SQL par appel).
 * $forceReload permet à setParametre() d'invalider ce cache immédiatement.
 */
function getTousLesParametres(PDO $pdo, bool $forceReload = false): array
{
    static $cache = null;
    if ($cache === null || $forceReload) {
        $cache = [];
        foreach ($pdo->query('SELECT cle, valeur FROM parametres')->fetchAll() as $row) {
            $cache[$row['cle']] = $row['valeur'];
        }
    }
    return $cache;
}

function getParametre(PDO $pdo, string $cle, string $defaut = ''): string
{
    $params = getTousLesParametres($pdo);
    return $params[$cle] ?? $defaut;
}

function getParametreInt(PDO $pdo, string $cle, int $defaut = 0): int
{
    return (int)getParametre($pdo, $cle, (string)$defaut);
}

function getParametreFloat(PDO $pdo, string $cle, float $defaut = 0): float
{
    return (float)getParametre($pdo, $cle, (string)$defaut);
}

/**
 * Crée ou met à jour un paramètre. Invalide le cache pour que la nouvelle
 * valeur soit immédiatement visible dans le reste de la requête courante.
 */
function setParametre(PDO $pdo, string $cle, string $valeur): void
{
    $pdo->prepare(
        'INSERT INTO parametres (cle, valeur) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
    )->execute([$cle, $valeur]);

    getTousLesParametres($pdo, true); // invalide le cache statique immédiatement
}

/* =====================================================================
 * GRADES : liste configurable des niveaux de sabre laser
 * ===================================================================== */

function getGrades(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM grades ORDER BY ordre ASC, nom ASC')->fetchAll();
}

function getGradeById(PDO $pdo, ?int $gradeId): ?array
{
    if (!$gradeId) return null;
    $stmt = $pdo->prepare('SELECT * FROM grades WHERE id = ?');
    $stmt->execute([$gradeId]);
    $grade = $stmt->fetch();
    return $grade ?: null;
}

/* =====================================================================
 * PROFIL JOUEUR : calculs dérivés automatiques (jamais saisis à la main)
 * ===================================================================== */

/**
 * Calcule l'âge en années à partir d'une date de naissance. Retourne null
 * si la date est absente : l'âge n'est donc jamais saisi manuellement,
 * uniquement dérivé de la date de naissance.
 */
function calculerAge(?string $dateNaissance): ?int
{
    if (!$dateNaissance) return null;
    try {
        $naissance = new DateTime($dateNaissance);
        $aujourdhui = new DateTime('today');
        return $naissance->diff($aujourdhui)->y;
    } catch (Exception $e) {
        return null;
    }
}

function libelleSexe(?string $sexe): string
{
    return match ($sexe) {
        'M' => 'Homme',
        'F' => 'Femme',
        'Autre' => 'Autre',
        default => '-',
    };
}

/* =====================================================================
 * IMPORT / EXPORT JOUEURS (CSV) : colonnes : pseudo, nom, prenom, sexe,
 * date_naissance, poids, grade. Le pseudo sert de clé d'unicité : un
 * pseudo déjà présent en base est mis à jour, sinon un nouveau joueur
 * est créé. Format CSV choisi (plutôt que .xlsx) pour ouvrir/enregistrer
 * nativement dans Excel sans dépendance externe à installer.
 * ===================================================================== */

const JOUEURS_CSV_COLONNES = ['pseudo', 'nom', 'prenom', 'sexe', 'date_naissance', 'poids', 'grade', 'equipe'];

/**
 * Génère le contenu CSV de tous les joueurs actuels (pour export/sauvegarde).
 * Encodage UTF-8 avec BOM + délimiteur ";" : ouverture directe et correcte
 * dans Excel (y compris en localisation française, où "," est le séparateur
 * décimal et ";" le séparateur de colonnes par défaut).
 */
function joueursVersCSV(PDO $pdo): string
{
    $joueurs = $pdo->query(
        'SELECT j.*, g.nom AS grade_nom FROM joueurs j LEFT JOIN grades g ON g.id = j.grade_id ORDER BY j.pseudo ASC'
    )->fetchAll();

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8
    // Anti « injection de formule » : une cellule qui commence par = + - @ serait
    // exécutée comme formule par Excel/LibreOffice ; on la préfixe d'une apostrophe.
    $cellule = static function ($v): string {
        $v = (string)$v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    };
    // Champs personnalisés : une colonne par champ, après les colonnes standard
    $champs = getChampsJoueur($pdo);
    $valeurs = valeursChampsJoueurs($pdo);
    fputcsv($fh, array_map($cellule, array_merge(JOUEURS_CSV_COLONNES, array_column($champs, 'nom'))), ';');
    foreach ($joueurs as $j) {
        $perso = array_map(static fn($c) => $valeurs[(int)$j['id']][(int)$c['id']] ?? '', $champs);
        fputcsv($fh, array_map($cellule, [...[
            $j['pseudo'],
            $j['nom'] ?? '',
            $j['prenom'] ?? '',
            $j['sexe'] ?? '',
            $j['date_naissance'] ?? '',
            $j['poids'] ?? '',
            $j['grade_nom'] ?? '',
            $j['equipe'] ?? '',
        ], ...$perso]), ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/**
 * Importe un CSV de joueurs (upsert par pseudo). $cheminFichier est un
 * fichier déjà uploadé (tmp_name de $_FILES). Retourne un résumé détaillé
 * pour affichage à l'admin : jamais d'import silencieux : chaque ligne en
 * erreur est explicitement rapportée avec son numéro et sa raison.
 */
function importerJoueursCSV(PDO $pdo, string $cheminFichier): array
{
    $resume = ['crees' => 0, 'mis_a_jour' => 0, 'erreurs' => []];

    $fh = fopen($cheminFichier, 'r');
    if (!$fh) {
        $resume['erreurs'][] = 'Impossible de lire le fichier envoyé.';
        return $resume;
    }

    // Retire le BOM UTF-8 éventuel en tête de fichier (Excel en ajoute un).
    $premierOctet = fread($fh, 3);
    if ($premierOctet !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $entetes = fgetcsv($fh, 0, ';');
    if ($entetes === false) {
        $resume['erreurs'][] = 'Le fichier est vide ou illisible.';
        fclose($fh);
        return $resume;
    }
    // Tolère aussi le CSV séparé par des virgules (Excel selon la locale).
    if (count($entetes) === 1 && str_contains($entetes[0], ',')) {
        rewind($fh);
        fread($fh, 3);
        $entetes = fgetcsv($fh, 0, ',');
        $delimiteur = ',';
    } else {
        $delimiteur = ';';
    }
    $entetes = array_map(static fn($h) => mb_strtolower(ltrim(trim((string)$h), "'")), $entetes);

    $sexesValides = ['M', 'F', 'Autre'];
    $grades = getGrades($pdo);
    $gradeParNom = [];
    foreach ($grades as $g) {
        $gradeParNom[mb_strtolower($g['nom'])] = $g['id'];
    }
    $champsPerso = getChampsJoueur($pdo);

    $ligneNum = 1; // ligne 1 = en-têtes
    while (($ligne = fgetcsv($fh, 4096, $delimiteur)) !== false) {
        $ligneNum++;
        if ($ligneNum > 2001) {
            $resume['erreurs'][] = 'Fichier tronqué : 2000 lignes au maximum par import.';
            break;
        }
        if (count($ligne) === 1 && trim((string)$ligne[0]) === '') {
            continue; // ligne vide
        }
        $row = [];
        foreach ($entetes as $i => $nomColonne) {
            // Texte nettoyé (caractères de contrôle retirés), longueur bornée,
            // apostrophe de protection anti-formule retirée si présente
            $row[$nomColonne] = ltrim(texteBorne((string)($ligne[$i] ?? ''), 255) ?? '', "'");
        }

        $pseudo = mb_substr($row['pseudo'] ?? '', 0, 60);
        if ($pseudo === '') {
            $resume['erreurs'][] = "Ligne {$ligneNum} : pseudo manquant, ligne ignorée.";
            continue;
        }

        $sexe = null;
        if (!empty($row['sexe'])) {
            $trouve = null;
            foreach ($sexesValides as $sv) {
                if (mb_strtolower($sv) === mb_strtolower($row['sexe'])) { $trouve = $sv; break; }
            }
            if ($trouve === null) {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : sexe \"{$row['sexe']}\" non reconnu (M / F / Autre attendu), ignoré.";
            }
            $sexe = $trouve;
        }

        $dateNaissance = null;
        if (!empty($row['date_naissance'])) {
            $ts = strtotime($row['date_naissance']);
            if ($ts === false) {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : date de naissance \"{$row['date_naissance']}\" illisible, ignorée.";
            } elseif ($ts > time()) {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : date de naissance dans le futur, ignorée.";
            } else {
                $dateNaissance = date('Y-m-d', $ts);
            }
        }

        $poids = null;
        if (!empty($row['poids'])) {
            $poidsBrut = str_replace(',', '.', $row['poids']);
            if (is_numeric($poidsBrut) && (float)$poidsBrut > 0 && (float)$poidsBrut < 400) {
                $poids = (float)$poidsBrut;
            } else {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : poids \"{$row['poids']}\" invalide, ignoré.";
            }
        }

        $gradeId = null;
        if (!empty($row['grade'])) {
            $gradeId = $gradeParNom[mb_strtolower($row['grade'])] ?? null;
            if ($gradeId === null) {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : grade \"{$row['grade']}\" inconnu (voir Admin → Paramètres), ignoré.";
            }
        }

        $nom = mb_substr($row['nom'] ?? '', 0, 100);
        $prenom = mb_substr($row['prenom'] ?? '', 0, 100);

        // Champs personnalisés : seules les colonnes présentes dans le fichier sont modifiées
        $valeursPerso = [];
        foreach ($champsPerso as $c) {
            $col = mb_strtolower($c['nom']);
            if (!array_key_exists($col, $row)) continue;
            [$v, $err] = normaliserValeurChamp($c, $row[$col]);
            if ($err) {
                $resume['erreurs'][] = "Ligne {$ligneNum} ({$pseudo}) : {$err} Valeur ignorée.";
                continue;
            }
            $valeursPerso[(int)$c['id']] = $v;
        }

        $stmt = $pdo->prepare('SELECT id FROM joueurs WHERE pseudo = ?');
        $stmt->execute([$pseudo]);
        $existant = $stmt->fetch();

        $equipe = mb_substr($row['equipe'] ?? '', 0, 60);

        if ($existant) {
            $pdo->prepare(
                'UPDATE joueurs SET nom = ?, prenom = ?, sexe = ?, date_naissance = ?, poids = ?, grade_id = ?, equipe = ? WHERE id = ?'
            )->execute([$nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId, $equipe ?: null, $existant['id']]);
            enregistrerValeursChamps($pdo, (int)$existant['id'], $valeursPerso);
            $resume['mis_a_jour']++;
        } else {
            $pdo->prepare(
                'INSERT INTO joueurs (pseudo, nom, prenom, sexe, date_naissance, poids, grade_id, equipe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$pseudo, $nom ?: null, $prenom ?: null, $sexe, $dateNaissance, $poids, $gradeId, $equipe ?: null]);
            enregistrerValeursChamps($pdo, (int)$pdo->lastInsertId(), $valeursPerso);
            $resume['crees']++;
        }
    }
    fclose($fh);

    return $resume;
}

/* =====================================================================
 * GÉNÉRATION AUTOMATIQUE DES COMBATS : appariement des joueurs présents
 * selon des règles 100% configurables (Admin → Paramètres), puis
 * placement du 1er tour de l'arbre de tournoi + répartition tournante
 * des arbitres présents.
 * ===================================================================== */

/**
 * Vérifie si deux joueurs peuvent être appariés selon les règles configurées.
 */
function joueursCompatibles(array $j1, array $j2, array $regles): bool
{
    if ($regles['ecart_poids_max_kg'] > 0 && $j1['poids'] !== null && $j2['poids'] !== null) {
        if (abs((float)$j1['poids'] - (float)$j2['poids']) > $regles['ecart_poids_max_kg']) {
            return false;
        }
    }

    if ($regles['ecart_age_max_ans'] > 0) {
        $age1 = calculerAge($j1['date_naissance']);
        $age2 = calculerAge($j2['date_naissance']);
        if ($age1 !== null && $age2 !== null && abs($age1 - $age2) > $regles['ecart_age_max_ans']) {
            return false;
        }
    }

    if ($regles['appariement_meme_sexe'] && $j1['sexe'] && $j2['sexe'] && $j1['sexe'] !== $j2['sexe']) {
        return false;
    }

    if ($regles['ecart_grade_max'] > 0 && $j1['grade_ordre'] !== null && $j2['grade_ordre'] !== null) {
        if (abs($j1['grade_ordre'] - $j2['grade_ordre']) > $regles['ecart_grade_max']) {
            return false;
        }
    }

    return true;
}

/**
 * Génère automatiquement les combats du 1er tour à partir des joueurs et
 * arbitres sélectionnés, en respectant les règles d'appariement configurées
 * (écart de poids/âge/grade, même sexe). Algorithme glouton : chaque joueur
 * est apparié avec le joueur compatible restant dont le poids est le plus
 * proche, en partant de la liste triée par poids (rapproche naturellement
 * les gabarits similaires même quand plusieurs règles sont actives).
 *
 * Retourne un résumé complet (jamais d'échec silencieux) : nombre de
 * combats créés, joueurs n'ayant pas pu être appariés, avertissements sur
 * les arbitres.
 */
function genererCombatsAutomatiques(PDO $pdo, array $joueurIds, array $arbitreIds, bool $viderArbreExistant, array $options = []): array
{
    // Planning : heure du 1er combat (heure du serveur) + minutes entre deux débuts.
    // Sans heure de début, les combats sont créés sans horaire (démarrage manuel).
    $debutPlanning = !empty($options['debut']) ? strtotime($options['debut']) : false;
    $intervalleMin = max(1, (int)($options['intervalle_min'] ?? getParametreInt($pdo, 'intervalle_combats_min', 5)));
    $dureeChoisie  = isset($options['duree']) ? max(30, (int)$options['duree']) : null;

    $resume = ['combats_crees' => 0, 'non_apparies' => [], 'avertissements' => [], 'erreur' => null];

    if (count($joueurIds) < 2) {
        $resume['erreur'] = 'Il faut sélectionner au moins 2 joueurs.';
        return $resume;
    }

    // Un arbre déjà en cours ou terminé ne doit jamais être écrasé
    // silencieusement : on bloque totalement dans ce cas.
    $existants = $pdo->query('SELECT id, statut FROM combats WHERE tour IS NOT NULL')->fetchAll();
    $existantsActifs = array_filter($existants, static fn($c) => $c['statut'] !== 'a_venir');
    if ($existantsActifs) {
        $resume['erreur'] = 'L\'arbre contient déjà des combats en cours ou terminés, il ne peut pas être régénéré. Pour repartir de zéro, affichez le filtre « Tous », cochez tous les combats et supprimez-les.';
        return $resume;
    }
    if ($existants && !$viderArbreExistant) {
        $resume['erreur'] = 'Des combats à venir sont déjà placés dans l\'arbre. Cochez « Remplacer les combats à venir déjà placés dans l\'arbre » pour les remplacer.';
        return $resume;
    }

    $placeholders = implode(',', array_fill(0, count($joueurIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT j.*, g.ordre AS grade_ordre FROM joueurs j LEFT JOIN grades g ON g.id = j.grade_id WHERE j.id IN ($placeholders)"
    );
    $stmt->execute($joueurIds);
    $joueurs = $stmt->fetchAll();

    $regles = [
        'ecart_poids_max_kg'   => getParametreFloat($pdo, 'ecart_poids_max_kg', 0),
        'ecart_age_max_ans'    => getParametreInt($pdo, 'ecart_age_max_ans', 0),
        'ecart_grade_max'      => getParametreInt($pdo, 'ecart_grade_max', 0),
        'appariement_meme_sexe' => getParametreInt($pdo, 'appariement_meme_sexe', 0) === 1,
    ];

    // Tri par poids (les joueurs sans poids renseigné passent en dernier) :
    // rapproche les gabarits similaires même sans contrainte stricte.
    usort($joueurs, static fn($a, $b) => ($a['poids'] ?? PHP_INT_MAX) <=> ($b['poids'] ?? PHP_INT_MAX));

    $restants = $joueurs;
    $paires = [];
    while (count($restants) > 1) {
        $j1 = array_shift($restants);
        $meilleurIndex = null;
        $meilleurEcart = null;
        foreach ($restants as $i => $j2) {
            if (!joueursCompatibles($j1, $j2, $regles)) continue;
            $ecart = abs((float)($j1['poids'] ?? 0) - (float)($j2['poids'] ?? 0));
            if ($meilleurEcart === null || $ecart < $meilleurEcart) {
                $meilleurEcart = $ecart;
                $meilleurIndex = $i;
            }
        }
        if ($meilleurIndex === null) {
            $resume['non_apparies'][] = $j1['pseudo'];
            continue;
        }
        $j2 = $restants[$meilleurIndex];
        unset($restants[$meilleurIndex]);
        $restants = array_values($restants);
        $paires[] = [$j1, $j2];
    }
    if (count($restants) === 1) {
        $resume['non_apparies'][] = $restants[0]['pseudo'];
    }

    if (!$paires) {
        $resume['erreur'] = 'Aucun appariement compatible n\'a pu être formé avec les règles actuelles (Admin → Paramètres). Assouplissez les règles ou sélectionnez d\'autres joueurs.';
        return $resume;
    }

    // Arbitres : jusqu'à 3 rôles distincts par combat, répartis en rotation
    // pour équilibrer la charge. Si moins de 3 arbitres sont sélectionnés,
    // seuls les rôles couvrables sont assignés (jamais le même arbitre sur
    // deux rôles du même combat).
    $nbArbitres = count($arbitreIds);
    $rolesParCombat = min(3, $nbArbitres);
    if ($rolesParCombat < 3) {
        $resume['avertissements'][] = $nbArbitres === 0
            ? 'Aucun arbitre sélectionné : affectez-les à chaque combat avant son démarrage.'
            : "Seulement {$nbArbitres} arbitre(s) sélectionné(s) : certains combats n'auront pas leurs 3 arbitres avant d'être complétés manuellement.";
    }
    $roles = ['central', 'coin1', 'coin2'];

    $pdo->beginTransaction();
    try {
        if ($viderArbreExistant && $existants) {
            $idsASupprimer = array_column($existants, 'id');
            $ph = implode(',', array_fill(0, count($idsASupprimer), '?'));
            $pdo->prepare("DELETE FROM combats WHERE id IN ($ph)")->execute($idsASupprimer);
        }

        $dureeDefaut = $dureeChoisie ?? getParametreInt($pdo, 'duree_combat_defaut', 180);
        $insertCombat = $pdo->prepare(
            'INSERT INTO combats (joueur1_id, joueur2_id, duree_secondes, date_prevue, tour, position, statut) VALUES (?, ?, ?, ?, 1, ?, "a_venir")'
        );
        $insertArbitre = $pdo->prepare('INSERT INTO combat_arbitres (combat_id, user_id, role) VALUES (?, ?, ?)');

        foreach ($paires as $position => [$j1, $j2]) {
            $datePrevue = $debutPlanning !== false ? date('Y-m-d H:i:s', $debutPlanning + $position * $intervalleMin * 60) : null;
            $insertCombat->execute([$j1['id'], $j2['id'], $dureeDefaut, $datePrevue, $position]);
            $combatId = (int)$pdo->lastInsertId();

            for ($r = 0; $r < $rolesParCombat; $r++) {
                $arbitreId = $arbitreIds[($position * $rolesParCombat + $r) % $nbArbitres];
                $insertArbitre->execute([$combatId, $arbitreId, $roles[$r]]);
            }

            $resume['combats_crees']++;
        }

        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return $resume;
}

/* =====================================================================
 * RETARD : signalé par un arbitre ou l'admin, décale automatiquement tous
 * les combats "à venir" ayant une date prévue, et informe les spectateurs
 * via un bandeau public (voir includes/header.php).
 * ===================================================================== */

/**
 * Ajoute (ou retire, si $minutes négatif) un retard au planning : décale la
 * date prévue de tous les combats à venir, et cumule la valeur affichée
 * publiquement. Retourne le nombre de combats effectivement décalés.
 */
function appliquerRetard(PDO $pdo, int $minutes): int
{
    if ($minutes === 0) return 0;

    $stmt = $pdo->prepare(
        "UPDATE combats SET date_prevue = DATE_ADD(date_prevue, INTERVAL ? MINUTE) WHERE statut = 'a_venir' AND date_prevue IS NOT NULL"
    );
    $stmt->execute([$minutes]);
    $nbAffectes = $stmt->rowCount();

    $retardActuel = getParametreInt($pdo, 'retard_minutes', 0);
    setParametre($pdo, 'retard_minutes', (string) max(0, $retardActuel + $minutes));

    return $nbAffectes;
}

/**
 * Colonne sabres.last_hit_at (indicateur « Touché ? » dans Admin → Sabres) :
 * utilisée par le daemon BLE et l'API mais absente des scripts SQL d'origine.
 * On l'ajoute automatiquement si besoin.
 */
function ensureSabresSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'sabres'")->fetch()) return;
        if (!$pdo->query("SHOW COLUMNS FROM sabres LIKE 'last_hit_at'")->fetch()) {
            $pdo->exec('ALTER TABLE sabres ADD COLUMN last_hit_at DATETIME(3) NULL DEFAULT NULL AFTER last_seen');
        }
    } catch (PDOException $e) {
        error_log('[Sabre] ensureSabresSchema : ' . $e->getMessage());
    }
}

/** Télécommandes d'arbitre Bluetooth (ble_receiver/receiver.py crée la même table). */
function ensureTelecommandesSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS telecommandes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mac_address VARCHAR(17) DEFAULT NULL,
                nom VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'Nom Bluetooth (ex. TEL_2)',
                user_id INT DEFAULT NULL COMMENT 'Arbitre au nom duquel elle donne les points',
                actif TINYINT(1) NOT NULL DEFAULT 1,
                last_seen DATETIME DEFAULT NULL,
                last_press_at DATETIME(3) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_mac (mac_address),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Première version (USB) : identifiée par son nom, sans adresse MAC
        $pdo->exec("ALTER TABLE telecommandes ADD COLUMN IF NOT EXISTS mac_address VARCHAR(17) DEFAULT NULL AFTER id");
        $pdo->exec("ALTER TABLE telecommandes DROP INDEX IF EXISTS nom");
        $pdo->exec("ALTER TABLE telecommandes ADD UNIQUE INDEX IF NOT EXISTS uniq_mac (mac_address)");
    } catch (PDOException $e) {
        error_log('[Sabre] ensureTelecommandesSchema : ' . $e->getMessage());
    }
}
