<?php

declare(strict_types=1);

use App\Controller\AllowlistController;
use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\RoleController;
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

return $router;
