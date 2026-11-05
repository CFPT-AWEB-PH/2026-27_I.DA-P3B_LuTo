<?php

declare(strict_types=1);

// Tests de sécurité : refus par défaut, escalade de privilèges, sessions, entrées hostiles, envoi de fichiers,
// limitation des opérations coûteuses, en-têtes. Défensifs et non destructifs : données fictives en @test.invalid,
// groupe TEST-SEC et rôles « Test Sec … », supprimés à la fin.

use App\Auth\LoginLinks;
use App\Auth\Passwords;
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
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid' OR user_group = 'TEST-SEC'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
    $pdo->exec("DELETE FROM roles WHERE name LIKE 'Test Sec %'");
    $pdo->exec("DELETE FROM login_tokens WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM items WHERE reference LIKE 'ZZSEC-%'");
    $pdo->exec("DELETE FROM custom_fields WHERE name LIKE 'Test Sec %'");
    $pdo->exec("DELETE FROM rate_limits WHERE bucket LIKE 'password-%' OR bucket LIKE 'costly-%'");
}
cleanup();

/** Requête au corps brut, pour ce que ApiClient ne sait pas envoyer (JSON cassé, mauvais type…). */
function raw(string $url, string $method, string $body, array $headers): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $content = @file_get_contents($url, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    $parsed = [];
    foreach (array_slice($http_response_header ?? [], 1) as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $parsed[strtolower($name)] = $value;
    }
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $parsed, 'body' => (string) $content];
}

function uploadPhoto(string $base, ApiClient $client, int $itemId, string $bytes, string $name, string $type): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'sec');
    file_put_contents($tmp, $bytes);
    $ch = curl_init("{$base}/api/items/{$itemId}/photo");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Cookie: gs_session=' . $client->session, 'X-CSRF-Token: ' . $client->csrf],
        CURLOPT_POSTFIELDS => ['photo' => new CURLFile($tmp, $type, $name)],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    unlink($tmp);
    return ['status' => $status, 'body' => $body];
}

/** PNG valide dont l'en-tête annonce de très grandes dimensions (bombe de décompression). */
function pngHeader(int $width, int $height): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0", 1024))) . $chunk('IEND', '');
}

$pdo = Database::connection();
$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
$adminRole = (int) $pdo->query('SELECT id FROM roles WHERE is_protected = 1')->fetchColumn();
$studentRole = (int) $pdo->query('SELECT id FROM roles WHERE is_default = 1')->fetchColumn();

$makeRole = static function (string $name, array $permissions) use ($admin): int {
    $r = $admin->request('POST', '/api/roles', ['name' => "Test Sec {$name}", 'permissions' => $permissions]);
    return (int) $r['body']['role']['id'];
};
$makeUser = static function (string $name, ?int $roleId) use ($admin, $base): ApiClient {
    $email = "{$name}@test.invalid";
    $admin->request('POST', '/api/allowlist', ['value' => $email]);
    $client = new ApiClient($base);
    $client->login(LoginLinks::create($email));
    if ($roleId !== null) {
        $id = (int) $client->request('GET', '/api/auth/me')['body']['user']['id'];
        $admin->request('PATCH', "/api/users/{$id}", ['role_id' => $roleId]);
    }
    return $client;
};
$idOf = static fn (ApiClient $c): int => (int) $c->request('GET', '/api/auth/me')['body']['user']['id'];

echo "== Refus par défaut : aucune route sans session, hors liste publique\n";
$router = require '/app/routes.php';
$routes = (new ReflectionProperty($router, 'routes'))->getValue($router);
$public = ['GET /health' => 200, 'GET /auth/config' => 200, 'GET /auth/link' => 302, 'POST /auth/login' => 422];
$anon = new ApiClient($base);
$wrong = [];
foreach ($routes as $route) {
    [$method, $regex] = $route;
    $path = (string) preg_replace('/\(\?P<\w+>[^)]+\)/', '1', substr($regex, 2, -3));
    $expected = $public["{$method} {$path}"] ?? 401;
    $r = $anon->request($method, "/api{$path}", $method === 'GET' ? null : []);
    if ($r['status'] !== $expected) {
        $wrong[] = "{$method} {$path} -> {$r['status']}";
    }
}
check(count($routes) . ' routes : 401 sans session, sauf les 4 publiques', $wrong === [], $wrong);

