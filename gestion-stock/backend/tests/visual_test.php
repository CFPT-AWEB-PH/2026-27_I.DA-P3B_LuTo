<?php

declare(strict_types=1);

// Tests de l'armoire à tiroirs et des objets affichés en 3D : pile de tiroirs nommés, grille de bacs
// posée au fond, apparence des objets, contenu renvoyé avec la salle selon les permissions.
// Données de test : salle et rôle « Test … », objets « TST3D-… », compte @test.invalid ; supprimés à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$viewerEmail = 'lecteur.3d@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $items = "SELECT id FROM items WHERE reference LIKE 'TST3D-%'";
    $pdo->exec("DELETE FROM stock_movements WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM stock_levels WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM items WHERE reference LIKE 'TST3D-%'");
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'Test 3D%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM roles WHERE name LIKE 'Test 3D%'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
}
cleanup();

$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
$types = [];
foreach ($admin->request('GET', '/api/storage-types')['body']['types'] as $t) {
    $types[$t['name']] = $t['id'];
}
$r = $admin->request('POST', '/api/rooms', ['name' => 'Test 3D atelier', 'outline' => [[0, 0], [5000, 0], [5000, 5000], [0, 5000]],
    'height_mm' => 2800, 'door_offset_mm' => 300, 'door_width_mm' => 900, 'door_height_mm' => 2000]);
$roomId = $r['body']['room']['id'];
$cabinetId = $admin->request('POST', '/api/storage-units', ['room_id' => $roomId, 'type_id' => $types['Armoire'], 'name' => 'Armoire à tiroirs',
    'width_mm' => 1000, 'depth_mm' => 750, 'height_mm' => 1000, 'pos_x_mm' => 1500])['body']['unit']['id'];
$units = static function () use ($admin, $roomId): array {
    $byName = [];
    foreach ($admin->request('GET', "/api/rooms/{$roomId}")['body']['units'] as $u) {
        $byName[$u['name']] = $u;
    }
    return $byName;
};

echo "== Pile de tiroirs nommés\n";
$url = "/api/storage-units/{$cabinetId}/generate";
status('Pile plus haute que l\'armoire', $admin->request('POST', $url, ['type_id' => $types['Tiroir'], 'stack' => [['name' => 'A', 'height_mm' => 2000]]]), 422);
status('Pile vide', $admin->request('POST', $url, ['type_id' => $types['Tiroir'], 'stack' => []]), 422);
status('Tiroir sans nom', $admin->request('POST', $url, ['type_id' => $types['Tiroir'], 'stack' => [['height_mm' => 100]]]), 422);
$r = $admin->request('POST', $url, ['type_id' => $types['Tiroir'], 'stack' => [
    ['name' => 'Visserie', 'height_mm' => 100], ['name' => 'Résistances 1 %', 'height_mm' => 100], ['name' => 'Câbles', 'height_mm' => 300],
]]);
check('3 tiroirs créés', $r['status'] === 201 && $r['body']['created'] === 3, $r['body']);
$u = $units();
// Intérieur : 1000 − 2×19 = 962 ; 750 − 19 = 731 ; 1000 − 2×19 = 962
check('Premier tiroir en haut', ($u['Visserie']['pos_y_mm'] ?? 0) === 862 && $u['Visserie']['height_mm'] === 100, $u['Visserie'] ?? null);
check('Hauteurs différentes empilées', ($u['Câbles']['pos_y_mm'] ?? 0) === 462 && $u['Câbles']['height_mm'] === 300, $u['Câbles'] ?? null);
check('Largeur et profondeur de l\'intérieur', $u['Visserie']['width_mm'] === 962 && $u['Visserie']['depth_mm'] === 731);

