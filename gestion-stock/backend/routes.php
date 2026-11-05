<?php

declare(strict_types=1);

use App\Controller\AllowlistController;
use App\Controller\AuthController;
use App\Controller\CatalogController;
use App\Controller\DashboardController;
use App\Controller\HealthController;
use App\Controller\ItemController;
use App\Controller\RoleController;
use App\Controller\RoomController;
use App\Controller\SettingsController;
use App\Controller\StockController;
use App\Controller\StorageTypeController;
use App\Controller\StorageUnitController;
use App\Controller\UserController;
use App\Http\Router;

// Chaque contrôleur vérifie lui-même l'authentification et les permissions.
$router = new Router();

$router->add('GET', '/health', [HealthController::class, 'show']);

$router->add('GET', '/auth/config', [AuthController::class, 'config']);
$router->add('GET', '/auth/me', [AuthController::class, 'me']);
$router->add('GET', '/auth/link', [AuthController::class, 'link']);
$router->add('POST', '/auth/logout', [AuthController::class, 'logout']);

$router->add('GET', '/users', [UserController::class, 'index']);
$router->add('GET', '/users/options', [UserController::class, 'options']);
$router->add('PATCH', '/users/{id:\d+}', [UserController::class, 'update']);

$router->add('GET', '/roles', [RoleController::class, 'index']);
$router->add('POST', '/roles', [RoleController::class, 'create']);
$router->add('PATCH', '/roles/{id:\d+}', [RoleController::class, 'update']);
$router->add('DELETE', '/roles/{id:\d+}', [RoleController::class, 'delete']);
$router->add('GET', '/permissions', [RoleController::class, 'permissions']);

$router->add('GET', '/allowlist', [AllowlistController::class, 'index']);
$router->add('POST', '/allowlist', [AllowlistController::class, 'create']);
$router->add('DELETE', '/allowlist/{id:\d+}', [AllowlistController::class, 'delete']);

$router->add('GET', '/rooms', [RoomController::class, 'index']);
$router->add('POST', '/rooms', [RoomController::class, 'create']);
$router->add('GET', '/rooms/{id:\d+}', [RoomController::class, 'show']);
$router->add('PATCH', '/rooms/{id:\d+}', [RoomController::class, 'update']);
$router->add('DELETE', '/rooms/{id:\d+}', [RoomController::class, 'delete']);
$router->add('POST', '/rooms/{id:\d+}/duplicate', [RoomController::class, 'duplicate']);
$router->add('POST', '/rooms/{id:\d+}/openings', [RoomController::class, 'createOpening']);
$router->add('PATCH', '/openings/{id:\d+}', [RoomController::class, 'updateOpening']);
$router->add('DELETE', '/openings/{id:\d+}', [RoomController::class, 'deleteOpening']);

$router->add('POST', '/storage-units', [StorageUnitController::class, 'create']);
$router->add('PATCH', '/storage-units/{id:\d+}', [StorageUnitController::class, 'update']);
$router->add('DELETE', '/storage-units/{id:\d+}', [StorageUnitController::class, 'delete']);
$router->add('POST', '/storage-units/{id:\d+}/generate', [StorageUnitController::class, 'generate']);

$router->add('GET', '/storage-types', [StorageTypeController::class, 'index']);
$router->add('POST', '/storage-types', [StorageTypeController::class, 'create']);
$router->add('PATCH', '/storage-types/{id:\d+}', [StorageTypeController::class, 'update']);
$router->add('DELETE', '/storage-types/{id:\d+}', [StorageTypeController::class, 'delete']);

$router->add('GET', '/storage-units/{id:\d+}/contents', [StockController::class, 'contents']);

$router->add('GET', '/items', [ItemController::class, 'index']);
$router->add('GET', '/items/suggest', [ItemController::class, 'suggest']);
$router->add('POST', '/items', [ItemController::class, 'create']);
$router->add('GET', '/items/{id:\d+}', [ItemController::class, 'show']);
$router->add('PATCH', '/items/{id:\d+}', [ItemController::class, 'update']);
$router->add('DELETE', '/items/{id:\d+}', [ItemController::class, 'delete']);
$router->add('POST', '/items/{id:\d+}/restore', [ItemController::class, 'restore']);
$router->add('GET', '/items/{id:\d+}/photo', [ItemController::class, 'photo']);
$router->add('POST', '/items/{id:\d+}/photo', [ItemController::class, 'uploadPhoto']);
$router->add('DELETE', '/items/{id:\d+}/photo', [ItemController::class, 'deletePhoto']);

$router->add('GET', '/categories', [CatalogController::class, 'categories']);
$router->add('POST', '/categories', [CatalogController::class, 'saveCategory']);
$router->add('PATCH', '/categories/{id:\d+}', [CatalogController::class, 'saveCategory']);
$router->add('DELETE', '/categories/{id:\d+}', [CatalogController::class, 'deleteCategory']);
$router->add('GET', '/fields', [CatalogController::class, 'fields']);
$router->add('POST', '/fields', [CatalogController::class, 'saveField']);
$router->add('PATCH', '/fields/{id:\d+}', [CatalogController::class, 'saveField']);
$router->add('DELETE', '/fields/{id:\d+}', [CatalogController::class, 'deleteField']);

$router->add('POST', '/stock/take', [StockController::class, 'take']);
$router->add('POST', '/stock/add', [StockController::class, 'add']);
$router->add('POST', '/stock/adjust', [StockController::class, 'adjust']);
$router->add('POST', '/stock/move', [StockController::class, 'move']);
$router->add('GET', '/loans', [StockController::class, 'loans']);
$router->add('POST', '/loans/{id:\d+}/return', [StockController::class, 'giveBack']);
$router->add('GET', '/movements', [StockController::class, 'movements']);

$router->add('GET', '/dashboard', [DashboardController::class, 'show']);

$router->add('GET', '/settings', [SettingsController::class, 'show']);
$router->add('PATCH', '/settings', [SettingsController::class, 'update']);

return $router;
