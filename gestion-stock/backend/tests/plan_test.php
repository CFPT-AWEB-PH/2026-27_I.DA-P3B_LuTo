<?php

declare(strict_types=1);

// Tests du plan du bâtiment : étages, position des salles, arbres de rangements (copie, modèles, annulation
// d'une suppression), déplacement d'un rangement entre deux salles avec son stock.
// Données de test : salles « Test plan… », étages « Test… », modèles « Test… », objets « TSTPLAN-… » ; supprimés à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$viewerEmail = 'lecteur.plan@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $items = "SELECT id FROM items WHERE reference LIKE 'TSTPLAN-%'";
    $pdo->exec("DELETE FROM stock_movements WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM stock_levels WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM items WHERE reference LIKE 'TSTPLAN-%'");
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'Test plan%'");
    $pdo->exec("DELETE FROM floors WHERE name LIKE 'Test%'");
    $pdo->exec("DELETE FROM storage_templates WHERE name LIKE 'Test%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM roles WHERE name LIKE 'Test plan%'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
}
cleanup();

$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
$types = [];
foreach ($admin->request('GET', '/api/storage-types')['body']['types'] as $t) {
    $types[$t['name']] = $t['id'];
}
check('Nouveaux types de rangement', isset($types['Boîte plastique'], $types['Plateau à outils']), array_keys($types));

$room = static fn (string $name, array $extra = []): array => $extra + [
    'name' => $name, 'outline' => [[0, 0], [5000, 0], [5000, 4000], [0, 4000]], 'height_mm' => 2800,
    'door_offset_mm' => 300, 'door_width_mm' => 900, 'door_height_mm' => 2000,
];

echo "== Étages\n";
$r = $admin->request('POST', '/api/floors', ['name' => 'Test étage 50', 'level' => 50]);
status('Créer un étage', $r, 201);
$floorId = $r['body']['floor']['id'];
check('Étage renvoyé', $r['body']['floor']['level'] === 50 && $r['body']['floor']['height_mm'] === 3000, $r['body']);
status('Même niveau refusé', $admin->request('POST', '/api/floors', ['name' => 'Test autre', 'level' => 50]), 422);
status('Même nom refusé', $admin->request('POST', '/api/floors', ['name' => 'Test étage 50', 'level' => 51]), 422);
$r = $admin->request('PATCH', "/api/floors/{$floorId}", ['name' => 'Test étage renommé', 'height_mm' => 3500]);
check('Étage modifié', $r['status'] === 200 && $r['body']['floor']['name'] === 'Test étage renommé' && $r['body']['floor']['height_mm'] === 3500, $r['body']);

