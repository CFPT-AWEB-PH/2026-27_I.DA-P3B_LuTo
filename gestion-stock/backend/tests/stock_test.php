<?php

declare(strict_types=1);

// Tests d'intégration du stock : catalogue, recherche (scénario C), prise et retour (scénario D),
// déplacements, corrections, historique, accès concurrents, volume, permissions, photo.
// Données de test : salle « Test stock », objets « TST-… », comptes @test.invalid ; supprimés à la fin.

use App\Auth\LoginLinks;
use App\Database;
use Tests\ApiClient;

require '/app/bootstrap.php';
require __DIR__ . '/lib/ApiClient.php';
require __DIR__ . '/lib/assert.php';

$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: exit("TEST_ADMIN_EMAIL manquant\n");
$base = getenv('TEST_BASE_URL') ?: 'http://caddy';
$studentEmail = 'eleve.stock@test.invalid';
$student2Email = 'eleve.stock2@test.invalid';

function cleanup(): void
{
    $pdo = Database::connection();
    $items = "SELECT id FROM items WHERE reference LIKE 'TST-%' OR reference LIKE 'VOL-%'";
    $pdo->exec("DELETE FROM stock_movements WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM loans WHERE item_id IN ({$items})");
    $pdo->exec("DELETE FROM stock_levels WHERE item_id IN ({$items})");
    foreach ($pdo->query("SELECT photo_path FROM items WHERE reference LIKE 'TST-%' AND photo_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $p) {
        @unlink("/app/storage/uploads/items/{$p}");
    }
    $pdo->exec("DELETE FROM items WHERE reference LIKE 'TST-%' OR reference LIKE 'VOL-%'");
    $pdo->exec("DELETE FROM custom_fields WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM categories WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM rooms WHERE name LIKE 'Test %'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@test.invalid'");
    $pdo->exec("DELETE FROM allowlist WHERE value LIKE '%test.invalid'");
}
cleanup();

$admin = new ApiClient($base);
$admin->login(LoginLinks::create($adminEmail));
check('Session administrateur', $admin->csrf !== null);
$types = [];
foreach ($admin->request('GET', '/api/storage-types')['body']['types'] as $t) {
    $types[$t['name']] = $t['id'];
}

// Salle de technologie de test : Armoire A → Tiroir 2 → Boîte 4 → Compartiment B
$r = $admin->request('POST', '/api/rooms', ['name' => 'Test stock technologie', 'label' => 'T101', 'outline' => [[0, 0], [5000, 0], [5000, 5000], [0, 5000]],
    'height_mm' => 2800, 'door_offset_mm' => 300, 'door_width_mm' => 900, 'door_height_mm' => 2000]);
$roomId = $r['body']['room']['id'];
$unit = static function (array $body) use ($admin): int {
    $r = $admin->request('POST', '/api/storage-units', $body);
    if ($r['status'] !== 201) {
        echo '  (création de rangement échouée : ' . json_encode($r['body'], JSON_UNESCAPED_UNICODE) . ")\n";
    }
    return (int) ($r['body']['unit']['id'] ?? 0);
};
$cabinet = $unit(['room_id' => $roomId, 'type_id' => $types['Armoire'], 'name' => 'Armoire A', 'label' => 'ARM-A', 'pos_x_mm' => 1500]);
$drawer = $unit(['parent_id' => $cabinet, 'type_id' => $types['Tiroir'], 'name' => 'Tiroir 2', 'width_mm' => 900, 'depth_mm' => 450, 'height_mm' => 200]);
$box = $unit(['parent_id' => $drawer, 'type_id' => $types['Boîte'], 'name' => 'Boîte 4', 'width_mm' => 300, 'depth_mm' => 300, 'height_mm' => 150]);
$compartment = $unit(['parent_id' => $box, 'type_id' => $types['Compartiment'], 'name' => 'Compartiment B', 'width_mm' => 140, 'depth_mm' => 290, 'height_mm' => 140, 'panel_mm' => 2, 'capacity' => 100]);
$shelfUnit = $unit(['parent_id' => $cabinet, 'type_id' => $types['Étagère'], 'name' => 'Étagère haute', 'width_mm' => 962, 'depth_mm' => 481, 'height_mm' => 400, 'pos_y_mm' => 1500]);
$divider = $unit(['parent_id' => $cabinet, 'type_id' => $types['Séparateur'], 'name' => 'Séparateur', 'width_mm' => 10, 'depth_mm' => 100, 'height_mm' => 100, 'pos_y_mm' => 1000]);
check('Hiérarchie de test créée', $compartment > 0 && $shelfUnit > 0 && $divider > 0);

echo "== Catalogue\n";
$r = $admin->request('POST', '/api/categories', ['name' => 'Test électronique', 'color' => '#2563eb']);
status('Créer une catégorie', $r, 201);
$categoryId = $r['body']['category']['id'];
$r = $admin->request('POST', '/api/fields', ['name' => 'Test tension', 'field_type' => 'select', 'options' => ['5 V', '12 V'], 'category_id' => $categoryId, 'is_required' => true]);
status('Créer un champ personnalisé obligatoire', $r, 201);
$fieldId = $r['body']['field']['id'];
$r = $admin->request('POST', '/api/fields', ['name' => 'Test fournisseur', 'field_type' => 'text']);
$supplierField = $r['body']['field']['id'] ?? 0;

$item = ['reference' => 'TST-ARDUINO-UNO', 'name' => 'Carte Arduino Uno', 'description' => 'Microcontrôleur ATmega328P', 'category_id' => $categoryId,
    'unit' => 'pièce', 'min_quantity' => 5, 'fields' => [(string) $fieldId => '5 V', (string) $supplierField => 'Distrelec']];
status('Champ obligatoire manquant', $admin->request('POST', '/api/items', ['fields' => []] + $item), 422);
status('Valeur hors liste', $admin->request('POST', '/api/items', ['fields' => [(string) $fieldId => '230 V']] + $item), 422);
status('Quantité à 4 décimales', $admin->request('POST', '/api/items', ['min_quantity' => 1.2345] + $item), 422);
$r = $admin->request('POST', '/api/items', $item + ['unit_id' => $compartment, 'initial_stock' => 10]);
status('Créer l\'objet avec un stock initial de 10', $r, 201);
$itemId = $r['body']['item']['id'] ?? 0;
check('Stock initial et emplacement', ($r['body']['item']['total_quantity'] ?? null) == 10 && ($r['body']['item']['locations'][0]['unit_id'] ?? null) === $compartment);
status('Référence déjà utilisée', $admin->request('POST', '/api/items', $item), 422);
status('Stock dans un séparateur', $admin->request('POST', '/api/stock/add', ['item_id' => $itemId, 'unit_id' => $divider, 'quantity' => 1]), 422);
$cable = $admin->request('POST', '/api/items', ['reference' => 'TST-CABLE', 'name' => 'Câble Dupont', 'unit' => 'm', 'is_consumable' => true, 'unit_id' => $shelfUnit, 'initial_stock' => 12.5])['body']['item']['id'] ?? 0;
check('Quantité décimale (12,5 m)', ($admin->request('GET', "/api/items/{$cable}")['body']['item']['total_quantity'] ?? null) == 12.5);

echo "== Scénario C : recherche\n";
$find = static function (string $q, array $extra = []) use ($admin): array {
    return $admin->request('GET', '/api/items?' . http_build_query(['q' => $q] + $extra))['body'] ?? [];
};
foreach ([
    'nom' => 'arduino', 'début de mot' => 'ardu', 'référence exacte' => 'TST-ARDUINO-UNO', 'référence partielle' => 'TST-ARD',
    'description' => 'atmega328p', 'catégorie' => 'électronique', 'salle' => 'Test stock technologie', 'étiquette de salle' => 'T101',
    'armoire' => 'armoire A', 'étiquette d\'armoire' => 'ARM-A', 'tiroir' => 'tiroir 2', 'boîte' => 'boîte 4', 'compartiment' => 'compartiment B',
    'champ personnalisé' => 'distrelec', 'sans accents' => 'electronique', 'majuscules' => 'CARTE UNO',
] as $label => $q) {
    $result = $find($q);
    check("Recherche par {$label} (« {$q} »)", in_array($itemId, array_column($result['items'] ?? [], 'id'), true), array_column($result['items'] ?? [], 'reference'));
}
$result = $find('TST-ARDUINO-UNO');
$hit = $result['items'][0] ?? [];
check('Référence exacte en premier', ($hit['id'] ?? 0) === $itemId);
check('Quantité exacte dans le résultat', ($hit['total_quantity'] ?? null) == 10);
check('Emplacement exact dans le résultat', ($hit['locations'][0]['path'] ?? '') === 'Test stock technologie › Armoire A › Tiroir 2 › Boîte 4 › Compartiment B', $hit['locations'][0]['path'] ?? null);
check('Lien vers la 3D (salle et rangement)', ($hit['locations'][0]['room_id'] ?? 0) === $roomId && ($hit['locations'][0]['unit_id'] ?? 0) === $compartment);
check('Filtre par salle', count($find('arduino', ['room_id' => $roomId])['items'] ?? []) === 1 && count($find('arduino', ['room_id' => 999999])['items'] ?? [1]) === 0);
check('Filtre par armoire (sous-rangements inclus)', in_array($itemId, array_column($find('', ['unit_id' => $cabinet])['items'] ?? [], 'id'), true));
check('Filtre par catégorie', array_column($find('', ['category_id' => $categoryId])['items'] ?? [], 'id') === [$itemId]);
check('Aucun résultat pour un mot absent', ($find('zzqxw')['total'] ?? 1) === 0);
$sugg = $admin->request('GET', '/api/items/suggest?q=ardu')['body']['suggestions'] ?? [];
check('Suggestions', ($sugg[0]['id'] ?? 0) === $itemId && str_contains($sugg[0]['location'] ?? '', 'Compartiment B'));
$admin->request('PATCH', "/api/storage-units/{$box}", ['name' => 'Boîte bleue']);
check('Recherche à jour après renommage d\'un rangement', in_array($itemId, array_column($find('boîte bleue')['items'] ?? [], 'id'), true));
$room = $admin->request('GET', "/api/rooms/{$roomId}")['body'];
check('Remplissage dans la vue 3D (stock par rangement)', ($room['stock'][(string) $compartment]['quantity'] ?? null) == 10 && ($room['stock'][(string) $compartment]['items'] ?? 0) === 1);

echo "== Scénario D : prise et retour\n";
foreach ([$studentEmail, $student2Email] as $email) {
    $admin->request('POST', '/api/allowlist', ['value' => $email]);
}
$student = new ApiClient($base);
$student->login(LoginLinks::create($studentEmail));
$studentId = $student->request('GET', '/api/auth/me')['body']['user']['id'] ?? 0;
status('Prise supérieure au stock', $student->request('POST', '/api/stock/take', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 11]), 409);
status('Prise de quantité nulle', $student->request('POST', '/api/stock/take', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 0]), 422);
status('Prise négative', $student->request('POST', '/api/stock/take', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => -3]), 422);
$before = gmdate('Y-m-d H:i:s');
$r = $student->request('POST', '/api/stock/take', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 4, 'comment' => 'Projet robot']);
status('Élève prend 4 cartes', $r, 201);
$loanId = $r['body']['loan_id'] ?? 0;
check('Stock restant 6', ($r['body']['remaining'] ?? null) == 6 && $loanId > 0);
$item = $admin->request('GET', "/api/items/{$itemId}")['body'];
check('Fiche : 6 en stock, 4 en prêt', $item['item']['total_quantity'] == 6 && $item['item']['on_loan'] == 4);
$move = $admin->request('GET', "/api/movements?item_id={$itemId}&type=take")['body']['movements'][0] ?? [];
check('Mouvement : utilisateur, date, origine, commentaire',
    ($move['user']['id'] ?? 0) === $studentId && ($move['created_at'] ?? '') >= $before && ($move['from']['path'] ?? '') !== '' && $move['comment'] === 'Projet robot', $move);