echo "== Escalade de privilèges par users.manage\n";
$usersRole = $makeRole('Gestion comptes', ['users.manage']);
$strongRole = $makeRole('Fort', ['users.manage', 'roles.manage']);
$manager = $makeUser('sec.manager', $usersRole);
$managerId = $idOf($manager);
$student = $makeUser('sec.eleve', null);
$studentId = $idOf($student);
$otherAdmin = $makeUser('sec.admin2', $adminRole);
$otherAdminId = $idOf($otherAdmin);

status('Se donner le rôle administrateur', $manager->request('PATCH', "/api/users/{$managerId}", ['role_id' => $adminRole]), 403);
status('Donner à autrui le rôle administrateur', $manager->request('PATCH', "/api/users/{$studentId}", ['role_id' => $adminRole]), 403);
status('Donner un rôle plus fort que le sien', $manager->request('PATCH', "/api/users/{$studentId}", ['role_id' => $strongRole]), 403);
status('Désactiver un administrateur', $manager->request('PATCH', "/api/users/{$otherAdminId}", ['is_active' => false]), 403);
status('Rétrograder un administrateur', $manager->request('PATCH', "/api/users/{$otherAdminId}", ['role_id' => $studentRole]), 403);
status('Renommer un administrateur', $manager->request('PATCH', "/api/users/{$otherAdminId}", ['display_name' => 'Pirate']), 403);
status('Importer dans un rôle plus fort', $manager->request('POST', '/api/users/import', ['role_id' => $strongRole, 'people' => [['first_name' => 'Zzsec', 'last_name' => 'Fort', 'group' => 'TEST-SEC']]]), 403);
check('Toujours gestionnaire de comptes, pas administrateur', !in_array('system.admin', $manager->request('GET', '/api/auth/me')['body']['permissions'], true));
check('Administrateur toujours actif', $otherAdmin->request('GET', '/api/auth/me')['status'] === 200);

$r = $admin->request('POST', '/api/users/import', ['role_id' => $studentRole, 'people' => [['first_name' => 'Zzsec', 'last_name' => 'Pwdadmin', 'group' => 'TEST-SEC']]]);
$pwdAdmin = $r['body']['created'][0];
$r = $admin->request('POST', '/api/users/import', ['role_id' => $strongRole, 'people' => [['first_name' => 'Zzsec', 'last_name' => 'Pwdfort', 'group' => 'TEST-SEC']]]);
$pwdStrong = $r['body']['created'][0];
$admin->request('PATCH', "/api/users/{$pwdAdmin['id']}", ['role_id' => $adminRole]);
status('Changer le mot de passe d\'un administrateur', $manager->request('POST', "/api/users/{$pwdAdmin['id']}/password", []), 403);
status('Changer le mot de passe d\'un compte plus fort', $manager->request('POST', "/api/users/{$pwdStrong['id']}/password", []), 403);

$r = $manager->request('POST', '/api/users/import', ['role_id' => $usersRole, 'people' => [['first_name' => 'Zzsec', 'last_name' => 'Legit', 'group' => 'TEST-SEC']]]);
status('Importer dans un rôle égal au sien : permis', $r, 201);
$legit = $r['body']['created'][0] ?? ['id' => 0];
status('Modifier un compte de même niveau : permis', $manager->request('PATCH', "/api/users/{$legit['id']}", ['display_name' => 'Zzsec Renommé']), 200);
status('Nouveau mot de passe d\'un compte de même niveau : permis', $manager->request('POST', "/api/users/{$legit['id']}/password", []), 200);
status('L\'administrateur garde tous ses droits', $admin->request('POST', "/api/users/{$pwdStrong['id']}/password", []), 200);

