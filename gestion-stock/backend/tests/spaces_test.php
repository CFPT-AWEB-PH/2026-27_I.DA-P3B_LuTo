<?php

declare(strict_types=1);

// Tests d'intégration des salles et rangements (scénarios A et B, accès concurrents).
// Données de test : salles « Test … », compte élève en @test.invalid ; supprimées à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$studentEmail = 'eleve.salles@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM storage_types WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
}
cleanup();

$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
check('Session administrateur', $admin->csrf !== null);

$types = [];
foreach ($admin->request('GET', '/api/storage-types')['body']['types'] ?? [] as $t) {
    $types[$t['name']] = $t['id'];
}
check('Types fournis présents', isset($types['Armoire'], $types['Étagère'], $types['Tiroir'], $types['Boîte'], $types['Case']), array_keys($types));

$roomBody = [
    'name' => 'Test salle A', 'description' => 'Salle de test', 'display_unit' => 'cm',
    'outline' => [[0, 0], [6000, 0], [6000, 8000], [0, 8000]], 'height_mm' => 2800, 'wall_thickness_mm' => 150,
    'door_offset_mm' => 500, 'door_width_mm' => 900, 'door_height_mm' => 2050,
];

echo "== Scénario A : salle, porte, ouvertures\n";
foreach ([
    'Premier point hors origine' => ['outline' => [[100, 0], [6000, 0], [6000, 8000], [0, 8000]]],
    'Mur de la porte hors axe X' => ['outline' => [[0, 0], [6000, 300], [6000, 8000], [0, 8000]]],
    'Contour croisé' => ['outline' => [[0, 0], [6000, 0], [0, 8000], [6000, 8000]]],
    'Salle derrière le mur de la porte' => ['outline' => [[0, 0], [6000, 0], [6000, -3000], [0, -3000]]],
    'Porte qui dépasse du mur' => ['door_offset_mm' => 5500],
    'Porte plus haute que la salle' => ['door_height_mm' => 3000],
    'Hauteur non entière' => ['height_mm' => 2800.5],
] as $label => $override) {
    status($label, $admin->request('POST', '/api/rooms', $override + $roomBody), 422);
}
$r = $admin->request('POST', '/api/rooms', $roomBody);
status('Créer la salle', $r, 201);
$roomId = (int) ($r['body']['room']['id'] ?? 0);
check('Dimensions conservées', ($r['body']['room']['outline'] ?? null) === $roomBody['outline'] && $r['body']['room']['door_offset_mm'] === 500);
status('Nom déjà utilisé', $admin->request('POST', '/api/rooms', $roomBody), 422);

$window = ['kind' => 'window', 'name' => 'Fenêtre 1', 'wall_index' => 1, 'offset_mm' => 1000, 'width_mm' => 1500, 'height_mm' => 1200, 'sill_mm' => 900];
status('Ajouter une fenêtre (mur 2)', $admin->request('POST', "/api/rooms/{$roomId}/openings", $window), 201);
status('Fenêtre qui chevauche', $admin->request('POST', "/api/rooms/{$roomId}/openings", ['offset_mm' => 2000] + $window), 422);
status('Ouverture sur la porte', $admin->request('POST', "/api/rooms/{$roomId}/openings", ['wall_index' => 0, 'offset_mm' => 800, 'sill_mm' => 0] + $window), 422);
status('Mur inexistant', $admin->request('POST', "/api/rooms/{$roomId}/openings", ['wall_index' => 9] + $window), 422);
status('Ouverture plus longue que le mur', $admin->request('POST', "/api/rooms/{$roomId}/openings", ['wall_index' => 2, 'offset_mm' => 5000, 'width_mm' => 2000] + $window), 422);

$cabinet = ['room_id' => $roomId, 'type_id' => $types['Armoire'], 'name' => 'Armoire A', 'width_mm' => 1000, 'depth_mm' => 500, 'height_mm' => 2000,
    'pos_x_mm' => 2000, 'pos_y_mm' => 0, 'pos_z_mm' => 0, 'rotation_deg' => 0];
