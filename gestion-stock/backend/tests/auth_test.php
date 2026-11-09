<?php

declare(strict_types=1);

// Tests d'intégration de l'authentification et des permissions (scénario E).
// Exécutés dans le conteneur PHP contre la pile réelle, via Caddy :
//   bash scripts/run-tests.sh
// Les comptes de test utilisent le domaine réservé test.invalid et sont supprimés à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$student = 'eleve.test@test.invalid';
$outsider = 'intrus@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
    $pdo->exec("DELETE FROM roles WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM login_tokens WHERE email LIKE '%@test.invalid'");
}
cleanup();

echo "== Accès anonyme\n";
$anon = new ApiClient($base);
status('GET /auth/me sans session', $anon->request('GET', '/api/auth/me'), 401);
status('GET /users sans session', $anon->request('GET', '/api/users'), 401);
status('POST /roles sans session', $anon->request('POST', '/api/roles', ['name' => 'Test anonyme']), 401);
$r = $anon->request('GET', '/api/auth/link?token=' . str_repeat('a', 64));
check('Lien inventé refusé', $r['status'] === 302 && location($r) === '/login?error=invalid_link', location($r));
check('Aucune session créée', $anon->session === null);

echo "== Connexion administrateur\n";
$admin = new ApiClient($base);
$token = LoginLinks::create($adminEmail);
$r = $admin->login($token);
check('Lien valide -> redirection vers /', $r['status'] === 302 && location($r) === '/', location($r));
$cookie = implode(' ', $r['headers']['set-cookie'] ?? []);
check('Cookie HttpOnly et SameSite=Lax', stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=lax') !== false, $cookie);
$me = $admin->request('GET', '/api/auth/me');
status('GET /auth/me', $me, 200);
check('Permission system.admin', in_array('system.admin', $me['body']['permissions'] ?? [], true));
$reuse = new ApiClient($base);
$r = $reuse->login($token);
check('Lien déjà utilisé refusé', location($r) === '/login?error=invalid_link' && $reuse->session === null, location($r));

echo "== Liste d'autorisation\n";
$r = (new ApiClient($base))->login(LoginLinks::create($outsider));
check('Adresse non autorisée refusée', location($r) === '/login?error=not_allowed', location($r));
status('POST /allowlist sans jeton CSRF', $admin->request('POST', '/api/allowlist', ['value' => $student], withCsrf: false), 403);
status('POST /allowlist depuis une autre origine', $admin->request('POST', '/api/allowlist', ['value' => $student], ['Origin' => 'http://evil.example']), 403);
status('POST /allowlist valeur invalide', $admin->request('POST', '/api/allowlist', ['value' => 'pas un domaine']), 422);
status('POST /allowlist adresse élève', $admin->request('POST', '/api/allowlist', ['value' => $student, 'note' => 'test']), 201);
status('POST /allowlist doublon', $admin->request('POST', '/api/allowlist', ['value' => $student]), 422);

echo "== Compte Élève\n";
$eleve = new ApiClient($base);
$r = $eleve->login(LoginLinks::create($student));
check('Connexion élève', location($r) === '/' && $eleve->session !== null, location($r));
$me = $eleve->request('GET', '/api/auth/me');
check('Rôle par défaut Élève', ($me['body']['user']['role']['name'] ?? '') === 'Élève', $me['body']);
check('Pas de permission users.manage', !in_array('users.manage', $me['body']['permissions'] ?? [], true));
$studentId = (int) ($me['body']['user']['id'] ?? 0);
status('Élève GET /users', $eleve->request('GET', '/api/users'), 403);
status('Élève GET /roles', $eleve->request('GET', '/api/roles'), 403);
status('Élève GET /permissions', $eleve->request('GET', '/api/permissions'), 403);
status("Élève s'attribue le rôle administrateur", $eleve->request('PATCH', "/api/users/{$studentId}", ['role_id' => 1]), 403);
status('Élève crée un rôle', $eleve->request('POST', '/api/roles', ['name' => 'Test pirate', 'permissions' => ['system.admin']]), 403);
status('Élève supprime un rôle', $eleve->request('DELETE', '/api/roles/2'), 403);
status('Élève modifie la liste', $eleve->request('POST', '/api/allowlist', ['value' => 'gmail.com']), 403);
status('Élève avec faux jeton CSRF', $eleve->request('PATCH', "/api/users/{$studentId}", ['role_id' => 1], ['X-CSRF-Token' => str_repeat('0', 64)], false), 403);
$role = Database::connection()->query("SELECT role_id FROM users WHERE email = '{$student}'")->fetchColumn();
check('Rôle inchangé en base', (int) $role === 4, $role);

echo "== Gestion des rôles\n";
$r = $admin->request('POST', '/api/roles', ['name' => 'Test labo', 'description' => 'essai', 'permissions' => ['rooms.view', 'stock.view']]);
status('Créer un rôle', $r, 201);
$roleId = (int) ($r['body']['role']['id'] ?? 0);
status('Nom déjà utilisé', $admin->request('POST', '/api/roles', ['name' => 'Test labo']), 422);
status('Permission inconnue', $admin->request('POST', '/api/roles', ['name' => 'Test x', 'permissions' => ['n.existe.pas']]), 422);
$r = $admin->request('PATCH', "/api/roles/{$roleId}", ['permissions' => ['rooms.view', 'stock.view', 'stock.take']]);
check('Modifier les permissions', $r['status'] === 200 && count($r['body']['role']['permissions'] ?? []) === 3, $r['body']);
status('Attribuer le rôle à l\'élève', $admin->request('PATCH', "/api/users/{$studentId}", ['role_id' => $roleId]), 200);
$me = $eleve->request('GET', '/api/auth/me');
check('Nouvelles permissions effectives immédiatement', in_array('stock.take', $me['body']['permissions'] ?? [], true) && ($me['body']['user']['role']['name'] ?? '') === 'Test labo');
status('Supprimer un rôle attribué', $admin->request('DELETE', "/api/roles/{$roleId}"), 409);
status('Rendre le rôle Élève', $admin->request('PATCH', "/api/users/{$studentId}", ['role_id' => 4]), 200);
status('Supprimer le rôle libre', $admin->request('DELETE', "/api/roles/{$roleId}"), 204);
status('Supprimer le rôle par défaut', $admin->request('DELETE', '/api/roles/4'), 409);

echo "== Protection du dernier administrateur\n";
$adminId = (int) $admin->request('GET', '/api/auth/me')['body']['user']['id'];
$admins = (int) Database::connection()->query(
    "SELECT COUNT(*) FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id AND rp.permission_code = 'system.admin' WHERE u.is_active = 1"
)->fetchColumn();
status('Se désactiver soi-même', $admin->request('PATCH', "/api/users/{$adminId}", ['is_active' => false]), 409);
if ($admins === 1) {
    status('Se retirer le rôle administrateur', $admin->request('PATCH', "/api/users/{$adminId}", ['role_id' => 4]), 409);
} else {
    echo "  (ignoré : {$admins} administrateurs actifs, le test du dernier administrateur suppose un seul)\n";
}
status('Modifier les permissions du rôle protégé', $admin->request('PATCH', '/api/roles/1', ['permissions' => ['rooms.view']]), 409);
status('Supprimer le rôle protégé', $admin->request('DELETE', '/api/roles/1'), 409);
$ownEntry = Database::connection()->prepare("SELECT id FROM allowlist WHERE kind = 'email' AND value = ?");
$ownEntry->execute([$adminEmail]);
if (($entryId = $ownEntry->fetchColumn()) !== false && (getenv('BOOTSTRAP_ADMIN_EMAIL') ?: '') === '') {
    status('Retirer sa propre adresse de la liste', $admin->request('DELETE', "/api/allowlist/{$entryId}"), 409);
}
status('Identifiant non numérique', $admin->request('PATCH', '/api/users/abc', ['is_active' => true]), 404);
status('Type de champ invalide', $admin->request('PATCH', "/api/users/{$studentId}", ['is_active' => 'non']), 422);

echo "== Désactivation\n";
status("Désactiver l'élève", $admin->request('PATCH', "/api/users/{$studentId}", ['is_active' => false]), 200);
status('Session élève révoquée', $eleve->request('GET', '/api/auth/me'), 401);
$r = (new ApiClient($base))->login(LoginLinks::create($student));
check('Compte désactivé refusé à la connexion', location($r) === '/login?error=disabled', location($r));

echo "== Déconnexion\n";
status('POST /auth/logout', $admin->request('POST', '/api/auth/logout'), 204);
status('Session administrateur terminée', $admin->request('GET', '/api/auth/me'), 401);

echo "== Journal d'audit\n";
$audit = Database::connection()->query(
    "SELECT action, COUNT(*) FROM audit_log WHERE created_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE GROUP BY action"
)->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['auth.login', 'auth.denied', 'auth.logout', 'role.created', 'role.deleted', 'user.updated', 'allowlist.added'] as $action) {
    check("Audit « {$action} »", isset($audit[$action]));
}

echo "== Limitation des tentatives\n";
// Compteur dédié : les tests suivants ne doivent pas être bloqués
$bucket = 'test-bucket-' . bin2hex(random_bytes(4));
for ($i = 0; $i < 20; $i++) {
    \App\Auth\RateLimiter::hit($bucket, 600);
}
try {
    \App\Auth\RateLimiter::assertAllowed($bucket, 20, 600);
    check('Blocage après 20 échecs', false);
} catch (\App\Http\HttpException $e) {
    check('Blocage après 20 échecs (429)', $e->status === 429);
}
Database::connection()->prepare("DELETE FROM rate_limits WHERE bucket = ?")->execute([$bucket]);

cleanup();
finish();