echo "== Escalade de privilèges par roles.manage\n";
$rolesRole = $makeRole('Gestion roles', ['roles.manage', 'rooms.view']);
$rolesManager = $makeUser('sec.roles', $rolesRole);
status('Créer un rôle administrateur système', $rolesManager->request('POST', '/api/roles', ['name' => 'Test Sec Pirate', 'permissions' => ['system.admin']]), 403);
status('Se donner une permission de plus', $rolesManager->request('PATCH', "/api/roles/{$rolesRole}", ['permissions' => ['roles.manage', 'rooms.view', 'users.manage']]), 403);
status('Modifier un rôle plus fort', $rolesManager->request('PATCH', "/api/roles/{$strongRole}", ['description' => 'piraté']), 403);
status('Renommer le rôle administrateur', $rolesManager->request('PATCH', "/api/roles/{$adminRole}", ['name' => 'Test Sec Renommé']), 403);
status('Supprimer un rôle plus fort', $rolesManager->request('DELETE', "/api/roles/{$usersRole}"), 403);
status('Rôle administrateur par défaut', $rolesManager->request('PATCH', "/api/roles/{$adminRole}", ['is_default' => true]), 403);
$r = $rolesManager->request('POST', '/api/roles', ['name' => 'Test Sec Permis', 'permissions' => ['rooms.view']]);
status('Créer un rôle dans ses droits : permis', $r, 201);

status('Admin : rôle protégé par défaut refusé', $admin->request('PATCH', "/api/roles/{$adminRole}", ['is_default' => true]), 409);
status('Admin : rôle d\'administration par défaut refusé', $admin->request('PATCH', "/api/roles/{$usersRole}", ['is_default' => true]), 409);
status('Admin : créer un rôle d\'administration par défaut refusé', $admin->request('POST', '/api/roles', ['name' => 'Test Sec Defaut', 'permissions' => ['roles.manage'], 'is_default' => true]), 409);
status('Admin : droits d\'administration sur le rôle par défaut refusés', $admin->request('PATCH', "/api/roles/{$studentRole}", ['permissions' => ['rooms.view', 'stock.view', 'stock.take', 'users.manage']]), 409);
check('Le rôle par défaut n\'a pas changé', (int) $pdo->query('SELECT id FROM roles WHERE is_default = 1')->fetchColumn() === $studentRole);

echo "== Connexion et sessions\n";
$r = $admin->request('POST', '/api/users/import', ['role_id' => $studentRole, 'people' => [['first_name' => 'Zzsec', 'last_name' => 'Variante', 'group' => 'TEST-SEC']]]);
$variant = $r['body']['created'][0];
$login = new ApiClient($base);
$accented = str_replace(['z', 'e'], ['ž', 'é'], $variant['username']);
status('Variante accentuée de l\'identifiant refusée', $login->request('POST', '/api/auth/login', ['username' => $accented, 'password' => $variant['password']]), 401);
status('Identifiant trop long', $login->request('POST', '/api/auth/login', ['username' => str_repeat('a', 61), 'password' => 'x']), 422);
status('Mot de passe de type incorrect', $login->request('POST', '/api/auth/login', ['username' => 'a', 'password' => ['x']]), 422);
status('Mot de passe de plus de 72 octets refusé à la création', $admin->request('POST', "/api/users/{$variant['id']}/password", ['password' => str_repeat('a', 73)]), 422);
status('Mot de passe identique à l\'identifiant refusé', $admin->request('POST', "/api/users/{$variant['id']}/password", ['password' => $variant['username']]), 422);
$denied = $pdo->query("SELECT details FROM audit_log WHERE action = 'auth.denied' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
check('Le journal ne garde pas un identifiant mal formé (peut-être un mot de passe)', !str_contains(implode(' ', $denied), 'žž') && !str_contains(implode(' ', $denied), $accented));

$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($variant['password'], PASSWORD_BCRYPT, ['cost' => 4]), $variant['id']]);
$login = new ApiClient($base);
status('Connexion avec une empreinte ancienne', $login->request('POST', '/api/auth/login', ['username' => $variant['username'], 'password' => $variant['password']]), 200);
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$variant['id']]);
check('Empreinte remise au niveau actuel à la connexion', !password_needs_rehash((string) $stmt->fetchColumn(), PASSWORD_DEFAULT));

