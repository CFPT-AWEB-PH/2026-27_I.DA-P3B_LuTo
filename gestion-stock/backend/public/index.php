<?php

declare(strict_types=1);

use App\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

require __DIR__ . '/../bootstrap.php';

$requestId = bin2hex(random_bytes(8));

try {
    $request = Request::fromGlobals();
    $router = require __DIR__ . '/../routes.php';
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = Response::error($e->status, $e->getMessage(), $e->details);
} catch (\Throwable $e) {
    error_log(sprintf('[%s] %s: %s (%s:%d)', $requestId, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $details = ['request_id' => $requestId];
    if (!Config::isProduction()) {
        $details['exception'] = $e->getMessage();
    }
    $response = Response::error(500, 'Erreur interne du serveur.', $details);
}

$response->withHeader('X-Request-Id', $requestId)->send();
