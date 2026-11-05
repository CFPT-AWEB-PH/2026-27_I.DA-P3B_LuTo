<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit;
use App\Auth\AuthContext;
use App\Database;
use App\Http\HttpException;
use App\Http\Input;
use App\Http\Request;
use App\Http\Response;
use App\Space\Geometry;
use App\Space\SpaceRepository as Repo;
use App\Space\SpaceValidator;
use App\Stock\Quantity;
use PDO;

/**
 * Meubles (rangements sans parent, permission furniture.edit) et rangements imbriqués
 * (permission storage.edit). Chaque écriture verrouille la salle : les vérifications de
 * chevauchement ne peuvent pas être contournées par deux requêtes simultanées.
 */
final class StorageUnitController
{
    public static function create(Request $request): Response
    {
        $auth = $request->user();
        $input = $request->input();
        $parentId = $input->optionalInt('parent_id', null, 1);
        self::requireEditRight($auth, $parentId);

        [$id, $roomId] = Database::transaction(static function (PDO $pdo) use ($input, $parentId): array {
            $parent = $parentId === null ? null : Repo::unit($pdo, $parentId);
            $roomId = $parent === null ? $input->int('room_id', 1) : (int) $parent['room_id'];
            $room = Repo::room($pdo, $roomId, true);
            $type = Repo::type($pdo, $input->int('type_id', 1));

            $unit = self::read($input, null, $type) + ['id' => null];
            if ($parent !== null) {
                $unit['rotation_deg'] = 0;
            }
            SpaceValidator::unit($pdo, $room, $unit, $type, $parent);
            self::assertReferenceFree($pdo, $unit['reference'], null);

            unset($unit['id']);
            $columns = array_keys($unit);
            $pdo->prepare('INSERT INTO storage_units (room_id, parent_id, ' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')')
                ->execute([$roomId, $parentId, ...array_values($unit)]);
            $id = (int) $pdo->lastInsertId();
            Repo::rebuildPaths($pdo, $roomId);
            return [$id, $roomId];
        });

        Audit::log($auth->userId, 'unit.created', 'storage_unit', $id, ['room_id' => $roomId, 'parent_id' => $parentId], $request->ip);
        return Response::json(['unit' => Repo::formatUnit(Repo::unit(Database::connection(), $id))], 201);
    }

