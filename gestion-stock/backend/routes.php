<?php

declare(strict_types=1);

use App\Controller\AllowlistController;
use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\RoleController;
use App\Controller\RoomController;
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

return $router;
