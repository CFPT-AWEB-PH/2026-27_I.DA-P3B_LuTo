<?php
/**
 * includes/tournoi.php : heure du serveur, démarrage automatique des combats,
 * historique des touches et petites fonctions d'affichage des durées.
 */

const SCHEMA_TOURNOI_VERSION = 8;

/**
 * Migrations automatiques (une seule fois, suivies par le paramètre schema_tournoi).
 *  v3 : l'application passe de l'heure UTC à l'heure du serveur. Les horodatages
 *       déjà écrits en UTC (début / fin de combat, touches, sabres) sont décalés
 *       une fois pour rester justes. Les heures prévues étaient déjà saisies en
 *       heure locale et ne bougent pas.
 */
function ensureSchemaTournoi(PDO $pdo): void
{
    static $fait = false;
    if ($fait) return;
    $fait = true;

    $version = (int)getParametre($pdo, 'schema_tournoi', '0');
    if ($version >= SCHEMA_TOURNOI_VERSION) return;

    try {
        ensureSabresSchema($pdo);

        $pdo->prepare(
            'INSERT INTO parametres (cle, valeur, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cle = cle'
        )->execute([
            'demarrage_auto', '1',
            'Démarrer automatiquement un combat à son heure prévue, si ses 3 arbitres sont affectés (1 = oui, 0 = non)',
        ]);
        $pdo->prepare(
            'INSERT INTO parametres (cle, valeur, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cle = cle'
        )->execute([
            'intervalle_combats_min', '5',
            'Minutes entre deux combats lors de la génération automatique du planning',
        ]);

        if ($version < 3) {
            $decalage = (int)date('Z'); // secondes entre UTC et l'heure du serveur
            if ($decalage !== 0) {
                $req = [
                    'UPDATE combats SET temps_debut = temps_debut + INTERVAL ? SECOND WHERE temps_debut IS NOT NULL',
                    'UPDATE combats SET temps_fin = temps_fin + INTERVAL ? SECOND WHERE temps_fin IS NOT NULL',
                ];
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('touchers', $tables, true)) {
                    $req[] = 'UPDATE touchers SET created_at = created_at + INTERVAL ? SECOND WHERE created_at IS NOT NULL';
                }
                if (in_array('sabres', $tables, true)) {
                    $req[] = 'UPDATE sabres SET last_seen = last_seen + INTERVAL ? SECOND WHERE last_seen IS NOT NULL';
                }
                foreach ($req as $sql) {
                    $pdo->prepare($sql)->execute([$decalage]);
                }
            }
        }
        // v5 : points en direct (boutons +1 / +2 / +3 de chaque arbitre) et points de victoire
        if ($version < 5) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS points_live (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    combat_id INT NOT NULL,
                    arbitre_user_id INT NOT NULL,
                    joueur_num TINYINT NOT NULL,
                    valeur TINYINT NOT NULL,
                    annule TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
                    KEY idx_combat (combat_id, arbitre_user_id),
                    FOREIGN KEY (combat_id) REFERENCES combats(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec("ALTER TABLE combats ADD COLUMN IF NOT EXISTS points_victoire TINYINT DEFAULT NULL AFTER vainqueur_id");
            $pdo->exec("ALTER TABLE joueurs ADD COLUMN IF NOT EXISTS points_victoire INT NOT NULL DEFAULT 0");
            // Combats déjà terminés : on calcule leurs points de victoire à partir de l'écart
            foreach ($pdo->query("SELECT id, score_joueur1, score_joueur2, vainqueur_id FROM combats WHERE statut = 'termine' AND points_victoire IS NULL")->fetchAll() as $c) {
                $pv = pointsVictoire(abs((float)$c['score_joueur1'] - (float)$c['score_joueur2']));
                $pdo->prepare('UPDATE combats SET points_victoire = ? WHERE id = ?')->execute([$pv, $c['id']]);
                if ($c['vainqueur_id'] && $pv) {
                    $pdo->prepare('UPDATE joueurs SET points_victoire = points_victoire + ? WHERE id = ?')->execute([$pv, $c['vainqueur_id']]);
                }
            }
        }

        // v6 : sécurité (limitation de débit, invalidation des sessions, changement de mot de passe forcé)
        if ($version < 6) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS limites_debit (
                    id CHAR(64) NOT NULL PRIMARY KEY,
                    action VARCHAR(40) NOT NULL,
                    compteur INT NOT NULL DEFAULT 0,
                    debut DATETIME NOT NULL,
                    KEY idx_debut (debut)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS session_version INT NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS derniere_connexion DATETIME DEFAULT NULL");
            // Tout compte encore sur un mot de passe par défaut connu devra le changer
            foreach ($pdo->query('SELECT id, password_hash FROM users')->fetchAll() as $u) {
                if (password_verify('admin123', $u['password_hash'])) {
                    $pdo->prepare('UPDATE users SET doit_changer_mdp = 1 WHERE id = ?')->execute([$u['id']]);
                }
            }
            $pdo->prepare("INSERT INTO parametres (cle, valeur, description) VALUES ('inscriptions_ouvertes', '1', 'Autoriser la création de comptes joueur depuis la page publique (1 = oui, 0 = non)') ON DUPLICATE KEY UPDATE cle = cle")->execute();
        }

        // v7 : champs personnalisés des fiches joueurs (includes/champs_joueur.php)
        if ($version < 7) {
            creerSchemaChampsJoueur($pdo);
        }

        // v8 : mise en pause d'un combat en cours (chrono arrêté)
        if ($version < 8) {
            $pdo->exec("ALTER TABLE combats ADD COLUMN IF NOT EXISTS en_pause_depuis DATETIME DEFAULT NULL AFTER temps_fin");
        }

        // v4 : descriptions de paramètres sans tiret long
        $pdo->exec("UPDATE parametres SET description = REPLACE(description, ' — ', ' : ') WHERE description LIKE '%—%'");

        setParametre($pdo, 'schema_tournoi', (string)SCHEMA_TOURNOI_VERSION);
    } catch (PDOException $e) {
        error_log('[Sabre] ensureSchemaTournoi : ' . $e->getMessage());
    }
}