status('Retour de plus que le reste', $student->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 5]), 422);
$student2 = new ApiClient($base);
$student2->login(LoginLinks::create($student2Email));
status('Retour par un autre élève', $student2->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 1]), 403);
$r = $student->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 1.5]);
check('Retour partiel de 1,5', $r['status'] === 201 && ($r['body']['remaining_to_return'] ?? null) == 2.5 && $r['body']['status'] === 'open', $r);
$loans = $student->request('GET', '/api/loans')['body']['loans'] ?? [];
check('Emprunt en cours : 2,5 à rendre', ($loans[0]['remaining'] ?? null) == 2.5 && $loans[0]['status'] === 'open');
check('Stock après retour partiel : 7,5', $admin->request('GET', "/api/items/{$itemId}")['body']['item']['total_quantity'] == 7.5);
$r = $student->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 2.5, 'unit_id' => $shelfUnit]);
check('Retour du reste dans un autre rangement', $r['status'] === 201 && $r['body']['status'] === 'closed');
status('Retour sur un emprunt clôturé', $student->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 1]), 409);
$item = $admin->request('GET', "/api/items/{$itemId}")['body']['item'];
check('Stock final 10 réparti sur 2 emplacements', $item['total_quantity'] == 10 && count($item['locations']) === 2 && $item['on_loan'] == 0);
$history = $admin->request('GET', "/api/movements?loan_id={$loanId}")['body'];
check('Historique complet de l\'emprunt (1 prise, 2 retours)', ($history['total'] ?? 0) === 3, array_column($history['movements'] ?? [], 'type'));
$r = $student->request('POST', '/api/stock/take', ['item_id' => $cable, 'unit_id' => $shelfUnit, 'quantity' => 2.25]);
check('Consommable : pas d\'emprunt', $r['status'] === 201 && $r['body']['loan_id'] === null && $r['body']['remaining'] == 10.25, $r['body']);