$r = $admin->request('POST', '/api/storage-units', $cabinet);
status('Ajouter une armoire contre le mur de la porte', $r, 201);
$cabinetId = (int) ($r['body']['unit']['id'] ?? 0);
check('Chemin de l\'armoire', ($r['body']['unit']['path'] ?? '') === 'Test salle A › Armoire A', $r['body']['unit']['path'] ?? null);
check('Panneaux par défaut du type (19 mm)', ($r['body']['unit']['panel_mm'] ?? 0) === 19);
status('Armoire qui chevauche', $admin->request('POST', '/api/storage-units', ['name' => 'Armoire B', 'pos_x_mm' => 2500] + $cabinet), 422);
status('Armoire hors de la salle', $admin->request('POST', '/api/storage-units', ['name' => 'Armoire B', 'pos_x_mm' => 5500] + $cabinet), 422);
status('Armoire plus haute que la salle', $admin->request('POST', '/api/storage-units', ['name' => 'Armoire B', 'pos_x_mm' => 4000, 'height_mm' => 3000] + $cabinet), 422);
status('Armoire tournée qui traverse un mur', $admin->request('POST', '/api/storage-units', ['name' => 'Armoire B', 'pos_x_mm' => 100, 'pos_z_mm' => 500, 'rotation_deg' => 90] + $cabinet), 422);
$r = $admin->request('POST', '/api/storage-units', ['name' => 'Armoire B', 'pos_x_mm' => 0, 'pos_z_mm' => 3000, 'rotation_deg' => 90] + $cabinet);
status('Armoire tournée de 90° contre le mur gauche', $r, 201);
$cabinetB = (int) ($r['body']['unit']['id'] ?? 0);
status('Objet posé sur une armoire (empilement)', $admin->request('POST', '/api/storage-units', ['name' => 'Caisse haute', 'type_id' => $types['Boîte'], 'width_mm' => 500, 'depth_mm' => 400, 'height_mm' => 300, 'pos_x_mm' => 2000, 'pos_y_mm' => 2000] + $cabinet), 201);

echo "== Persistance (rechargement)\n";
$room = $admin->request('GET', "/api/rooms/{$roomId}")['body'];
$saved = array_values(array_filter($room['units'] ?? [], static fn ($u) => $u['id'] === $cabinetB))[0] ?? [];
check('Armoire B relue à l\'identique', ($saved['pos_z_mm'] ?? null) === 3000 && $saved['rotation_deg'] === 90 && $saved['width_mm'] === 1000, $saved);
check('Fenêtre relue', count($room['openings'] ?? []) === 1 && $room['openings'][0]['sill_mm'] === 900);

echo "== Scénario B : organisation d'une armoire\n";
$r = $admin->request('POST', "/api/storage-units/{$cabinetId}/generate", ['rows' => 4, 'columns' => 1, 'type_id' => $types['Étagère']]);
check('Générer 4 étagères', $r['status'] === 201 && ($r['body']['created'] ?? 0) === 4, $r);
status('Générer à nouveau (armoire non vide)', $admin->request('POST', "/api/storage-units/{$cabinetId}/generate", ['rows' => 2, 'columns' => 1, 'type_id' => $types['Étagère']]), 409);

$units = static function () use ($admin, $roomId): array {
    $byName = [];
    foreach ($admin->request('GET', "/api/rooms/{$roomId}")['body']['units'] as $u) {
        $byName[$u['name']] = $u;
    }
    return $byName;
};
$u = $units();
// Intérieur de l'armoire : 1000 - 2×19 = 962 ; 500 - 19 = 481 ; 2000 - 2×19 = 1962 ; 4 niveaux de 490
check('Étagère 1 en haut, 490 mm de haut', ($u['Étagère 1']['pos_y_mm'] ?? null) === 1470 && $u['Étagère 1']['height_mm'] === 490, $u['Étagère 1'] ?? null);
check('Étagère 4 en bas', ($u['Étagère 4']['pos_y_mm'] ?? null) === 0);
check('Largeur = intérieur de l\'armoire (962 mm)', ($u['Étagère 4']['width_mm'] ?? null) === 962 && $u['Étagère 4']['depth_mm'] === 481);
$shelf = $u['Étagère 4']['id'];

