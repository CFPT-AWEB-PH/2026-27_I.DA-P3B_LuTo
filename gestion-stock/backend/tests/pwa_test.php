<?php

declare(strict_types=1);

// Tests du tableau de bord (selon les permissions) et de ce que le serveur fournit à la PWA :
// manifeste, service worker versionné, icônes, en-têtes de cache et de sécurité.
// Données de test : compte élève en @test.invalid, supprimé à la fin.

use App\Auth\LoginLinks;
use App\Config;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$studentEmail = 'eleve.pwa@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
}
cleanup();

/** @return array{status: int, headers: array<string, string>, body: string} */
function fetchRaw(string $url): array
{
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
    $headers = [];
    foreach (array_slice($http_response_header ?? [], 1) as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $headers[strtolower($name)] = $value;
    }
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $headers, 'body' => (string) $body];
}

echo "== Tableau de bord\n";
status('Sans session', (new ApiClient($base))->request('GET', '/api/dashboard'), 401);
$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
$r = $admin->request('GET', '/api/dashboard');
status('Administrateur', $r, 200);
check('Tous les blocs pour un administrateur', isset($r['body']['spaces'], $r['body']['stock'], $r['body']['activity']), $r['body']);
$expected = (int) Database::connection()->query('SELECT COUNT(*) FROM items WHERE is_active = 1')->fetchColumn();
check('Nombre d\'objets exact', ($r['body']['stock']['items'] ?? -1) === $expected, $r['body']['stock'] ?? null);

$admin->request('POST', '/api/allowlist', ['value' => $studentEmail]);
$student = new ApiClient($base);
$student->login(LoginLinks::create($studentEmail));
$r = $student->request('GET', '/api/dashboard');
status('Élève', $r, 200);
check('Élève : salles et stock', isset($r['body']['spaces'], $r['body']['stock']));
check('Élève : aucune activité globale (permission history.view)', !isset($r['body']['activity']), $r['body']);

echo "== Manifeste\n";
$r = fetchRaw("{$base}/manifest.webmanifest");
check('Manifeste servi', $r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'application/manifest+json'), $r['headers']);
$manifest = json_decode($r['body'], true);
check('Manifeste installable', ($manifest['start_url'] ?? '') === '/' && ($manifest['display'] ?? '') === 'standalone' && !empty($manifest['name']), $manifest);
$sizes = [];
foreach ($manifest['icons'] ?? [] as $icon) {
    $sizes[$icon['sizes'] . '/' . ($icon['purpose'] ?? 'any')] = $icon['src'];
    $png = fetchRaw($base . $icon['src']);
    check("Icône {$icon['src']}", $png['status'] === 200 && str_starts_with($png['body'], "\x89PNG"));
}
check('Icônes 192, 512 et masquable', isset($sizes['192x192/any'], $sizes['512x512/any'], $sizes['512x512/maskable']), array_keys($sizes));

echo "== Service worker\n";
$r = fetchRaw("{$base}/sw.js");
check('Servi en JavaScript', $r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'javascript'), $r['headers']);
check('Revalidé à chaque fois (mises à jour détectées)', ($r['headers']['cache-control'] ?? '') === 'no-cache', $r['headers']['cache-control'] ?? null);
$version = Config::version();
check('Version du déploiement intégrée', !str_contains($r['body'], '__APP_VERSION__') && str_contains($r['body'], "'{$version}'"), $version);
check('Liste de préchargement générée', !str_contains($r['body'], '__PRECACHE__') && preg_match('#"/assets/js/app\.js"#', $r['body']) === 1);
preg_match_all('#"(/[^"]+)"#', $r['body'], $m);
$missing = array_values(array_filter($m[1], static fn ($path) => fetchRaw($base . $path)['status'] !== 200));
check(count($m[1]) . ' fichiers préchargés, tous disponibles', count($m[1]) > 20 && $missing === [], $missing);
check('Aucune réponse d\'API préchargée', !str_contains(implode(' ', $m[1]), '/api/'));
check('Le service worker ne touche pas à l\'API', str_contains($r['body'], "startsWith('/api/')"));

echo "== En-têtes\n";
$r = fetchRaw("{$base}/");
$csp = $r['headers']['content-security-policy'] ?? '';
check('CSP : worker et manifeste limités au site', str_contains($csp, "worker-src 'self'") && str_contains($csp, "manifest-src 'self'"), $csp);
check('Page : manifeste et icônes référencés', str_contains($r['body'], 'rel="manifest"') && str_contains($r['body'], 'apple-touch-icon'));
$r = fetchRaw("{$base}/api/health");
check('API jamais en cache', ($r['headers']['cache-control'] ?? '') === 'no-store', $r['headers']['cache-control'] ?? null);

$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
