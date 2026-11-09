<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    private ?string $raw = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly mixed $body = null,
        private array $headers = [],
    ) {
    }

    public static function json(mixed $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** Contenu binaire (photo) avec son type MIME. */
    public static function binary(string $content, string $contentType, string $cacheControl = 'private, max-age=86400'): self
    {
        $response = new self(200, null, ['Content-Type' => $contentType, 'Cache-Control' => $cacheControl, 'X-Content-Type-Options' => 'nosniff']);
        $response->raw = $content;
        return $response;
    }

    /** Redirection vers un chemin local uniquement (pas de redirection ouverte). */
    public static function redirect(string $path): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }
        return new self(302, null, ['Location' => $path]);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $message, array $details = []): self
    {
        return new self($status, ['error' => ['message' => $message] + $details]);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->raw === null) {
            header('Content-Type: application/json; charset=utf-8');
            // Les réponses de l'API ne doivent jamais être mises en cache (données authentifiées)
            header('Cache-Control: no-store');
        }
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->raw !== null) {
            echo $this->raw;
        } elseif ($this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
