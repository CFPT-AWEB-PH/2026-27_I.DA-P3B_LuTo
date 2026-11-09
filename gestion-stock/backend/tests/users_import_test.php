<?php

declare(strict_types=1);

// Tests de l'import de comptes et de la connexion par identifiant : identifiants uniques, doublons,
// mot de passe jamais stocké en clair, personne ne change le sien, limitation des essais.
// Données de test : personnes fictives « Zztest », groupe « TEST-IMPORT » ; supprimées à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';

function cleanup(): void
{
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM users WHERE user_group = 'TEST-IMPORT'");
    $pdo->exec("DELETE FROM rate_limits WHERE bucket LIKE 'password-%'");
}
cleanup();

$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
$roles = [];
foreach ($admin->request('GET', '/api/roles')['body']['roles'] as $r) {
    $roles[$r['name']] = $r['id'];
}
$people = [
    ['first_name' => 'ÉLODIE MARIE', 'last_name' => 'ZZTEST DA SILVA', 'group' => 'TEST-IMPORT'],
    ['first_name' => 'Luc', 'last_name' => 'Zztest', 'group' => 'TEST-IMPORT'],
    ['first_name' => 'Luc', 'last_name' => 'Zz-Test', 'group' => 'TEST-IMPORT'],
];

echo "== Import\n";
status('Rôle administrateur refusé', $admin->request('POST', '/api/users/import', ['role_id' => $roles['Administrateur'], 'people' => $people]), 422);
status('Liste vide refusée', $admin->request('POST', '/api/users/import', ['role_id' => $roles['Élève'], 'people' => []]), 422);
status('Personne sans nom refusée', $admin->request('POST', '/api/users/import', ['role_id' => $roles['Élève'], 'people' => [['first_name' => 'X', 'group' => 'TEST-IMPORT']]]), 422);
$r = $admin->request('POST', '/api/users/import', ['role_id' => $roles['Élève'], 'people' => $people]);
status('Import', $r, 201);
$created = $r['body']['created'];
check('3 comptes créés', count($created) === 3, $r['body']);
check('Identifiant sans accents ni espaces', $created[0]['username'] === 'elodie.zztestdasilva', $created[0]['username']);
check('Nom d\'affichage', $created[0]['display_name'] === 'Élodie Marie Zztest Da Silva', $created[0]['display_name']);
check('Identifiants uniques malgré des noms proches', $created[1]['username'] === 'luc.zztest' && $created[2]['username'] === 'luc.zztest2', array_column($created, 'username'));
check('Mots de passe différents et lisibles', count(array_unique(array_column($created, 'password'))) === 3 && preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $created[0]['password']) === 1);

$r = $admin->request('POST', '/api/users/import', ['role_id' => $roles['Élève'], 'people' => $people]);
check('Second import : personne n\'est recréé ni modifié', $r['status'] === 201 && $r['body']['created'] === [] && count($r['body']['skipped']) === 3, $r['body']);

