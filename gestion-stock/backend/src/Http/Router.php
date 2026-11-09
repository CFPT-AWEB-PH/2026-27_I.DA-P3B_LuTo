<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{string, string, callable(Request, array<string, string>): Response, bool}> */
    private array $routes = [];

    /**
     * $pattern accepte des paramètres « {nom} » ou contraints « {nom:\d+} ».
     * Toute route exige une session, sauf si elle est déclarée publique : un oubli dans un contrôleur
     * ne peut donc pas ouvrir une route aux anonymes. Les permissions restent vérifiées par le contrôleur.
     *
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler, bool $public = false): void
    {
        $regex = '#^' . preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern,
        ) . '$#D';
        $this->routes[] = [strtoupper($method), $regex, $handler, $public];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $public]) {
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }
            if (!$public) {
                $request->user();
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