echo "== Réapprovisionnement, correction, déplacement\n";
status('Élève ajoute du stock', $student->request('POST', '/api/stock/add', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 5]), 403);
status('Élève corrige le stock', $student->request('POST', '/api/stock/adjust', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 50, 'comment' => 'triche']), 403);
status('Élève déplace du stock', $student->request('POST', '/api/stock/move', ['item_id' => $itemId, 'from_unit_id' => $compartment, 'to_unit_id' => $shelfUnit, 'quantity' => 1]), 403);
status('Élève consulte l\'historique', $student->request('GET', '/api/movements'), 403);
status('Élève crée un objet', $student->request('POST', '/api/items', ['reference' => 'TST-X', 'name' => 'X']), 403);
status('Élève prend dans un emprunt d\'un autre (via retour)', $student2->request('POST', "/api/loans/{$loanId}/return", ['quantity' => 1]), 403);
status('Réapprovisionner +5', $admin->request('POST', '/api/stock/add', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 5, 'comment' => 'Commande 2026-11']), 201);
status('Correction sans commentaire', $admin->request('POST', '/api/stock/adjust', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 9]), 422);
$r = $admin->request('POST', '/api/stock/adjust', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 9, 'comment' => 'Inventaire annuel']);
check('Correction à 9 (avant 12,5, après 9)', $r['status'] === 201 && $r['body']['quantity'] == 9);
$adj = $admin->request('GET', "/api/movements?item_id={$itemId}&type=adjust")['body']['movements'][0] ?? [];
check('Correction tracée (qui, avant, après)', ($adj['quantity_before'] ?? null) == 12.5 && $adj['quantity_after'] == 9 && $adj['user']['email'] === $adminEmail, $adj);
status('Déplacement supérieur au stock', $admin->request('POST', '/api/stock/move', ['item_id' => $itemId, 'from_unit_id' => $compartment, 'to_unit_id' => $shelfUnit, 'quantity' => 50]), 409);
status('Déplacer 3 vers l\'étagère', $admin->request('POST', '/api/stock/move', ['item_id' => $itemId, 'from_unit_id' => $compartment, 'to_unit_id' => $shelfUnit, 'quantity' => 3]), 201);
$mv = $admin->request('GET', "/api/movements?item_id={$itemId}&type=move")['body']['movements'][0] ?? [];
check('Anciens et nouveaux emplacements tracés', str_ends_with($mv['from']['path'] ?? '', 'Compartiment B') && str_ends_with($mv['to']['path'] ?? '', 'Étagère haute'));
check('Filtres du journal (utilisateur, salle, période)',
    ($admin->request('GET', "/api/movements?user_id={$studentId}")['body']['total'] ?? 0) === 4
    && ($admin->request('GET', "/api/movements?room_id={$roomId}&from=" . date('Y-m-d') . '&to=' . date('Y-m-d'))['body']['total'] ?? 0) >= 9);
