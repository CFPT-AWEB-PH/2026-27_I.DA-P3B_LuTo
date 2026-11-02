<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
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
        header('Content-Type: application/json; charset=utf-8');
        // Les réponses de l'API ne doivent jamais être mises en cache (données authentifiées)
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->status !== 204) {
            echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
}
