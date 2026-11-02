<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Http\Router;

$router = new Router();

$router->add('GET', '/health', [HealthController::class, 'show']);

return $router;