$drawer = ['parent_id' => $shelf, 'type_id' => $types['Tiroir'], 'name' => 'Tiroir 1', 'width_mm' => 400, 'depth_mm' => 450, 'height_mm' => 150, 'pos_x_mm' => 0, 'pos_y_mm' => 0, 'pos_z_mm' => 0];
$r = $admin->request('POST', '/api/storage-units', $drawer);
status('Tiroir 1 sur l\'étagère 4', $r, 201);
$drawer1 = (int) ($r['body']['unit']['id'] ?? 0);
status('Tiroir 2 à côté', $admin->request('POST', '/api/storage-units', ['name' => 'Tiroir 2', 'pos_x_mm' => 400] + $drawer), 201);
status('Tiroir qui chevauche', $admin->request('POST', '/api/storage-units', ['name' => 'Tiroir 3', 'pos_x_mm' => 300] + $drawer), 422);
status('Tiroir plus large que l\'étagère', $admin->request('POST', '/api/storage-units', ['name' => 'Tiroir 3', 'pos_x_mm' => 0, 'pos_y_mm' => 200, 'width_mm' => 1000] + $drawer), 422);
status('Position négative', $admin->request('POST', '/api/storage-units', ['name' => 'Tiroir 3', 'pos_x_mm' => -10, 'pos_y_mm' => 200] + $drawer), 422);
status('Rangement dans un séparateur', $admin->request('POST', '/api/storage-units', ['name' => 'Sép', 'type_id' => $types['Séparateur'], 'width_mm' => 10, 'height_mm' => 100, 'depth_mm' => 100, 'pos_x_mm' => 900] + $drawer), 201);
$sep = $units()['Sép']['id'];
status('Rangement à l\'intérieur d\'un séparateur', $admin->request('POST', '/api/storage-units', ['parent_id' => $sep, 'name' => 'X', 'width_mm' => 5, 'height_mm' => 5, 'depth_mm' => 5] + $drawer), 422);

$r = $admin->request('POST', '/api/storage-units', ['parent_id' => $drawer1, 'type_id' => $types['Boîte'], 'name' => 'Boîte 1', 'width_mm' => 150, 'depth_mm' => 200, 'height_mm' => 100]);
status('Boîte dans le tiroir 1', $r, 201);
$box = (int) ($r['body']['unit']['id'] ?? 0);
$r = $admin->request('POST', "/api/storage-units/{$box}/generate", ['rows' => 1, 'columns' => 3, 'type_id' => $types['Case']]);
check('3 cases dans la boîte', $r['status'] === 201 && ($r['body']['created'] ?? 0) === 3, $r);
$u = $units();
check('Chemin complet sur 5 niveaux', ($u['Case 1']['path'] ?? '') === 'Test salle A › Armoire A › Étagère 4 › Tiroir 1 › Boîte 1 › Case 1', $u['Case 1']['path'] ?? null);
// Intérieur de la boîte : 150 - 2×3 = 144 → 3 cases de 48 mm
check('Cases de 48 mm côte à côte', ($u['Case 2']['width_mm'] ?? 0) === 48 && $u['Case 2']['pos_x_mm'] === 48 && $u['Case 3']['pos_x_mm'] === 96);

echo "== Cohérence lors des modifications\n";
status('Réduire l\'armoire sous ses étagères', $admin->request('PATCH', "/api/storage-units/{$cabinetId}", ['height_mm' => 1000]), 422);
status('Rétrécir la salle sous l\'armoire B', $admin->request('PATCH', "/api/rooms/{$roomId}", ['outline' => [[0, 0], [6000, 0], [6000, 2500], [0, 2500]]]), 422);
status('Rétrécir la salle sous la fenêtre', $admin->request('PATCH', "/api/rooms/{$roomId}", ['outline' => [[0, 0], [6000, 0], [6000, 2200], [0, 2200]]]), 422);
status('Placer l\'armoire dans sa propre case', $admin->request('PATCH', "/api/storage-units/{$cabinetId}", ['parent_id' => $u['Case 1']['id']]), 422);
status('Référence ARM-A', $admin->request('PATCH', "/api/storage-units/{$cabinetId}", ['reference' => 'ARM-A', 'label' => 'A']), 200);
status('Référence déjà utilisée', $admin->request('PATCH', "/api/storage-units/{$cabinetB}", ['reference' => 'ARM-A']), 422);
status('Renommer l\'armoire', $admin->request('PATCH', "/api/storage-units/{$cabinetId}", ['name' => 'Armoire Alpha']), 200);
check('Chemins des sous-rangements mis à jour', ($units()['Case 3']['path'] ?? '') === 'Test salle A › Armoire Alpha › Étagère 4 › Tiroir 1 › Boîte 1 › Case 3');
status('Déplacer l\'armoire de 1 m', $admin->request('PATCH', "/api/storage-units/{$cabinetId}", ['pos_x_mm' => 3000]), 200);
check('Contenu déplacé avec l\'armoire (positions relatives)', ($units()['Tiroir 1']['pos_x_mm'] ?? null) === 0);
status('Renommer la salle', $admin->request('PATCH', "/api/rooms/{$roomId}", ['name' => 'Test salle Alpha']), 200);
check('Chemins après renommage de la salle', str_starts_with($units()['Case 3']['path'] ?? '', 'Test salle Alpha › '));

