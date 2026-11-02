<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{string, string, callable(Request, array<string, string>): Response}> */
    private array $routes = [];

    /**
     * $pattern accepte des paramètres « {nom} » ou contraints « {nom:\d+} ».
     *
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        ) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request, $params);
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Méthode non autorisée.');
        }
        throw new HttpException(404, 'Ressource introuvable.');
    }
}