$levels = Database::connection()->query("SELECT SUM(quantity) FROM stock_levels WHERE item_id = {$itemId}")->fetchColumn();
check('Total en base : 9 + 2,5 = 11,5 (le déplacement ne change pas le total)', (float) $levels == 11.5, $levels);

echo "== Accès concurrents (prises simultanées)\n";
// 20 prises de 1 en parallèle sur un stock de 6 : exactement 6 réussites, jamais de stock négatif
$admin->request('POST', '/api/stock/adjust', ['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 6, 'comment' => 'Préparation test']);
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 20; $i++) {
    $client = $i % 2 === 0 ? $student : $student2;
    $ch = curl_init("{$base}/api/stock/take");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: {$client->csrf}", "Cookie: gs_session={$client->session}"],
        CURLOPT_POSTFIELDS => json_encode(['item_id' => $itemId, 'unit_id' => $compartment, 'quantity' => 1]),
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi);
} while ($running > 0);
$codes = array_count_values(array_map(static fn ($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles));
check('6 prises acceptées, 14 refusées', ($codes[201] ?? 0) === 6 && ($codes[409] ?? 0) === 14, $codes);
$level = Database::connection()->query("SELECT quantity FROM stock_levels WHERE item_id = {$itemId} AND unit_id = {$compartment}")->fetchColumn();
check('Stock à zéro, jamais négatif', (float) $level === 0.0, $level);
$openLoans = (int) Database::connection()->query("SELECT COUNT(*) FROM loans WHERE item_id = {$itemId} AND status = 'open'")->fetchColumn();
check('6 emprunts ouverts enregistrés', $openLoans === 6, $openLoans);

echo "== Protection des données\n";
status('Supprimer un rangement qui contient du stock', $admin->request('DELETE', "/api/storage-units/{$cabinet}"), 409);
status('Supprimer la salle qui contient du stock', $admin->request('DELETE', "/api/rooms/{$roomId}"), 409);
status('Supprimer un objet encore en stock', $admin->request('DELETE', "/api/items/{$cable}"), 409);
status('Rendre un séparateur incapable de contenir', $admin->request('PATCH', "/api/storage-units/{$shelfUnit}", ['type_id' => $types['Séparateur']]), 422);

echo "== Photo\n";
$img = imagecreatetruecolor(1600, 900);
imagefill($img, 0, 0, imagecolorallocate($img, 40, 120, 200));
$png = tempnam(sys_get_temp_dir(), 'tst') . '.png';
imagepng($img, $png);
$upload = static function (string $path, string $mime) use ($base, $admin, $itemId): int {
    $ch = curl_init("{$base}/api/items/{$itemId}/photo");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["X-CSRF-Token: {$admin->csrf}", "Cookie: gs_session={$admin->session}"],
        CURLOPT_POSTFIELDS => ['photo' => new CURLFile($path, $mime, 'photo')]]);
    curl_exec($ch);
    return curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
};
check('Envoi d\'une photo PNG', $upload($png, 'image/png') === 200);
$fake = tempnam(sys_get_temp_dir(), 'tst') . '.png';
file_put_contents($fake, '<?php echo "pirate"; ?>');
check('Fichier non image refusé', $upload($fake, 'image/png') === 422);
$photo = $admin->request('GET', "/api/items/{$itemId}/photo");
$stored = Database::connection()->query("SELECT photo_path FROM items WHERE id = {$itemId}")->fetchColumn();
$size = @getimagesize("/app/storage/uploads/items/{$stored}");
check('Photo réencodée en WebP, 1200 px maximum', $photo['status'] === 200 && ($photo['headers']['content-type'][0] ?? '') === 'image/webp' && $size[0] === 1200, $size);
status('Photo refusée sans session', (new ApiClient($base))->request('GET', "/api/items/{$itemId}/photo"), 401);