/** Horodatage (ms) d'une date SQL écrite dans l'heure du serveur. */
function dateVersMs(?string $datetime): ?int
{
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    return $ts === false ? null : $ts * 1000;
}

/** 180 → "3 min", 90 → "1 min 30", 45 → "45 s" */
function formatDuree(int $secondes): string
{
    $secondes = max(0, $secondes);
    $m = intdiv($secondes, 60);
    $s = $secondes % 60;
    if ($m === 0) return $s . ' s';
    return $s ? sprintf('%d min %02d', $m, $s) : $m . ' min';
}

/** Libellé lisible d'un statut de combat. */
function libelleStatut(string $statut): string
{
    return [
        'a_venir'  => 'À venir',
        'en_cours' => 'En cours',
        'termine'  => 'Terminé',
        'annule'   => 'Annulé',
    ][$statut] ?? $statut;
}

/**
 * Pourquoi ce combat ne peut pas démarrer maintenant ? null s'il peut démarrer.
 */
function raisonBlocageDemarrage(PDO $pdo, array $combat): ?string
{
    $combatId = (int)$combat['id'];
    $nbArb = (int)$pdo->query('SELECT COUNT(*) FROM combat_arbitres WHERE combat_id = ' . $combatId)->fetchColumn();
    if ($nbArb < 3) {
        return 'il manque ' . (3 - $nbArb) . ' arbitre' . (3 - $nbArb > 1 ? 's' : '') . ' (il en faut 3 : central et deux coins).';
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM combats
         WHERE statut = 'en_cours' AND id <> ?
           AND (joueur1_id IN (?, ?) OR joueur2_id IN (?, ?))"
    );
    $st->execute([$combatId, $combat['joueur1_id'], $combat['joueur2_id'], $combat['joueur1_id'], $combat['joueur2_id']]);
    if ((int)$st->fetchColumn() > 0) {
        return 'un des joueurs est encore dans un combat en cours.';
    }

    $st = $pdo->prepare(
        "SELECT u.username FROM combat_arbitres ca
         JOIN users u ON u.id = ca.user_id
         WHERE ca.combat_id = ? AND ca.user_id IN (
             SELECT ca2.user_id FROM combat_arbitres ca2
             JOIN combats c2 ON c2.id = ca2.combat_id
             WHERE c2.statut = 'en_cours' AND c2.id <> ?)"
    );
    $st->execute([$combatId, $combatId]);
    $occupes = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($occupes) {
        return implode(', ', $occupes) . (count($occupes) > 1 ? ' arbitrent' : ' arbitre') . ' encore un autre combat.';
    }
    return null;
}

