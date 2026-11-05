<?php
/**
 * includes/identifiants.php : mots de passe des comptes de l'application.
 *
 * Les mots de passe sont toujours GÉNÉRÉS (personne ne choisit le sien, personne ne
 * le change) et les administrateurs doivent pouvoir les relire (guide, écrans des
 * Raspberry). Ils sont donc gardés en plus du hachage, chiffrés (AES-256-GCM) avec
 * une clé dérivée de DB_PASS : une copie de la base seule ne suffit pas à les lire.
 * Si DB_PASS change, ils deviennent illisibles : ils se réenregistrent à la prochaine
 * connexion du compte, ou en générant un nouveau mot de passe.
 *
 * Exception, décidée par le projet : le compte système a un mot de passe imposé, qui ne
 * se change pas, et il ne peut être ni supprimé, ni désactivé, ni changer de rôle.
 */

const COMPTE_SYSTEME = 'admin';
const MDP_COMPTE_SYSTEME = 'Super';

function estCompteSysteme(?string $username): bool
{
    return $username === COMPTE_SYSTEME;
}

/** Mot de passe facile à taper sur un téléphone : xxxx-xxxx-xxxx (≈ 62 bits). */
function genererMotDePasse(string $username = ''): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // sans l, i, o, 0, 1 (ambigus)
    do {
        $groupes = [];
        for ($g = 0; $g < 3; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $groupes[] = $s;
        }
        $mdp = implode('-', $groupes);
    } while (erreurMotDePasse($mdp, $username));
    return $mdp;
}

function cleMotsDePasse(): string
{
    return hash('sha256', 'sabre-mdp|' . (string)getenv('DB_PASS'), true);
}

function chiffrerMotDePasse(string $mdp): string
{
    $iv = random_bytes(12);
    $chiffre = openssl_encrypt($mdp, 'aes-256-gcm', cleMotsDePasse(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $chiffre);
}

/** Mot de passe en clair, null s'il n'est pas connu ou plus lisible. */
function dechiffrerMotDePasse(?string $valeur): ?string
{
    $brut = $valeur ? base64_decode($valeur, true) : false;
    if ($brut === false || strlen($brut) < 28) return null;
    $mdp = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', cleMotsDePasse(), OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));
    return $mdp === false ? null : $mdp;
}

/** Nouveau mot de passe généré pour un compte : ses sessions ouvertes sont révoquées. */
function definirMotDePasseCompte(PDO $pdo, int $id, string $username): string
{
    $mdp = estCompteSysteme($username) ? MDP_COMPTE_SYSTEME : genererMotDePasse($username);
    $pdo->prepare('UPDATE users SET password_hash = ?, mdp_chiffre = ?, doit_changer_mdp = 0, session_version = session_version + 1 WHERE id = ?')
        ->execute([password_hash($mdp, PASSWORD_DEFAULT), chiffrerMotDePasse($mdp), $id]);
    return $mdp;
}

/** Message affiché à celui qui reçoit un mot de passe généré. */
function flashIdentifiants(string $intro, string $username, string $mdp): void
{
    flash('success', $intro . ' Identifiant : ' . $username . ' · Mot de passe : ' . $mdp . ' (notez-le maintenant).', true);
}
