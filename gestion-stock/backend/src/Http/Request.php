<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthContext;

final class Request
{
    private const MAX_JSON_BYTES = 1_048_576;

    /** Session de l'utilisateur connecté, résolue par le contrôleur frontal. */
    public ?AuthContext $auth = null;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers clés en minuscules
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $ip,
        public readonly bool $https,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim((string) preg_replace('#^/api#', '', $path), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            array_map('strval', $_COOKIE),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ($_SERVER['HTTPS'] ?? '') === 'on',
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /** Utilisateur connecté, sinon 401. */
    public function user(): AuthContext
    {
        return $this->auth ?? throw new HttpException(401, 'Connexion requise.');
    }

    /** Utilisateur connecté possédant la permission, sinon 401 ou 403. */
    public function requirePermission(string $permission): AuthContext
    {
        $auth = $this->user();
        $auth->require($permission);
        return $auth;
    }

    public function input(): Input
    {
        return new Input($this->json());
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (!str_starts_with((string) $this->header('content-type'), 'application/json')) {
            throw new HttpException(415, 'Le corps de la requête doit être au format JSON.');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        if (strlen($raw) > self::MAX_JSON_BYTES) {
            throw new HttpException(413, 'Requête trop volumineuse.');
        }
        try {
            $data = json_decode($raw === '' ? '{}' : $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'JSON invalide.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpException(400, 'Un objet JSON est attendu.');
        }
        return $this->json = $data;
    }
}