/**
 * Démarre un combat. Retourne ['ok' => bool, 'message' => string].
 * Refuse si les 3 arbitres ne sont pas affectés, ou si un des deux joueurs
 * est déjà dans un autre combat en cours.
 */
function demarrerCombat(PDO $pdo, int $combatId): array
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat) return ['ok' => false, 'message' => 'Combat introuvable.'];
    if ($combat['statut'] !== 'a_venir') return ['ok' => false, 'message' => 'Ce combat n\'est pas « à venir ».'];

    $raison = raisonBlocageDemarrage($pdo, $combat);
    if ($raison !== null) {
        return ['ok' => false, 'message' => $combat['joueur1_pseudo'] . ' contre ' . $combat['joueur2_pseudo'] . ' : ' . $raison];
    }

    $up = $pdo->prepare(
        "UPDATE combats SET statut = 'en_cours', temps_debut = NOW(), manche_actuelle = 1
         WHERE id = ? AND statut = 'a_venir'"
    );
    $up->execute([$combatId]);
    if ($up->rowCount() === 0) return ['ok' => false, 'message' => 'Ce combat vient déjà d\'être démarré.'];

    return ['ok' => true, 'message' => $combat['joueur1_pseudo'] . ' contre ' . $combat['joueur2_pseudo'] . ' a démarré.'];
}

/**
 * Met en pause un combat en cours : le chrono s'arrête et plus aucun point ne
 * peut être donné jusqu'à la reprise.
 */
function mettreEnPauseCombat(PDO $pdo, int $combatId): array
{
    $up = $pdo->prepare("UPDATE combats SET en_pause_depuis = NOW() WHERE id = ? AND statut = 'en_cours' AND en_pause_depuis IS NULL");
    $up->execute([$combatId]);
    return $up->rowCount()
        ? ['ok' => true, 'message' => 'Combat en pause : le chrono est arrêté.']
        : ['ok' => false, 'message' => 'Ce combat n\'est pas en cours, ou déjà en pause.'];
}

/**
 * Reprend un combat en pause : le début est décalé de la durée de la pause,
 * le temps restant repart donc exactement d'où il s'était arrêté.
 */
function reprendreCombat(PDO $pdo, int $combatId): array
{
    $up = $pdo->prepare(
        "UPDATE combats
         SET temps_debut = temps_debut + INTERVAL TIMESTAMPDIFF(SECOND, en_pause_depuis, NOW()) SECOND,
             en_pause_depuis = NULL
         WHERE id = ? AND statut = 'en_cours' AND en_pause_depuis IS NOT NULL"
    );
    $up->execute([$combatId]);
    return $up->rowCount()
        ? ['ok' => true, 'message' => 'Combat repris : le chrono repart.']
        : ['ok' => false, 'message' => 'Ce combat n\'est pas en pause.'];
}

/**
 * Démarre tout seul chaque combat « à venir » dont l'heure prévue est passée
 * (heure du serveur). Appelé à chaque chargement de page et par les API
 * interrogées en direct, donc le combat part à l'heure sans tâche cron.
 */
function demarrerCombatsPlanifies(PDO $pdo): void
{
    static $fait = false;
    if ($fait) return;
    $fait = true;

    if (getParametreInt($pdo, 'demarrage_auto', 1) !== 1) return;

    $ids = $pdo->query(
        "SELECT id FROM combats
         WHERE statut = 'a_venir' AND date_prevue IS NOT NULL AND date_prevue <= NOW()
         ORDER BY date_prevue ASC, id ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        demarrerCombat($pdo, (int)$id); // les combats sans arbitres restent en attente
    }
}

/**
 * Supprime un combat. S'il était terminé, ses résultats sont retirés des
 * statistiques des deux joueurs (matchs, victoires, défaites, points).
 */