$rotating = new ApiClient($base);
$rotating->login(LoginLinks::create('sec.eleve@test.invalid'));
$old = $rotating->session;
$rotating->login(LoginLinks::create('sec.eleve@test.invalid'));
check('Une nouvelle connexion change le jeton', $old !== $rotating->session);
$check = new ApiClient($base);
$check->session = $old;
status('La session remplacée ne vaut plus', $check->request('GET', '/api/auth/me'), 401);

$forged = new ApiClient($base);
$forged->session = str_repeat('a', 64);
status('Jeton inventé', $forged->request('GET', '/api/auth/me'), 401);
$forged->session = "../../etc/passwd";
status('Jeton mal formé', $forged->request('GET', '/api/auth/me'), 401);
$expiring = new ApiClient($base);
$expiring->login(LoginLinks::create('sec.eleve@test.invalid'));
$pdo->prepare('UPDATE sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([hash('sha256', (string) $expiring->session)]);
status('Session expirée', $expiring->request('GET', '/api/auth/me'), 401);
$idle = new ApiClient($base);
$idle->login(LoginLinks::create('sec.eleve@test.invalid'));
$pdo->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE id = ?')->execute([hash('sha256', (string) $idle->session)]);
status('Session inactive trop longtemps', $idle->request('GET', '/api/auth/me'), 401);
$out = new ApiClient($base);
$out->login(LoginLinks::create('sec.eleve@test.invalid'));
$kept = $out->session;
$out->request('POST', '/api/auth/logout');
$out->session = $kept;
status('Jeton réutilisé après déconnexion', $out->request('GET', '/api/auth/me'), 401);

echo "== Liste d'autorisation\n";
$adminEntry = array_values(array_filter($admin->request('GET', '/api/allowlist')['body']['entries'], static fn ($e) => $e['value'] === 'sec.admin2@test.invalid'))[0];
status('Gestionnaire limité : retirer l\'adresse d\'un administrateur refusé', $manager->request('DELETE', "/api/allowlist/{$adminEntry['id']}"), 403);
check('L\'entrée est toujours là', in_array('sec.admin2@test.invalid', array_column($admin->request('GET', '/api/allowlist')['body']['entries'], 'value'), true));
status('L\'administrateur reste connecté', $otherAdmin->request('GET', '/api/auth/me'), 200);
$temp = $makeUser('sec.temporaire', null);
status('Session ouverte avant le retrait', $temp->request('GET', '/api/auth/me'), 200);
$entries = $admin->request('GET', '/api/allowlist')['body']['entries'];
$entry = array_values(array_filter($entries, static fn ($e) => $e['value'] === 'sec.temporaire@test.invalid'))[0];
$passwordAdmin = new ApiClient($base);
$reset = $admin->request('POST', "/api/users/{$pwdAdmin['id']}/password", []);
$passwordAdmin->request('POST', '/api/auth/login', ['username' => $reset['body']['username'], 'password' => $reset['body']['password']]);
$passwordAdmin->csrf = $passwordAdmin->request('GET', '/api/auth/me')['body']['csrf_token'] ?? null;
status('Un administrateur sans e-mail peut retirer une entrée', $passwordAdmin->request('DELETE', "/api/allowlist/{$entry['id']}"), 204);
status('Le retrait de la liste ferme la session', $temp->request('GET', '/api/auth/me'), 401);

echo "== Données d'autrui\n";
status('Élève : historique refusé', $student->request('GET', '/api/movements'), 403);
status('Élève : annuaire refusé', $student->request('GET', '/api/users/options'), 403);
status('Élève : comptes refusés', $student->request('GET', '/api/users'), 403);
status('Élève : rôles refusés', $student->request('GET', '/api/roles'), 403);
status('Élève : liste d\'autorisation refusée', $student->request('GET', '/api/allowlist'), 403);
status('Élève : paramètres en écriture refusés', $student->request('PATCH', '/api/settings', ['app.name' => 'Pirate']), 403);
$mine = $student->request('GET', '/api/loans?scope=all&user_id=1');
check('Élève : scope=all ignoré, seulement ses emprunts', array_filter($mine['body']['loans'] ?? [], static fn ($l) => $l['user']['id'] !== $idOf($student)) === []);

echo "== Entrées hostiles : jamais d'erreur 500\n";
$hostile = [
    'page énorme (objets)' => '/api/items?page=99999999999999999999999&per_page=100',
    'page énorme (mouvements)' => '/api/movements?page=9223372036854775807&per_page=200',
    'date impossible' => '/api/movements?from=2024-13-45&to=2024-99-99',
    'date suivie d\'un saut de ligne' => '/api/movements?from=2024-01-01%0A',
    'tableau au lieu de texte (q)' => '/api/items?q[]=a&sort[]=name&page[]=1',
    'tableau au lieu de texte (users)' => '/api/users?q[]=a&group[]=b&active[]=1&role_id[]=1',
    'tableau au lieu de texte (mouvements)' => '/api/movements?item_id[]=1&from[]=a&page[]=1',
    'injection SQL dans la recherche' => "/api/items?q=" . rawurlencode("' OR 1=1; DROP TABLE users; -- "),
    'opérateurs plein texte' => '/api/items?q=' . rawurlencode('+"a*( @>< ~ -b'),
    'tri inconnu' => "/api/items?sort=" . rawurlencode('name; DROP TABLE items'),
    'identifiant énorme' => '/api/items/99999999999999999999999999',
    'caractères nuls' => '/api/items?q=%00%00',
    'UTF-8 invalide' => '/api/items?q=%C3%28%FF',
];
foreach ($hostile as $label => $url) {
    $r = $admin->request('GET', $url);
    check("{$label} : pas de 500", $r['status'] > 0 && $r['status'] < 500, ['status' => $r['status'], 'body' => $r['body']]);
}
check('Tables intactes', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0);

$jsonHeaders = ['Content-Type: application/json', 'Cookie: gs_session=' . $admin->session, 'X-CSRF-Token: ' . $admin->csrf];
check('JSON cassé -> 400', raw("{$base}/api/categories", 'POST', '{"name":', $jsonHeaders)['status'] === 400);
check('Tableau au lieu d\'un objet -> 400', raw("{$base}/api/categories", 'POST', '[1,2]', $jsonHeaders)['status'] === 400);
check('JSON trop imbriqué -> 400', raw("{$base}/api/categories", 'POST', str_repeat('{"a":', 40) . '1' . str_repeat('}', 40), $jsonHeaders)['status'] === 400);
check('Corps de plus de 1 Mo -> 413', raw("{$base}/api/categories", 'POST', json_encode(['name' => str_repeat('a', 1_100_000)]), $jsonHeaders)['status'] === 413);
check('Mauvais type de contenu -> 415', raw("{$base}/api/categories", 'POST', 'name=x', ['Content-Type: application/x-www-form-urlencoded', 'Cookie: gs_session=' . $admin->session, 'X-CSRF-Token: ' . $admin->csrf])['status'] === 415);
check('Corps de plus de 6 Mo arrêté par le proxy', raw("{$base}/api/categories", 'POST', str_repeat('a', 7_000_000), $jsonHeaders)['status'] === 413);
status('Type incorrect', $admin->request('POST', '/api/categories', ['name' => ['a']]), 422);
status('Quantité négative', $admin->request('POST', '/api/stock/take', ['item_id' => 1, 'unit_id' => 1, 'quantity' => -5]), 422);
status('Quantité en texte', $admin->request('POST', '/api/stock/take', ['item_id' => 1, 'unit_id' => 1, 'quantity' => '1; DROP']), 422);
status('Couleur suivie d\'un saut de ligne', $admin->request('POST', '/api/categories', ['name' => 'Test Sec Couleur', 'color' => "#aabbcc\n"]), 422);
status('Caractères de contrôle', $admin->request('POST', '/api/categories', ['name' => "a\x07b"]), 422);
status('Caractères de contrôle dans une liste', $admin->request('POST', '/api/fields', ['name' => 'Test Sec Liste', 'field_type' => 'select', 'options' => ["a\x07"]]), 422);

$field = $admin->request('POST', '/api/fields', ['name' => 'Test Sec Nombre', 'field_type' => 'number']);
$fieldId = $field['body']['field']['id'] ?? 0;
$item = $admin->request('POST', '/api/items', ['reference' => 'ZZSEC-001', 'name' => '<img src=x onerror=alert(1)>']);
status('Objet au nom hostile accepté comme simple texte', $item, 201);
$itemId = (int) ($item['body']['item']['id'] ?? 0);
check('Rendu tel quel en JSON, à afficher comme texte', ($item['body']['item']['name'] ?? '') === '<img src=x onerror=alert(1)>');
status('Champ numérique de 600 chiffres', $admin->request('PATCH', "/api/items/{$itemId}", ['fields' => [(string) $fieldId => str_repeat('1', 600)]]), 422);

echo "== Envoi de photos\n";
foreach ([
    'texte déguisé en JPEG' => ['pas une image', 'photo.jpg', 'image/jpeg'],
    'script PHP déguisé' => ['<?php system($_GET["c"]); ?>', 'photo.php.jpg', 'image/jpeg'],
    'SVG avec script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.svg', 'image/svg+xml'],
    'en-tête PNG tronqué' => ["\x89PNG\r\n\x1a\n", 'photo.png', 'image/png'],
] as $label => [$bytes, $name, $type]) {
    $r = uploadPhoto($base, $admin, $itemId, $bytes, $name, $type);
    check("{$label} : refusé", $r['status'] === 422, $r);
}
$r = uploadPhoto($base, $admin, $itemId, pngHeader(6000, 6000), 'bombe.png', 'image/png');
check('36 mégapixels annoncés : refusé sans épuiser la mémoire', $r['status'] === 422, $r);
$r = uploadPhoto($base, $admin, $itemId, pngHeader(30000, 30000), 'bombe.png', 'image/png');
check('900 mégapixels annoncés : refusé', $r['status'] === 422, $r);
$image = imagecreatetruecolor(32, 32);
ob_start();
imagepng($image);
$valid = (string) ob_get_clean();
$r = uploadPhoto($base, $admin, $itemId, $valid, '../../../evil.php', 'image/png');
check('Image valide au nom hostile : acceptée', $r['status'] === 200, $r);
$stored = $pdo->prepare('SELECT photo_path FROM items WHERE id = ?');
$stored->execute([$itemId]);
check('Nom de fichier choisi par le serveur', (bool) preg_match('/^\d+_[a-f0-9]{16}\.webp$/', (string) $stored->fetchColumn()));
$photo = raw("{$base}/api/items/{$itemId}/photo", 'GET', '', ['Cookie: gs_session=' . $admin->session]);
check('Photo servie en WebP, sans détection de type', ($photo['headers']['content-type'] ?? '') === 'image/webp' && ($photo['headers']['x-content-type-options'] ?? '') === 'nosniff');
status('Photo inaccessible sans session', (new ApiClient($base))->request('GET', "/api/items/{$itemId}/photo"), 401);
status('Envoi refusé à un élève', ['status' => uploadPhoto($base, $student, $itemId, $valid, 'a.png', 'image/png')['status'], 'body' => null], 403);
$admin->request('DELETE', "/api/items/{$itemId}/photo");