echo "== Duplication\n";
$r = $admin->request('POST', "/api/rooms/{$roomId}/duplicate");
status('Dupliquer la salle', $r, 201);
check('Nom de la copie', ($r['body']['room']['name'] ?? '') === 'Test salle Alpha (copie)');
check('Même nombre de rangements', count($r['body']['units'] ?? []) === count($units()));
check('Références non copiées', array_filter($r['body']['units'] ?? [], static fn ($x) => $x['reference'] !== null) === []);
check('Fenêtre copiée', count($r['body']['openings'] ?? []) === 1);

echo "== Types personnalisés\n";
$r = $admin->request('POST', '/api/storage-types', ['name' => 'Test casier', 'shape' => 'cell', 'default_width_mm' => 300, 'default_depth_mm' => 300, 'default_height_mm' => 300, 'default_panel_mm' => 5]);
status('Créer un type', $r, 201);
$typeId = (int) ($r['body']['type']['id'] ?? 0);
status('Supprimer un type fourni', $admin->request('DELETE', "/api/storage-types/{$types['Armoire']}"), 409);
status('Changer la forme d\'un type fourni', $admin->request('PATCH', "/api/storage-types/{$types['Armoire']}", ['shape' => 'box']), 409);
status('Supprimer le type personnalisé', $admin->request('DELETE', "/api/storage-types/{$typeId}"), 204);

echo "== Accès concurrents\n";
// Deux créations simultanées au même endroit : une seule doit réussir
$handles = [];
$multi = curl_multi_init();
foreach (['Test concurrent 1', 'Test concurrent 2'] as $name) {
    $ch = curl_init("{$base}/api/storage-units");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: {$admin->csrf}", "Cookie: gs_session={$admin->session}"],
        CURLOPT_POSTFIELDS => json_encode(['name' => $name, 'pos_x_mm' => 4500, 'pos_z_mm' => 6000] + $cabinet),
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);
$codes = array_map(static fn ($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
sort($codes);
check('Une création acceptée, l\'autre refusée (chevauchement)', $codes === [201, 422], $codes);

echo "== Permissions (Élève)\n";
$admin->request('POST', '/api/allowlist', ['value' => $studentEmail]);
$student = new ApiClient($base);
$student->login(LoginLinks::create($studentEmail));
status('Élève consulte les salles', $student->request('GET', '/api/rooms'), 200);
status('Élève consulte une salle', $student->request('GET', "/api/rooms/{$roomId}"), 200);
status('Élève crée une salle', $student->request('POST', '/api/rooms', ['name' => 'Test pirate'] + $roomBody), 403);
status('Élève modifie la salle', $student->request('PATCH', "/api/rooms/{$roomId}", ['name' => 'Test piratée']), 403);
status('Élève ajoute une armoire', $student->request('POST', '/api/storage-units', ['name' => 'Test X', 'pos_x_mm' => 4500] + $cabinet), 403);
status('Élève déplace une armoire', $student->request('PATCH', "/api/storage-units/{$cabinetId}", ['pos_x_mm' => 100]), 403);
status('Élève ajoute un tiroir', $student->request('POST', '/api/storage-units', ['name' => 'Tiroir X', 'pos_y_mm' => 200] + $drawer), 403);
status('Élève supprime la salle', $student->request('DELETE', "/api/rooms/{$roomId}"), 403);
status('Élève supprime un rangement', $student->request('DELETE', "/api/storage-units/{$drawer1}"), 403);
status('Élève crée un type', $student->request('POST', '/api/storage-types', ['name' => 'Test t', 'shape' => 'box', 'default_width_mm' => 1, 'default_depth_mm' => 1, 'default_height_mm' => 1]), 403);

echo "== Suppression\n";
$before = count($units());
status('Supprimer le tiroir 1 (et son contenu)', $admin->request('DELETE', "/api/storage-units/{$drawer1}"), 204);
check('Tiroir, boîte et 3 cases supprimés', count($units()) === $before - 5);
status('Supprimer la salle', $admin->request('DELETE', "/api/rooms/{$roomId}"), 204);
status('Salle introuvable ensuite', $admin->request('GET', "/api/rooms/{$roomId}"), 404);

$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