echo "== Place des salles sur le plan\n";
$r = $admin->request('POST', '/api/rooms', $room('Test plan A'));
status('Salle sans position', $r, 201);
$roomA = $r['body']['room']['id'];
check('Rangée sur un étage, pas encore placée', $r['body']['room']['floor_id'] !== null && $r['body']['room']['plan_x_mm'] === null && $r['body']['room']['plan_rotation_deg'] === 0, $r['body']['room']);
status('Étage inconnu refusé', $admin->request('PATCH', "/api/rooms/{$roomA}", ['floor_id' => 999999]), 422);
status('Position non entière refusée', $admin->request('PATCH', "/api/rooms/{$roomA}", ['plan_x_mm' => 12.5]), 422);
status('Rotation hors limites refusée', $admin->request('PATCH', "/api/rooms/{$roomA}", ['plan_rotation_deg' => 360]), 422);
$r = $admin->request('PATCH', "/api/rooms/{$roomA}", ['floor_id' => $floorId, 'plan_x_mm' => 1500, 'plan_z_mm' => -200, 'plan_rotation_deg' => 90]);
check('Salle placée', $r['status'] === 200 && $r['body']['room']['floor_id'] === $floorId && $r['body']['room']['plan_x_mm'] === 1500 && $r['body']['room']['plan_z_mm'] === -200 && $r['body']['room']['plan_rotation_deg'] === 90, $r['body']['room'] ?? null);
$r = $admin->request('PATCH', "/api/rooms/{$roomA}", ['plan_x_mm' => null]);
check('Position retirée', $r['status'] === 200 && $r['body']['room']['plan_x_mm'] === null);
$admin->request('PATCH', "/api/rooms/{$roomA}", ['plan_x_mm' => 1500]);
$roomB = $admin->request('POST', '/api/rooms', $room('Test plan B', ['floor_id' => $floorId]))['body']['room']['id'];
$r = $admin->request('POST', "/api/rooms/{$roomA}/openings", ['kind' => 'door', 'wall_index' => 1, 'offset_mm' => 100, 'width_mm' => 900, 'height_mm' => 2000]);
status('Deuxième porte', $r, 201);
$doorId = $r['body']['openings'][0]['id'];
$r = $admin->request('POST', "/api/rooms/{$roomA}/openings", ['kind' => 'door', 'wall_index' => 2, 'offset_mm' => 1500, 'width_mm' => 900, 'height_mm' => 2000]);
status('Troisième porte', $r, 201);
status('Porte déplacée sur un autre mur', $admin->request('PATCH', "/api/openings/{$doorId}", ['wall_index' => 3, 'offset_mm' => 500]), 200);
status('Porte hors du mur refusée', $admin->request('PATCH', "/api/openings/{$doorId}", ['offset_mm' => 9000]), 422);

$plan = $admin->request('GET', '/api/plan');
status('Lecture du plan', $plan, 200);
$planRoom = array_values(array_filter($plan['body']['rooms'], static fn ($x) => $x['id'] === $roomA))[0] ?? null;
check('Le plan contient étages, salles et portes', $planRoom !== null && count(array_filter($plan['body']['openings'], static fn ($o) => $o['room_id'] === $roomA)) === 2
    && in_array($floorId, array_column($plan['body']['floors'], 'id'), true));
status('Étage avec salles non supprimable', $admin->request('DELETE', "/api/floors/{$floorId}"), 409);

echo "== Arbre de rangements : copie et règles d'emplacement\n";
$cabinetId = $admin->request('POST', '/api/storage-units', ['room_id' => $roomA, 'type_id' => $types['Armoire'], 'name' => 'Armoire', 'width_mm' => 1000, 'depth_mm' => 500, 'height_mm' => 1000, 'pos_x_mm' => 2000])['body']['unit']['id'];
$admin->request('POST', "/api/storage-units/{$cabinetId}/generate", ['type_id' => $types['Tiroir'], 'stack' => [['name' => 'Visserie', 'height_mm' => 100], ['name' => 'Câbles', 'height_mm' => 200]]]);
$drawers = array_values(array_filter($admin->request('GET', "/api/rooms/{$roomA}")['body']['units'], static fn ($u) => $u['parent_id'] === $cabinetId));
$r = $admin->request('POST', "/api/storage-units/{$drawers[0]['id']}/generate", ['type_id' => $types['Bac'], 'rows' => 2, 'columns' => 2]);
check('Génération : identifiants renvoyés', $r['status'] === 201 && count($r['body']['ids']) === 4, $r['body']);