echo "== Grille de bacs au fond du tiroir\n";
$drawerId = $u['Visserie']['id'];
$r = $admin->request('POST', "/api/storage-units/{$drawerId}/generate", ['type_id' => $types['Bac'], 'rows' => 2, 'columns' => 3, 'labels' => ['M3 x 12', 'M4 x 6']]);
check('6 bacs créés', $r['status'] === 201 && $r['body']['created'] === 6, $r['body']);
$u = $units();
check('Bacs posés au fond, même hauteur', ($u['Bac 1.1']['pos_y_mm'] ?? -1) === 0 && $u['Bac 2.3']['pos_y_mm'] === 0 && $u['Bac 1.1']['height_mm'] === $u['Bac 2.3']['height_mm']);
check('Rangées de l\'arrière vers l\'avant', $u['Bac 1.1']['pos_z_mm'] === 0 && $u['Bac 2.1']['pos_z_mm'] === $u['Bac 1.1']['depth_mm']);
check('Bacs moins hauts que le tiroir', $u['Bac 1.1']['height_mm'] <= 88, $u['Bac 1.1']['height_mm']);
check('Étiquettes dans l\'ordre de lecture', $u['Bac 1.1']['label'] === 'M3 x 12' && $u['Bac 1.2']['label'] === 'M4 x 6' && $u['Bac 1.3']['label'] === '');

echo "== Apparence 3D des objets\n";
$binId = $u['Bac 1.1']['id'];
status('Modèle inconnu', $admin->request('POST', '/api/items', ['reference' => 'TST3D-X', 'name' => 'X', 'visual' => 'licorne']), 422);
status('Couleur invalide', $admin->request('POST', '/api/items', ['reference' => 'TST3D-X', 'name' => 'X', 'visual_color' => 'rouge']), 422);
$r = $admin->request('POST', '/api/items', ['reference' => 'TST3D-VIS', 'name' => 'Vis M3 x 12', 'unit_id' => $binId, 'initial_stock' => 40]);
check('Apparence automatique par défaut', ($r['body']['item']['visual'] ?? '') === 'auto' && $r['body']['item']['visual_color'] === null, $r['body']['item'] ?? null);
$itemId = $r['body']['item']['id'];
$r = $admin->request('PATCH', "/api/items/{$itemId}", ['visual' => 'screw', 'visual_color' => '#AABBCC']);
check('Modèle et couleur enregistrés', ($r['body']['item']['visual'] ?? '') === 'screw' && $r['body']['item']['visual_color'] === '#aabbcc', $r['body']['item'] ?? null);
$r = $admin->request('PATCH', "/api/items/{$itemId}", ['visual_color' => null]);
check('Couleur retirée', $r['status'] === 200 && $r['body']['item']['visual_color'] === null && $r['body']['item']['visual'] === 'screw', $r['body']);
$found = $admin->request('GET', '/api/items?q=TST3D-VIS')['body']['items'][0] ?? [];
check('Apparence dans les résultats de recherche', ($found['visual'] ?? '') === 'screw');

echo "== Contenu renvoyé avec la salle\n";
$room = $admin->request('GET', "/api/rooms/{$roomId}")['body'];
$content = $room['contents'][0] ?? [];
check('Objet, quantité et apparence', count($room['contents'] ?? []) === 1 && $content['unit_id'] === $binId && $content['item_id'] === $itemId && $content['quantity'] == 40 && $content['visual'] === 'screw', $room['contents'] ?? null);

$role = $admin->request('POST', '/api/roles', ['name' => 'Test 3D salles seules', 'permissions' => ['rooms.view']])['body']['role']['id'];
$admin->request('POST', '/api/allowlist', ['value' => $viewerEmail]);
$viewer = new ApiClient($base);
$viewer->login(LoginLinks::create($viewerEmail));
$viewerId = $viewer->request('GET', '/api/auth/me')['body']['user']['id'];
$admin->request('PATCH', "/api/users/{$viewerId}", ['role_id' => $role]);
$room = $viewer->request('GET', "/api/rooms/{$roomId}");
status('Sans stock.view : salle visible', $room, 200);
check('Sans stock.view : aucun objet renvoyé', !isset($room['body']['contents']) && !isset($room['body']['stock']));
status('Sans storage.edit : génération refusée', $viewer->request('POST', "/api/storage-units/{$u['Câbles']['id']}/generate", ['type_id' => $types['Bac'], 'rows' => 1, 'columns' => 1]), 403);

$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