echo "== Volume (5000 objets)\n";
$pdo = Database::connection();
$pdo->exec("INSERT INTO items (reference, name, description, unit, search_text)
    SELECT CONCAT('VOL-', LPAD(seq, 5, '0')), CONCAT(ELT(1 + seq % 5, 'Vis', 'Écrou', 'Résistance', 'Condensateur', 'Câble'), ' modèle ', seq),
           'Objet de test de volume', 'pièce',
           CONCAT(ELT(1 + seq % 5, 'Vis', 'Écrou', 'Résistance', 'Condensateur', 'Câble'), ' modèle ', seq, ' VOL-', LPAD(seq, 5, '0'), ' objet de test de volume')
    FROM seq_1_to_5000");
$count = (int) $pdo->query("SELECT COUNT(*) FROM items WHERE reference LIKE 'VOL-%'")->fetchColumn();
check('5000 objets insérés', $count === 5000, $count);
$times = [];
foreach (['résistance', 'modèle 4321', 'VOL-04321', 'cond', 'objet test volume'] as $q) {
    $start = microtime(true);
    $result = $find($q);
    $times[$q] = round((microtime(true) - $start) * 1000);
    if ($q === 'VOL-04321') {
        check('Référence trouvée en premier parmi 5000', ($result['items'][0]['reference'] ?? '') === 'VOL-04321');
    }
    if ($q === 'résistance') {
        check('1000 résistances trouvées', ($result['total'] ?? 0) === 1000, $result['total'] ?? null);
    }
}
echo '  Temps de réponse (ms, requête HTTP complète) : ' . json_encode($times, JSON_UNESCAPED_UNICODE) . "\n";
check('Chaque recherche en moins de 500 ms', max($times) < 500, $times);

$admin->request('POST', '/api/auth/logout');
cleanup();
finish();