$tree = $admin->request('GET', "/api/storage-units/{$cabinetId}/tree");
status('Lecture de l\'arbre', $tree, 200);
check('Arbre complet (2 tiroirs, 4 bacs)', count($tree['body']['tree']['children']) === 2 && count($tree['body']['tree']['children'][0]['children']) + count($tree['body']['tree']['children'][1]['children']) === 4, $tree['body']['tree'] ?? null);
$t = $tree['body']['tree'];
status('Copie posée sur l\'original : refusée', $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomA, 'tree' => $t]), 422);
$unitCount = static fn (int $roomId) => count($admin->request('GET', "/api/rooms/{$roomId}")['body']['units']);
check('Refus : rien n\'a été créé', $unitCount($roomA) === 7, $unitCount($roomA));
$r = $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'tree' => $t, 'pos_x_mm' => 2500, 'pos_z_mm' => 100, 'name' => 'Copie']);
status('Copie dans une autre salle', $r, 201);
check('Copie : 7 rangements, identifiants fournis', $r['body']['created'] === 7 && count((array) $r['body']['ids']) === 7 && $r['body']['unit']['name'] === 'Copie' && $r['body']['unit']['pos_x_mm'] === 2500, $r['body']);
check('Copie : tout dans la salle cible', $unitCount($roomB) === 7);
$copyId = $r['body']['unit']['id'];
$bad = $t;
$bad['children'][0]['width_mm'] = 5000;
status('Enfant qui dépasse : tout est annulé', $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'tree' => $bad, 'pos_x_mm' => 0, 'pos_z_mm' => 2000]), 422);
check('Annulation complète', $unitCount($roomB) === 7, $unitCount($roomB));
$float = $t;
$float['pos_x_mm'] = 12.5;
status('Position non entière refusée', $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'tree' => $float]), 422);
status('Arbre absent refusé', $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB]), 422);

echo "== Modèles\n";
$r = $admin->request('POST', '/api/storage-templates', ['name' => 'Test armoire atelier', 'unit_id' => $cabinetId]);
status('Enregistrer un modèle', $r, 201);
$templateId = $r['body']['template']['id'];
check('Modèle complet', $r['body']['template']['node_count'] === 7 && count($r['body']['template']['tree']['children']) === 2, $r['body']);
status('Nom déjà pris', $admin->request('POST', '/api/storage-templates', ['name' => 'Test armoire atelier', 'unit_id' => $cabinetId]), 422);
status('Rangement inconnu', $admin->request('POST', '/api/storage-templates', ['name' => 'Test x', 'unit_id' => 99999999]), 404);
$r = $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'template_id' => $templateId, 'pos_x_mm' => 500, 'pos_z_mm' => 100]);
status('Poser le modèle', $r, 201);
check('Modèle posé : 7 rangements', $r['body']['created'] === 7 && $unitCount($roomB) === 14, $unitCount($roomB));
status('Modèle inconnu', $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'template_id' => 99999999]), 404);
$r = $admin->request('PATCH', "/api/storage-templates/{$templateId}", ['name' => 'Test armoire renommée']);
check('Modèle renommé', $r['status'] === 200 && $r['body']['template']['name'] === 'Test armoire renommée');
check('Modèles listés', in_array($templateId, array_column($admin->request('GET', '/api/storage-templates')['body']['templates'], 'id'), true));

echo "== Annuler une suppression, déplacer avec le stock\n";
$snapshot = $admin->request('GET', "/api/storage-units/{$copyId}/tree")['body']['tree'];
status('Supprimer la copie', $admin->request('DELETE', "/api/storage-units/{$copyId}"), 204);
$r = $admin->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'tree' => $snapshot]);
status('Recréer (annulation)', $r, 201);
check('Correspondance des anciens identifiants', count((array) $r['body']['ids']) === 7 && isset(((array) $r['body']['ids'])[(string) $copyId]), $r['body']['ids'] ?? null);