    public static function update(Request $request, array $params): Response
    {
        $auth = $request->user();
        $id = (int) $params['id'];
        $input = $request->input();

        $changes = Database::transaction(static function (PDO $pdo) use ($id, $input, $auth): array {
            $current = Repo::unit($pdo, $id);
            $room = Repo::room($pdo, (int) $current['room_id'], true);
            $current = Repo::unit($pdo, $id); // relu sous verrou de la salle
            $currentParent = $current['parent_id'] === null ? null : (int) $current['parent_id'];
            self::requireEditRight($auth, $currentParent);

            $parentId = $input->has('parent_id') ? $input->optionalInt('parent_id', null, 1) : $currentParent;
            if ($parentId !== $currentParent) {
                self::requireEditRight($auth, $parentId);
            }
            $parent = $parentId === null ? null : Repo::unit($pdo, $parentId);
            if ($parent !== null) {
                if ((int) $parent['room_id'] !== (int) $room['id']) {
                    Input::fail('parent_id', 'Le parent doit se trouver dans la même salle.');
                }
                self::assertNotDescendant($pdo, $id, $parentId);
            }

            $type = $input->has('type_id') ? Repo::type($pdo, $input->int('type_id', 1)) : Repo::type($pdo, (int) $current['type_id']);
            $unit = self::read($input, $current, $type) + ['id' => $id, 'name' => $current['name']];
            if ($parent !== null) {
                $unit['rotation_deg'] = 0;
            }
            SpaceValidator::unit($pdo, $room, $unit, $type, $parent);
            SpaceValidator::childrenFit($pdo, $unit, $type, $room['display_unit']);
            self::assertReferenceFree($pdo, $unit['reference'], $id);
            if (!(int) $type['can_hold_items'] && self::stockCount($pdo, [$id]) > 0) {
                Input::fail('type_id', 'Ce type ne peut pas contenir d\'objets, or ce rangement en contient.');
            }

            unset($unit['id']);
            $unit['parent_id'] = $parentId;
            $changes = array_filter($unit, static fn ($value, $key) => $value !== (is_int($value) && $current[$key] !== null ? (int) $current[$key] : $current[$key]), ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($changes)));
                $pdo->prepare("UPDATE storage_units SET {$set} WHERE id = ?")->execute([...array_values($changes), $id]);
                if (array_intersect_key($changes, array_flip(['name', 'parent_id', 'label', 'reference'])) !== []) {
                    Repo::rebuildPaths($pdo, (int) $room['id']);
                }
            }
            return $changes;
        });

        if ($changes !== []) {
            Audit::log($auth->userId, 'unit.updated', 'storage_unit', $id, $changes, $request->ip);
        }
        return Response::json(['unit' => Repo::formatUnit(Repo::unit(Database::connection(), $id))]);
    }

    public static function delete(Request $request, array $params): Response
    {
        $auth = $request->user();
        $id = (int) $params['id'];

        $summary = Database::transaction(static function (PDO $pdo) use ($id, $auth): array {
            $unit = Repo::unit($pdo, $id);
            Repo::room($pdo, (int) $unit['room_id'], true);
            self::requireEditRight($auth, $unit['parent_id'] === null ? null : (int) $unit['parent_id']);
            $subtree = [$id, ...self::descendantIds($pdo, $id)];
            if (($count = self::stockCount($pdo, $subtree)) > 0) {
                throw new HttpException(409, "« {$unit['name']} » contient encore {$count} objet(s) en stock. Déplacez-les ou corrigez le stock avant de le supprimer.");
            }
            $descendants = count($subtree) - 1;
            $pdo->prepare('DELETE FROM storage_units WHERE id = ?')->execute([$id]);
            return ['name' => $unit['name'], 'room_id' => (int) $unit['room_id'], 'descendants_deleted' => $descendants];
        });

        Audit::log($auth->userId, 'unit.deleted', 'storage_unit', $id, $summary, $request->ip);
        return Response::noContent();
    }

    /** Arbre d'un rangement et de ses sous-rangements (sans le stock), pour le copier ou en faire un modèle. */
    public static function tree(Request $request, array $params): Response
    {
        $request->requirePermission('rooms.view');
        return Response::json(['tree' => self::treeOf(Database::connection(), (int) $params['id'])]);
    }

    /**
     * Crée d'un coup un rangement et ses sous-rangements : collage, modèle ou annulation d'une suppression.
     * Source : tree (arbre fourni) ou template_id. Cible : parent_id ou room_id. Les positions données
     * remplacent celles de la racine. Chaque niveau passe par les règles habituelles (SpaceValidator).
     * ids : correspondance entre les identifiants de l'arbre source et ceux créés, s'ils étaient fournis.
     */
    public static function createTree(Request $request): Response
    {
        $auth = $request->user();
        $input = $request->input();
        $parentId = $input->optionalInt('parent_id', null, 1);
        self::requireEditRight($auth, $parentId);

        $result = Database::transaction(static function (PDO $pdo) use ($input, $parentId, $auth, $request): array {
            $tree = $input->has('template_id')
                ? TemplateController::tree($pdo, $input->int('template_id', 1))
                : ($request->json()['tree'] ?? null);
            if (!is_array($tree)) {
                Input::fail('tree', 'Rangement à créer manquant.');
            }
            $count = self::countNodes($tree);
            if ($count > self::MAX_TREE_NODES) {
                Input::fail('tree', 'Trop de rangements d\'un coup (' . self::MAX_TREE_NODES . ' au maximum).');
            }
            if ($count > 1) {
                $auth->require('storage.edit');
            }
            $parent = $parentId === null ? null : Repo::unit($pdo, $parentId);
            $roomId = $parent === null ? $input->int('room_id', 1) : (int) $parent['room_id'];
            $room = Repo::room($pdo, $roomId, true);

            foreach (['pos_x_mm', 'pos_y_mm', 'pos_z_mm', 'rotation_deg', 'name'] as $key) {
                if ($input->has($key)) {
                    $tree[$key] = $request->json()[$key];
                }
            }
            $ids = [];
            $rootId = self::insertNode($pdo, $room, $tree, $parent, 0, $ids);
            Repo::rebuildPaths($pdo, $roomId);
            return ['id' => $rootId, 'room_id' => $roomId, 'ids' => $ids, 'count' => $count];
        });

        Audit::log($auth->userId, 'unit.tree_created', 'storage_unit', $result['id'], ['room_id' => $result['room_id'], 'parent_id' => $parentId, 'count' => $result['count']], $request->ip);
        return Response::json(['unit' => Repo::formatUnit(Repo::unit(Database::connection(), $result['id'])), 'ids' => (object) $result['ids'], 'created' => $result['count']], 201);
    }

    /**
     * Déplace un rangement et tout ce qu'il contient (stock compris), éventuellement vers une autre salle.
     * Le stock reste attaché aux mêmes rangements : rien n'est perdu ni dupliqué.
     */
    public static function move(Request $request, array $params): Response
    {
        $auth = $request->user();
        $id = (int) $params['id'];
        $input = $request->input();

        $roomIds = Database::transaction(static function (PDO $pdo) use ($id, $input, $auth): array {
            $current = Repo::unit($pdo, $id);
            $fromRoomId = (int) $current['room_id'];
            self::requireEditRight($auth, $current['parent_id'] === null ? null : (int) $current['parent_id']);

            $parentId = $input->has('parent_id') ? $input->optionalInt('parent_id', null, 1) : ($current['parent_id'] === null ? null : (int) $current['parent_id']);
            self::requireEditRight($auth, $parentId);
            $parent = $parentId === null ? null : Repo::unit($pdo, $parentId);
            $toRoomId = $parent !== null ? (int) $parent['room_id'] : ($input->has('room_id') ? $input->int('room_id', 1) : $fromRoomId);

            // Toujours dans le même ordre : deux déplacements croisés ne peuvent pas se bloquer
            foreach (array_unique([min($fromRoomId, $toRoomId), max($fromRoomId, $toRoomId)]) as $lockId) {
                Repo::room($pdo, $lockId, true);
            }
            $room = Repo::room($pdo, $toRoomId);
            if ($parent !== null) {
                self::assertNotDescendant($pdo, $id, $parentId);
            }

            $type = Repo::type($pdo, (int) $current['type_id']);
            $unit = self::read($input, $current, $type) + ['id' => $id, 'name' => $current['name']];
            if ($parent !== null) {
                $unit['rotation_deg'] = 0;
            }
            SpaceValidator::unit($pdo, $room, $unit, $type, $parent);

            $pdo->prepare('UPDATE storage_units SET parent_id = ?, pos_x_mm = ?, pos_y_mm = ?, pos_z_mm = ?, rotation_deg = ? WHERE id = ?')
                ->execute([$parentId, $unit['pos_x_mm'], $unit['pos_y_mm'], $unit['pos_z_mm'], $unit['rotation_deg'], $id]);
            if ($toRoomId !== $fromRoomId) {
                $subtree = [$id, ...self::descendantIds($pdo, $id)];
                $pdo->prepare('UPDATE storage_units SET room_id = ? WHERE id IN (' . implode(',', array_fill(0, count($subtree), '?')) . ')')
                    ->execute([$toRoomId, ...$subtree]);
                Repo::rebuildPaths($pdo, $fromRoomId);
            }
            Repo::rebuildPaths($pdo, $toRoomId);
            return [$fromRoomId, $toRoomId];
        });

        Audit::log($auth->userId, 'unit.moved', 'storage_unit', $id, ['from_room' => $roomIds[0], 'to_room' => $roomIds[1]], $request->ip);
        return Response::json(['unit' => Repo::formatUnit(Repo::unit(Database::connection(), $id))]);
    }

    private const MAX_TREE_NODES = 3000;
    private const MAX_TREE_DEPTH = 12;
    private const TREE_FIELDS = ['type_id', 'name', 'label', 'description', 'width_mm', 'depth_mm', 'height_mm', 'pos_x_mm', 'pos_y_mm', 'pos_z_mm', 'rotation_deg', 'panel_mm', 'capacity', 'color', 'sort_order'];

    /** @param array<string, mixed> $tree */
    public static function countNodes(array $tree, int $depth = 0): int
    {
        if ($depth > self::MAX_TREE_DEPTH) {
            Input::fail('tree', 'Trop de niveaux imbriqués.');
        }
        $children = $tree['children'] ?? [];
        if (!is_array($children) || !array_is_list($children)) {
            Input::fail('tree', 'Liste de sous-rangements invalide.');
        }
        $count = 1;
        foreach ($children as $child) {
            if (!is_array($child)) {
                Input::fail('tree', 'Sous-rangement invalide.');
            }
            $count += self::countNodes($child, $depth + 1);
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parent
     * @param array<string, int> $ids
     */
    public static function insertNode(PDO $pdo, array $room, array $node, ?array $parent, int $order, array &$ids): int
    {
        $values = array_intersect_key($node, array_flip(self::TREE_FIELDS));
        $in = new Input($values);
        $type = Repo::type($pdo, $in->int('type_id', 1));
        $unit = self::read($in, null, $type) + ['id' => null];
        if ($parent !== null) {
            $unit['rotation_deg'] = 0;
        }
        SpaceValidator::unit($pdo, $room, $unit, $type, $parent);

        unset($unit['id']);
        $columns = array_keys($unit);
        $pdo->prepare('INSERT INTO storage_units (room_id, parent_id, ' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns) + 2, '?')) . ')')
            ->execute([$room['id'], $parent === null ? null : (int) $parent['id'], ...array_values($unit)]);
        $id = (int) $pdo->lastInsertId();
        if (isset($node['id']) && is_int($node['id'])) {
            $ids[(string) $node['id']] = $id;
        }
        $children = $node['children'] ?? [];
        if ($children !== []) {
            $self = Repo::unit($pdo, $id);
            foreach ($children as $i => $child) {
                self::insertNode($pdo, $room, $child, $self, $i, $ids);
            }
        }
        return $id;
    }

    /** @return array<string, mixed> */
    public static function treeOf(PDO $pdo, int $id): array
    {
        $root = Repo::unit($pdo, $id);
        $units = Repo::units($pdo, (int) $root['room_id']);
        $byParent = [];
        foreach ($units as $u) {
            $byParent[(int) $u['parent_id']][] = $u;
        }
        $build = static function (array $u) use (&$build, $byParent): array {
            $node = ['id' => (int) $u['id'], 'shape' => $u['shape']];
            foreach (self::TREE_FIELDS as $key) {
                $node[$key] = match ($key) {
                    'name', 'label', 'description', 'color' => $u[$key],
                    'capacity' => Quantity::out($u[$key]),
                    default => (int) $u[$key],
                };
            }
            $node['children'] = array_map($build, $byParent[(int) $u['id']] ?? []);
            return $node;
        };
        return $build($root);
    }

    /**
     * Remplit un rangement vide, de deux façons :
     * - grille régulière (rows × columns) : posée au fond pour un rangement ouvert par le dessus (tiroir,
     *   boîte, bac : rangées de l'arrière vers l'avant), empilée en hauteur sinon (armoire : de haut en bas) ;
     *   labels facultatif : étiquettes dans l'ordre de lecture ;
     * - pile (stack) : [{ name, height_mm }] de haut en bas, par exemple les tiroirs d'une armoire.
     */
    public static function generate(Request $request, array $params): Response
    {
        $auth = $request->requirePermission('storage.edit');
        $id = (int) $params['id'];
        $input = $request->input();
        $stack = $input->has('stack') ? self::readStack($request->json()['stack']) : null;
        $rows = $stack === null ? $input->int('rows', 1, 50) : count($stack);
        $columns = $stack === null ? $input->int('columns', 1, 50) : 1;
        $gap = $input->optionalInt('gap_mm', 0, 0, 500);
        $labels = $input->has('labels') ? $input->stringList('labels', 2500, 50) : [];

        $created = Database::transaction(static function (PDO $pdo) use ($id, $input, $rows, $columns, $gap, $stack, $labels): array {
            $parent = Repo::unit($pdo, $id);
            $room = Repo::room($pdo, (int) $parent['room_id'], true);
            $type = Repo::type($pdo, $input->int('type_id', 1));
            if (!(int) $parent['can_contain_units']) {
                Input::fail('type_id', "« {$parent['name']} » ne peut pas contenir de rangements.");
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM storage_units WHERE parent_id = ?');
            $count->execute([$id]);
            if ((int) $count->fetchColumn() > 0) {
                throw new HttpException(409, "« {$parent['name']} » contient déjà des rangements. La génération automatique ne s'applique qu'à un rangement vide.");
            }
            $inside = Geometry::interior($parent['shape'], (int) $parent['width_mm'], (int) $parent['depth_mm'], (int) $parent['height_mm'], (int) $parent['panel_mm'])
                ?? throw new HttpException(409, "« {$parent['name']} » n'a pas de volume intérieur.");

            $insert = $pdo->prepare(
                'INSERT INTO storage_units (room_id, parent_id, type_id, name, label, width_mm, depth_mm, height_mm, pos_x_mm, pos_y_mm, pos_z_mm, panel_mm, color, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $panelFor = static fn (int ...$sizes): int => min((int) $type['default_panel_mm'], intdiv(min($sizes), 4));
            $ids = [];
            $add = static function (string $name, string $label, int $w, int $d, int $h, int $x, int $y, int $z, int $order) use ($insert, $pdo, $room, $id, $type, $panelFor, &$ids): void {
                $insert->execute([$room['id'], $id, $type['id'], $name, $label, $w, $d, $h, $x, $y, $z, $panelFor($w, $d, $h), $type['default_color'], $order]);
                $ids[] = (int) $pdo->lastInsertId();
            };

            if ($stack !== null) {
                $total = array_sum(array_column($stack, 'height_mm')) + (count($stack) - 1) * $gap;
                if ($total > $inside['height']) {
                    Input::fail('stack', "Hauteur totale de {$total} mm, pour {$inside['height']} mm disponibles à l'intérieur.");
                }
                $top = $inside['height'];
                foreach ($stack as $i => $entry) {
                    $top -= $entry['height_mm'];
                    $add($entry['name'], $entry['label'], $inside['width'], $inside['depth'], $entry['height_mm'], 0, $top, 0, $i);
                    $top -= $gap;
                }
            } else {
                $flat = in_array($parent['shape'], ['drawer', 'box', 'bin'], true);
                $width = intdiv($inside['width'] - ($columns - 1) * $gap, $columns);
                $depth = $flat ? intdiv($inside['depth'] - ($rows - 1) * $gap, $rows) : $inside['depth'];
                $height = $flat ? min((int) $type['default_height_mm'], $inside['height']) : intdiv($inside['height'] - ($rows - 1) * $gap, $rows);
                if ($width < 10 || $height < 10 || $depth < 10) {
                    Input::fail('rows', 'Trop de divisions pour la taille intérieure disponible.');
                }
                for ($r = 0; $r < $rows; $r++) {
                    for ($c = 0; $c < $columns; $c++) {
                        $number = $columns === 1 ? (string) ($r + 1) : ($rows === 1 ? (string) ($c + 1) : ($r + 1) . '.' . ($c + 1));
                        $order = $r * $columns + $c;
                        $add(
                            "{$type['name']} {$number}", trim($labels[$order] ?? ''), $width, $depth, $height,
                            $c * ($width + $gap), $flat ? 0 : ($rows - 1 - $r) * ($height + $gap), $flat ? $r * ($depth + $gap) : 0, $order,
                        );
                    }
                }
            }
            Repo::rebuildPaths($pdo, (int) $room['id']);
            return $ids;
        });

        Audit::log($auth->userId, 'unit.generated', 'storage_unit', $id, ['rows' => $rows, 'columns' => $columns, 'stack' => $stack !== null, 'created' => count($created)], $request->ip);
        return Response::json(['created' => count($created), 'ids' => $created], 201);
    }

    /** @return list<array{name: string, label: string, height_mm: int}> */
    private static function readStack(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 50) {
            Input::fail('stack', 'Liste de 1 à 50 rangements attendue.');
        }
        $stack = [];
        foreach ($raw as $entry) {
            $in = new Input(is_array($entry) ? $entry : []);
            $stack[] = ['name' => $in->string('name', 120), 'label' => $in->optionalString('label', 50), 'height_mm' => $in->int('height_mm', 10, 5000)];
        }
        return $stack;
    }

    /**
     * Nombre d'objets différents en stock dans ces rangements.
     *
     * @param list<int> $unitIds
     */
    public static function stockCount(PDO $pdo, array $unitIds): int
    {
        if ($unitIds === []) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT item_id) FROM stock_levels WHERE quantity > 0 AND unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')');
        $stmt->execute($unitIds);
        return (int) $stmt->fetchColumn();
    }

    private static function requireEditRight(AuthContext $auth, ?int $parentId): void
    {
        $auth->require($parentId === null ? 'furniture.edit' : 'storage.edit');
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $type
     * @return array<string, mixed>
     */
    private static function read(Input $in, ?array $current, array $type): array
    {
        $fields = [
            'type_id' => [static fn () => $in->int('type_id', 1), (int) $type['id']],
            'name' => [static fn () => $in->string('name', 120), null],
            'label' => [static fn () => $in->optionalString('label', 50), ''],
            'reference' => [static fn () => $in->optionalString('reference', 50), ''],
            'description' => [static fn () => $in->optionalString('description', 1000), ''],
            'width_mm' => [static fn () => $in->int('width_mm', 1, 50_000), (int) $type['default_width_mm']],
            'depth_mm' => [static fn () => $in->int('depth_mm', 1, 50_000), (int) $type['default_depth_mm']],
            'height_mm' => [static fn () => $in->int('height_mm', 1, 50_000), (int) $type['default_height_mm']],
            'pos_x_mm' => [static fn () => $in->int('pos_x_mm', -200_000, 200_000), 0],
            'pos_y_mm' => [static fn () => $in->int('pos_y_mm', -200_000, 200_000), 0],
            'pos_z_mm' => [static fn () => $in->int('pos_z_mm', -200_000, 200_000), 0],
            'rotation_deg' => [static fn () => $in->int('rotation_deg', 0, 359), 0],
            'panel_mm' => [static fn () => $in->int('panel_mm', 0, 200), (int) $type['default_panel_mm']],
            'capacity' => [static fn () => $in->optionalQuantity('capacity'), false],
            'color' => [static fn () => $in->color('color'), $type['default_color']],
            'sort_order' => [static fn () => $in->int('sort_order', -100_000, 100_000), 0],
        ];
        $unit = [];
        foreach ($fields as $key => [$read, $default]) {
            $unit[$key] = match (true) {
                $in->has($key) => $read(),
                $current !== null && $key !== 'type_id' => is_int($default) ? (int) $current[$key] : $current[$key],
                $default === false => null,
                $default !== null => $default,
                default => $read(),
            };
        }
        $unit['reference'] = $unit['reference'] === '' || $unit['reference'] === null ? null : $unit['reference'];
        return $unit;
    }

    private static function assertReferenceFree(PDO $pdo, ?string $reference, ?int $exceptId): void
    {
        if ($reference === null) {
            return;
        }
        $stmt = $pdo->prepare('SELECT path_text FROM storage_units WHERE reference = ? AND id <> ?');
        $stmt->execute([$reference, $exceptId ?? 0]);
        if (($path = $stmt->fetchColumn()) !== false) {
            Input::fail('reference', "Référence déjà utilisée par : {$path}");
        }
    }

    private static function assertNotDescendant(PDO $pdo, int $unitId, int $newParentId): void
    {
        if ($newParentId === $unitId || in_array($newParentId, self::descendantIds($pdo, $unitId), true)) {
            Input::fail('parent_id', 'Un rangement ne peut pas être placé dans lui-même ou dans un de ses sous-rangements.');
        }
    }

    /** @return list<int> */
    private static function descendantIds(PDO $pdo, int $unitId): array
    {
        $ids = [];
        $frontier = [$unitId];
        while ($frontier !== []) {
            $stmt = $pdo->prepare('SELECT id FROM storage_units WHERE parent_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')');
            $stmt->execute($frontier);
            $frontier = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            array_push($ids, ...$frontier);
        }
        return $ids;
    }
}