function supprimerCombat(PDO $pdo, int $combatId): bool
{
    $combat = getCombat($pdo, $combatId);
    if (!$combat) return false;

    $pdo->beginTransaction();
    try {
        if ($combat['statut'] === 'termine') {
            $v = $combat['vainqueur_id'];
            foreach ([[$combat['joueur1_id'], (float)$combat['score_joueur1']], [$combat['joueur2_id'], (float)$combat['score_joueur2']]] as [$jid, $score]) {
                $victoire = ($v !== null && (int)$v === (int)$jid) ? 1 : 0;
                $defaite  = ($v !== null && (int)$v !== (int)$jid) ? 1 : 0;
                if ($victoire && (int)$combat['points_victoire'] > 0) {
                    $pdo->prepare('UPDATE joueurs SET points_victoire = GREATEST(0, points_victoire - ?) WHERE id = ?')
                        ->execute([(int)$combat['points_victoire'], $jid]);
                }
                $pdo->prepare(
                    'UPDATE joueurs SET
                        matchs_joues   = GREATEST(0, matchs_joues - 1),
                        victoires      = GREATEST(0, victoires - ?),
                        defaites       = GREATEST(0, defaites - ?),
                        points_cumules = GREATEST(0, points_cumules - ?)
                     WHERE id = ?'
                )->execute([$victoire, $defaite, $score, $jid]);
            }
        }
        $pdo->prepare('DELETE FROM combats WHERE id = ?')->execute([$combatId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[Sabre] supprimerCombat : ' . $e->getMessage());
        return false;
    }
}

/**
 * Historique des touches d'un combat, dans l'ordre chronologique.
 * Chaque touche porte le temps de match écoulé (secondes depuis le début).
 */
function getTouchesCombat(PDO $pdo, array $combat, int $depuisId = 0): array
{
    try {
        $st = $pdo->prepare(
            'SELECT t.id, t.type, t.joueur_id, t.timestamp_ms, t.created_at
             FROM touchers t
             WHERE t.combat_id = ? AND t.id > ?
             ORDER BY t.timestamp_ms ASC, t.id ASC
             LIMIT 1000'
        );
        $st->execute([(int)$combat['id'], $depuisId]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        return []; // table touchers absente (installation sans BLE)
    }

    $debutMs = dateVersMs($combat['temps_debut'] ?? null);
    return array_map(function ($t) use ($combat, $debutMs) {
        $num = null;
        if ((int)$t['joueur_id'] === (int)$combat['joueur1_id']) $num = 1;
        elseif ((int)$t['joueur_id'] === (int)$combat['joueur2_id']) $num = 2;
        $ts = (int)$t['timestamp_ms'];
        return [
            'id'         => (int)$t['id'],
            'type'       => $t['type'],
            'joueur_num' => $num,
            'joueur'     => $num === 1 ? $combat['joueur1_pseudo'] : ($num === 2 ? $combat['joueur2_pseudo'] : null),
            'ts_ms'      => $ts,
            'heure'      => date('H:i:s', intdiv($ts, 1000)),
            'match_s'    => $debutMs !== null ? max(0, intdiv($ts - $debutMs, 1000)) : null,
        ];
    }, $rows);
}

/**
 * Regroupe les deux lignes d'une double touche (une par sabre, à moins de
 * 250 ms d'écart) en une seule entrée pour l'affichage.
 */
function regrouperTouches(array $touches): array
{
    $out = [];
    foreach ($touches as $t) {
        $last = $out ? $out[count($out) - 1] : null;
        if ($t['type'] === 'double' && $last && $last['type'] === 'double'
            && abs($t['ts_ms'] - $last['ts_ms']) <= 250 && empty($last['groupe_complet'])) {
            $out[count($out) - 1]['groupe_complet'] = true;
            $out[count($out) - 1]['ids'][] = $t['id'];
            continue;
        }
        $t['ids'] = [$t['id']];
        $out[] = $t;
    }
    return $out;
}

/** Totaux des touches : [j1 => n, j2 => n, doubles => n]. */
function compterTouches(array $touches): array
{
    $c = ['j1' => 0, 'j2' => 0, 'doubles' => 0];
    foreach (regrouperTouches($touches) as $t) {
        if ($t['type'] === 'annule') continue;
        if ($t['type'] === 'double') { $c['doubles']++; continue; }
        if ($t['joueur_num'] === 1) $c['j1']++;
        elseif ($t['joueur_num'] === 2) $c['j2']++;
    }
    return $c;
}