$drawerId = $drawers[0]['id'];
$bin = array_values(array_filter($admin->request('GET', "/api/rooms/{$roomA}")['body']['units'], static fn ($u) => $u['parent_id'] === $drawerId))[0]['id'];
$a = $admin->request('POST', '/api/items', ['reference' => 'TSTPLAN-A', 'name' => 'Vis M3 x 12', 'unit_id' => $bin, 'initial_stock' => 10])['body']['item']['id'];
$b = $admin->request('POST', '/api/items', ['reference' => 'TSTPLAN-B', 'name' => 'Fiche banane', 'visual' => 'banana', 'unit_id' => $bin, 'initial_stock' => 5])['body']['item']['id'];
status('Modèle 3D ajouté accepté', $admin->request('PATCH', "/api/items/{$a}", ['visual' => 'raspberry_pi']), 200);
status('Modèle 3D inconnu refusé', $admin->request('PATCH', "/api/items/{$a}", ['visual' => 'licorne']), 422);
$contents = $admin->request('GET', "/api/storage-units/{$bin}/contents")['body']['contents'];
check('Plusieurs objets dans un même bac', count($contents) === 2, $contents);
status('Rangement plein : suppression refusée', $admin->request('DELETE', "/api/storage-units/{$cabinetId}"), 409);

$r = $admin->request('POST', "/api/storage-units/{$cabinetId}/move", ['room_id' => $roomB, 'pos_x_mm' => 0, 'pos_z_mm' => 0]);
status('Chevauchement à l\'arrivée refusé', $r, 422);
$r = $admin->request('POST', "/api/storage-units/{$cabinetId}/move", ['room_id' => $roomB, 'pos_x_mm' => 500, 'pos_z_mm' => 2500, 'rotation_deg' => 90]);
status('Déplacer vers une autre salle', $r, 200);
check('Salle et position changées', $r['body']['unit']['room_id'] === $roomB && $r['body']['unit']['rotation_deg'] === 90, $r['body']['unit']);
check('Sous-rangements suivis', $unitCount($roomA) === 0, $unitCount($roomA));
$contents = $admin->request('GET', "/api/storage-units/{$bin}/contents")['body']['contents'];
check('Le stock suit', count($contents) === 2 && str_starts_with($contents[0]['path'], 'Test plan B'), $contents);
status('Dans lui-même : refusé', $admin->request('POST', "/api/storage-units/{$cabinetId}/move", ['parent_id' => $drawerId]), 422);
$r = $admin->request('POST', "/api/storage-units/{$cabinetId}/move", ['room_id' => $roomA, 'pos_x_mm' => 2000, 'pos_z_mm' => 0, 'rotation_deg' => 0]);
check('Retour (annulation)', $r['status'] === 200 && $unitCount($roomA) === 7, $r['body']);

echo "== Permissions\n";
$role = $admin->request('POST', '/api/roles', ['name' => 'Test plan salles seules', 'permissions' => ['rooms.view']])['body']['role']['id'];
$admin->request('POST', '/api/allowlist', ['value' => $viewerEmail]);
$viewer = new ApiClient($base);
$viewer->login(LoginLinks::create($viewerEmail));
$viewerId = $viewer->request('GET', '/api/auth/me')['body']['user']['id'];
$admin->request('PATCH', "/api/users/{$viewerId}", ['role_id' => $role]);
status('Lecteur : plan visible', $viewer->request('GET', '/api/plan'), 200);
status('Lecteur : modèles visibles', $viewer->request('GET', '/api/storage-templates'), 200);
status('Lecteur : étage refusé', $viewer->request('POST', '/api/floors', ['name' => 'Test interdit']), 403);
status('Lecteur : déplacer une salle refusé', $viewer->request('PATCH', "/api/rooms/{$roomA}", ['plan_x_mm' => 0]), 403);
status('Lecteur : modèle refusé', $viewer->request('POST', '/api/storage-templates', ['name' => 'Test interdit', 'unit_id' => $cabinetId]), 403);
status('Lecteur : collage refusé', $viewer->request('POST', '/api/storage-units/tree', ['room_id' => $roomB, 'tree' => $t]), 403);
status('Lecteur : déplacement refusé', $viewer->request('POST', "/api/storage-units/{$cabinetId}/move", ['pos_x_mm' => 10]), 403);

status('Suppression du modèle', $admin->request('DELETE', "/api/storage-templates/{$templateId}"), 204);
$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