$stored = Database::connection()->query("SELECT password_hash, email FROM users WHERE username = 'luc.zztest'")->fetch();
check('Mot de passe stocké seulement en empreinte', !str_contains($stored['password_hash'], $created[1]['password']) && password_verify($created[1]['password'], $stored['password_hash']));
check('Compte sans adresse e-mail', $stored['email'] === null);
$audit = Database::connection()->query("SELECT details FROM audit_log WHERE action IN ('user.imported', 'user.password_reset') ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
check('Aucun mot de passe dans le journal', !str_contains(implode(' ', array_map('strval', $audit)), $created[1]['password']));

$list = $admin->request('GET', '/api/users?group=TEST-IMPORT')['body'];
check('Filtre par groupe', count($list['users']) === 3 && in_array('TEST-IMPORT', $list['groups'], true));
check('Aucun mot de passe renvoyé par la liste', !str_contains(json_encode($list), 'password_hash') && !str_contains(json_encode($list), $created[1]['password']));

echo "== Connexion\n";
$anon = new ApiClient($base);
status('Mauvais mot de passe', $anon->request('POST', '/api/auth/login', ['username' => 'luc.zztest', 'password' => 'faux-faux']), 401);
status('Compte inconnu : même réponse', $anon->request('POST', '/api/auth/login', ['username' => 'personne.inconnue', 'password' => 'faux-faux']), 401);
status('Champs manquants', $anon->request('POST', '/api/auth/login', ['username' => 'luc.zztest']), 422);
$student = new ApiClient($base);
$r = $student->request('POST', '/api/auth/login', ['username' => 'LUC.ZZTEST', 'password' => $created[1]['password']]);
status('Bon mot de passe (majuscules acceptées dans l\'identifiant)', $r, 200);
$me = $student->request('GET', '/api/auth/me');
status('Session ouverte', $me, 200);
check('Rôle Élève et nom', $me['body']['user']['role']['name'] === 'Élève' && $me['body']['user']['display_name'] === 'Luc Zztest', $me['body']['user'] ?? null);
$student->csrf = $me['body']['csrf_token'];
status('Élève : consulter le plan', $student->request('GET', '/api/plan'), 200);
$studentId = $me['body']['user']['id'];

echo "== Une personne ne change pas son mot de passe\n";
status('Élève : son propre mot de passe refusé', $student->request('POST', "/api/users/{$studentId}/password", ['password' => 'nouveau-secret']), 403);
status('Élève : import refusé', $student->request('POST', '/api/users/import', ['role_id' => $roles['Élève'], 'people' => $people]), 403);
status('Élève : changer son nom ou son rôle refusé', $student->request('PATCH', "/api/users/{$studentId}", ['role_id' => $roles['Administrateur']]), 403);

echo "== Le gestionnaire change le mot de passe\n";
status('Mot de passe trop court', $admin->request('POST', "/api/users/{$studentId}/password", ['password' => 'court']), 422);
$r = $admin->request('POST', "/api/users/{$studentId}/password", []);
status('Nouveau mot de passe généré', $r, 200);
check('Fiche complète', $r['body']['username'] === 'luc.zztest' && $r['body']['password'] !== $created[1]['password']);
status('Sessions de la personne fermées', $student->request('GET', '/api/auth/me'), 401);
$again = new ApiClient($base);
status('Ancien mot de passe refusé', $again->request('POST', '/api/auth/login', ['username' => 'luc.zztest', 'password' => $created[1]['password']]), 401);
status('Nouveau mot de passe accepté', $again->request('POST', '/api/auth/login', ['username' => 'luc.zztest', 'password' => $r['body']['password']]), 200);
status('Mot de passe choisi', $admin->request('POST', "/api/users/{$created[2]['id']}/password", ['password' => 'mot-de-passe-choisi']), 200);
status('Compte e-mail : pas de mot de passe', $admin->request('POST', '/api/users/' . $admin->request('GET', '/api/auth/me')['body']['user']['id'] . '/password', []), 409);

$disabled = new ApiClient($base);
$admin->request('PATCH', "/api/users/{$created[0]['id']}", ['is_active' => false]);
status('Compte désactivé', $disabled->request('POST', '/api/auth/login', ['username' => $created[0]['username'], 'password' => $created[0]['password']]), 403);

echo "== Limitation des essais\n";
Database::connection()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'password-%'");
$attacker = new ApiClient($base);
for ($i = 0; $i < 10; $i++) {
    $attacker->request('POST', '/api/auth/login', ['username' => 'luc.zztest2', 'password' => "essai-{$i}"]);
}
status('Compte bloqué après 10 échecs, même avec le bon mot de passe', $attacker->request('POST', '/api/auth/login', ['username' => 'luc.zztest2', 'password' => 'mot-de-passe-choisi']), 429);

$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