echo "== Limitation des opérations coûteuses\n";
$window = gmdate('Y-m-d H:i:s', intdiv(time(), 600) * 600);
$pdo->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 100000) ON DUPLICATE KEY UPDATE hits = 100000')->execute(["costly-bcrypt:{$idOf($admin)}", $window]);
$pdo->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 100000) ON DUPLICATE KEY UPDATE hits = 100000')->execute(["costly-photo:{$idOf($admin)}", $window]);
status('Mots de passe : quota atteint', $admin->request('POST', "/api/users/{$pwdStrong['id']}/password", []), 429);
check('Photos : quota atteint', uploadPhoto($base, $admin, $itemId, $valid, 'a.png', 'image/png')['status'] === 429);
status('Le reste de l\'application reste utilisable', $admin->request('GET', '/api/items'), 200);
$pdo->exec("DELETE FROM rate_limits WHERE bucket LIKE 'costly-%'");

echo "== En-têtes et exposition\n";
$r = raw("{$base}/", 'GET', '', []);
$csp = $r['headers']['content-security-policy'] ?? '';
check('CSP sans script en ligne ni domaine externe', str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe') && !str_contains($csp, 'http'), $csp);
check('Isolation des fenêtres et des ressources', ($r['headers']['cross-origin-opener-policy'] ?? '') === 'same-origin' && ($r['headers']['cross-origin-resource-policy'] ?? '') === 'same-origin', $r['headers']);
check('Anti-clickjacking', ($r['headers']['x-frame-options'] ?? '') === 'DENY' && str_contains($csp, "frame-ancestors 'none'"));
check('Pas de bannière Server ni X-Powered-By', !isset($r['headers']['server']) && !isset($r['headers']['x-powered-by']), $r['headers']);
check('Pas de HSTS en HTTP (ignoré et trompeur)', !isset($r['headers']['strict-transport-security']));
$r = raw("{$base}/api/items", 'OPTIONS', '', ['Origin: http://evil.example', 'Access-Control-Request-Method: GET']);
check('Aucun CORS accordé', !isset($r['headers']['access-control-allow-origin']), $r['headers']);
$r = raw("{$base}/api/items", 'GET', '', ['Origin: http://evil.example', 'Cookie: gs_session=' . $admin->session]);
check('Aucun CORS accordé, même avec session', !isset($r['headers']['access-control-allow-origin']), $r['headers']);

$anonHealth = json_decode(raw("{$base}/api/health", 'GET', '', [])['body'], true);
check('Santé publique : l\'état seulement', array_keys($anonHealth ?? []) === ['status'], $anonHealth);
status('Santé avec détails pour l\'administrateur', $admin->request('GET', '/api/health'), 200);
check('Détails présents', isset($admin->request('GET', '/api/health')['body']['version']));
check('Élève : pas de détails de santé', array_keys($student->request('GET', '/api/health')['body'] ?? []) === ['status']);

foreach (['/index.php', '/api/../bootstrap.php', '/.env', '/backend/bootstrap.php', '/docker-compose.yml', '/.git/config'] as $path) {
    $r = raw($base . $path, 'GET', '', []);
    check("{$path} : aucun contenu serveur", !str_contains($r['body'], '<?php') && !str_contains($r['body'], 'DB_PASSWORD') && !str_contains($r['body'], '[core]'), $r['status']);
}
$r = raw("{$base}/api/n-existe-pas", 'GET', '', []);
check('404 sans détail interne', $r['status'] === 404 && !preg_match('#/app/|\.php|Stack|SQLSTATE#', $r['body']), $r['body']);
check('TRACE refusé', raw("{$base}/api/health", 'TRACE', '', [])['status'] >= 400);

$pdo->exec("DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@test.invalid' OR user_group = 'TEST-SEC')");
cleanup();
finish();
