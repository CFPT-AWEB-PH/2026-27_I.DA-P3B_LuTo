<?php

declare(strict_types=1);

namespace Tests;

/** Client HTTP minimal pour les tests d'API : conserve son propre cookie de session. */
final class ApiClient
{
    public ?string $session = null;
    public ?string $csrf = null;

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, list<string>>, body: mixed}
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = [], bool $withCsrf = true): array
    {
        $lines = ['Accept: application/json'];
        if ($this->session !== null) {
            $lines[] = 'Cookie: gs_session=' . $this->session;
        }
        if ($withCsrf && $this->csrf !== null && $method !== 'GET') {
            $lines[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($json !== null) {
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);
        $raw = @file_get_contents($this->baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('#^HTTP/\S+ (\d{3})#', $responseHeaders[0] ?? '', $m);

        $parsed = [];
        foreach (array_slice($responseHeaders, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $parsed[strtolower($name)][] = $value;
        }
        foreach ($parsed['set-cookie'] ?? [] as $cookie) {
            if (preg_match('/^gs_session=([^;]*)/', $cookie, $c)) {
                $this->session = $c[1] === '' ? null : $c[1];
            }
        }
        return [
            'status' => (int) ($m[1] ?? 0),
            'headers' => $parsed,
            'body' => $raw === false || $raw === '' ? null : json_decode($raw, true),
        ];
    }

    /** Ouvre un lien de connexion et charge le jeton CSRF de la session obtenue. */
    public function login(string $token): array
    {
        $response = $this->request('GET', '/api/auth/link?token=' . $token);
        if ($this->session !== null) {
            $me = $this->request('GET', '/api/auth/me');
            $this->csrf = $me['body']['csrf_token'] ?? null;
        }
        return $response;
    }
}
