<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    private const MAX_JSON_BYTES = 1_048_576;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers clés en minuscules
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $ip,
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
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
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
        if (!is_array($data)) {
            throw new HttpException(400, 'Un objet JSON est attendu.');
        }
        return $this->json = $data;
    }
}
