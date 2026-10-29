<?php
/**
 * includes/champs_joueur.php : champs personnalisés des fiches joueurs.
 *
 * Les organisateurs créent autant de champs qu'ils veulent depuis
 * Admin → Paramètres (licence, taille, main forte, contact d'urgence…).
 * - Définitions : table `champs_joueur` (nom, type, options, ordre).
 * - Valeurs     : table `joueur_champs_valeurs`, une ligne par joueur et par champ
 *                 (supprimées automatiquement avec le joueur ou le champ).
 * Le schéma est créé par la migration v7 (includes/tournoi.php).
 */

/** Types de champ disponibles : clé => libellé. */
function typesChampJoueur(): array
{
    return [
        'texte'   => 'Texte',
        'nombre'  => 'Nombre',
        'date'    => 'Date',
        'liste'   => 'Liste de choix',
        'oui_non' => 'Oui / Non',
    ];
}

/** Colonnes fixes de la fiche : un champ personnalisé ne peut pas porter ces noms (conflit CSV). */
function nomsChampsReserves(): array
{
    return array_merge(JOUEURS_CSV_COLONNES, ['id', 'grade_id']);
}

function creerSchemaChampsJoueur(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS champs_joueur (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nom         VARCHAR(60)  NOT NULL UNIQUE,
            type        VARCHAR(20)  NOT NULL DEFAULT 'texte',
            options     TEXT         DEFAULT NULL COMMENT 'Choix possibles (type liste), un par ligne',
            obligatoire TINYINT(1)   NOT NULL DEFAULT 0,
            ordre       INT          NOT NULL DEFAULT 0,
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS joueur_champs_valeurs (
            joueur_id INT          NOT NULL,
            champ_id  INT          NOT NULL,
            valeur    VARCHAR(255) NOT NULL,
            PRIMARY KEY (joueur_id, champ_id),
            FOREIGN KEY (joueur_id) REFERENCES joueurs(id) ON DELETE CASCADE,
            FOREIGN KEY (champ_id) REFERENCES champs_joueur(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Tous les champs personnalisés, dans l'ordre d'affichage. */
function getChampsJoueur(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = $pdo->query('SELECT * FROM champs_joueur ORDER BY ordre, nom')->fetchAll();
    } catch (PDOException $e) {
        $cache = []; // table pas encore créée
    }
    return $cache;
}

/** Choix possibles d'un champ de type liste. */
function optionsChamp(array $champ): array
{
    $opts = preg_split('/\R/u', (string)($champ['options'] ?? '')) ?: [];
    return array_values(array_unique(array_filter(array_map('trim', $opts), fn($o) => $o !== '')));
}

/**
 * Valeurs de tous les joueurs (ou d'un seul) : [joueur_id => [champ_id => valeur]].
 */
function valeursChampsJoueurs(PDO $pdo, ?int $joueurId = null): array
{
    try {
        if ($joueurId) {
            $stmt = $pdo->prepare('SELECT joueur_id, champ_id, valeur FROM joueur_champs_valeurs WHERE joueur_id = ?');
            $stmt->execute([$joueurId]);
        } else {
            $stmt = $pdo->query('SELECT joueur_id, champ_id, valeur FROM joueur_champs_valeurs');
        }
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll() as $v) $out[(int)$v['joueur_id']][(int)$v['champ_id']] = $v['valeur'];
    return $out;
}

/**
 * Normalise une saisie pour un champ. Retourne [valeur|null, erreur|null] :
 * null = champ vide (rien à enregistrer).
 */
function normaliserValeurChamp(array $champ, mixed $brut): array
{
    if (is_bool($brut)) $brut = $brut ? '1' : '';
    $v = texteBorne(is_string($brut) ? $brut : '', 255);
    $nom = $champ['nom'];
    if ($champ['type'] === 'oui_non') {
        if ($v === null) return [null, null];
        $v = mb_strtolower($v);
        if (in_array($v, ['1', 'oui', 'o', 'yes', 'y', 'vrai', 'true', 'x'], true)) return ['1', null];
        if (in_array($v, ['0', 'non', 'n', 'no', 'faux', 'false'], true)) return ['0', null];
        return [null, "« {$nom} » : oui ou non attendu."];
    }
    if ($v === null) return [null, null];
    switch ($champ['type']) {
        case 'nombre':
            $n = str_replace([',', ' '], ['.', ''], $v);
            if (!is_numeric($n)) return [null, "« {$nom} » : nombre attendu."];
            return [(string)(0 + $n), null];
        case 'date':
            $d = DateTime::createFromFormat('!Y-m-d', $v) ?: DateTime::createFromFormat('!d/m/Y', $v) ?: DateTime::createFromFormat('!d.m.Y', $v);
            if (!$d || (int)$d->format('Y') < 1900 || (int)$d->format('Y') > 2200) return [null, "« {$nom} » : date invalide."];
            return [$d->format('Y-m-d'), null];
        case 'liste':
            foreach (optionsChamp($champ) as $o) {
                if (mb_strtolower($o) === mb_strtolower($v)) return [$o, null];
            }
            return [null, "« {$nom} » : choix « {$v} » inconnu."];
        default:
            return [$v, null];
    }
}

/**
 * Valide les saisies du formulaire ($_POST['champ'][id]) pour tous les champs.
 * Retourne [valeurs normalisées [champ_id => valeur|null], erreurs[]].
 */
function validerChampsJoueur(PDO $pdo, mixed $saisies, bool $exigerObligatoires = true): array
{
    $saisies = is_array($saisies) ? $saisies : [];
    $valeurs = [];
    $erreurs = [];
    foreach (getChampsJoueur($pdo) as $c) {
        [$v, $err] = normaliserValeurChamp($c, $saisies[$c['id']] ?? '');
        if ($err) $erreurs[] = $err;
        elseif ($v === null && $exigerObligatoires && $c['obligatoire'] && $c['type'] !== 'oui_non') $erreurs[] = "« {$c['nom']} » est obligatoire.";
        $valeurs[(int)$c['id']] = $v;
    }
    return [$valeurs, $erreurs];
}

/** Enregistre les valeurs (null = efface la valeur). */
function enregistrerValeursChamps(PDO $pdo, int $joueurId, array $valeurs): void
{
    $set = $pdo->prepare('INSERT INTO joueur_champs_valeurs (joueur_id, champ_id, valeur) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)');
    $del = $pdo->prepare('DELETE FROM joueur_champs_valeurs WHERE joueur_id = ? AND champ_id = ?');
    foreach ($valeurs as $champId => $v) {
        if ($v === null) $del->execute([$joueurId, $champId]);
        else $set->execute([$joueurId, $champId, $v]);
    }
}

/** Valeur lisible pour l'affichage. */
function afficherValeurChamp(array $champ, ?string $v): string
{
    if ($v === null || $v === '') return '';
    return match ($champ['type']) {
        'oui_non' => $v === '1' ? 'Oui' : 'Non',
        'date'    => ($d = DateTime::createFromFormat('!Y-m-d', $v)) ? $d->format('d/m/Y') : $v,
        'nombre'  => str_replace('.', ',', $v),
        default   => $v,
    };
}

/** Champ de formulaire HTML pour un champ personnalisé. */
function inputChampJoueur(array $champ, ?string $valeur): string
{
    $id = 'champ_' . (int)$champ['id'];
    $name = 'champ[' . (int)$champ['id'] . ']';
    $req = $champ['obligatoire'] && $champ['type'] !== 'oui_non' ? ' required' : '';
    $label = '<label for="' . $id . '">' . e($champ['nom']) . ($req ? ' <span class="req">(obligatoire)</span>' : '') . '</label>';
    $valeur = (string)($valeur ?? '');
    switch ($champ['type']) {
        case 'oui_non':
            return '<div class="field"><label class="switch"><span class="switch-text"><strong>' . e($champ['nom']) . '</strong></span>'
                . '<input type="hidden" name="' . $name . '" value="0">'
                . '<input type="checkbox" role="switch" id="' . $id . '" name="' . $name . '" value="1"' . ($valeur === '1' ? ' checked' : '') . '></label></div>';
        case 'liste':
            $html = '<select id="' . $id . '" name="' . $name . '"' . $req . '><option value="">Non renseigné</option>';
            foreach (optionsChamp($champ) as $o) {
                $html .= '<option value="' . e($o) . '"' . ($o === $valeur ? ' selected' : '') . '>' . e($o) . '</option>';
            }
            return '<div class="field">' . $label . $html . '</select></div>';
        case 'nombre':
            return '<div class="field">' . $label . '<input type="number" step="any" inputmode="decimal" id="' . $id . '" name="' . $name . '" value="' . e($valeur) . '"' . $req . '></div>';
        case 'date':
            return '<div class="field">' . $label . '<input type="date" id="' . $id . '" name="' . $name . '" value="' . e($valeur) . '"' . $req . '></div>';
        default:
            return '<div class="field">' . $label . '<input type="text" maxlength="255" id="' . $id . '" name="' . $name . '" value="' . e($valeur) . '"' . $req . '></div>';
    }
}
