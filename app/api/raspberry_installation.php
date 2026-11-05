<?php
/**
 * api/raspberry_installation.php : script d'installation d'un autre Raspberry, lancé par la
 * commande toute faite d'Admin › Raspberry. Authentifié par le jeton de ce Raspberry.
 * Le script contient tout : les fichiers à installer, le nom du Wi-Fi et l'accès au portail.
 */
require_once __DIR__ . '/../includes/securite.php';
initialiserErreurs();
envoyerEntetesSecurite();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/identifiants.php';
require_once __DIR__ . '/../includes/wifi.php';
require_once __DIR__ . '/../includes/raspberry.php';
ensureRaspberrySchema($pdo);

header('Cache-Control: no-store');
$r = raspberryDepuisJeton($pdo);
ensureWifiSchema($pdo);
$etat = wifiEtat($pdo);
$st = $pdo->prepare('SELECT identifiant, mdp_chiffre FROM wifi_comptes WHERE id = ?');
$st->execute([$r['wifi_compte_id']]);
$wifi = $st->fetch() ?: ['identifiant' => '', 'mdp_chiffre' => null];
$mdpWifi = dechiffrerMotDePasse($wifi['mdp_chiffre']);

header('Content-Type: text/plain; charset=utf-8');
if ($mdpWifi === null) {
    echo "#!/usr/bin/env bash\necho 'Accès Wi-Fi de ce Raspberry introuvable : supprimez-le puis ajoutez-le de nouveau dans Admin › Raspberry.' >&2\nexit 1\n";
    exit;
}

// Pas de config.php ici : sous-dossier éventuel de l'application, sous la même forme
$base = trim((string)(getenv('BASE_URL') ?: ''), '/');
$base = preg_match('#^[A-Za-z0-9_\-/]*$#', $base) && $base !== '' ? '/' . $base : '';

$fichier = fn(string $nom) => chunk_split(base64_encode(str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../raspberry/' . $nom))), 76, "\n");
$args = ['--ssid', $etat['ssid'] ?? 'Sabre-Arbitrage',
    '--serveur', ($etat['adresse'] ?? '10.42.0.1') . $base,
    '--wifi-id', $wifi['identifiant'], '--wifi-mdp', $mdpWifi,
    '--jeton', (string)$_SERVER['HTTP_X_SABRE_JETON']];
if (!empty($etat['mdp_wifi'])) array_push($args, '--cle-wifi', $etat['mdp_wifi']);
$ligne = implode(' ', array_map('escapeshellarg', $args));

// Tout est dans une fonction : bash lit le script en entier avant de lancer quoi que ce soit
echo <<<SCRIPT
#!/usr/bin/env bash
principal() {
    set -euo pipefail
    [[ \$EUID -eq 0 ]] || { echo "Lancez cette commande avec sudo." >&2; exit 1; }
    dossier=\$(mktemp -d)
    trap 'rm -rf "\$dossier"' EXIT
    mkdir "\$dossier/raspberry"
    base64 -d > "\$dossier/raspberry/sabre-fond-ecran.py" <<'FIN_FICHIER'
{$fichier('sabre-fond-ecran.py')}FIN_FICHIER
    base64 -d > "\$dossier/raspberry/install_ecran_client.sh" <<'FIN_FICHIER'
{$fichier('install_ecran_client.sh')}FIN_FICHIER
    bash "\$dossier/raspberry/install_ecran_client.sh" {$ligne} < /dev/null
}
principal

SCRIPT;
