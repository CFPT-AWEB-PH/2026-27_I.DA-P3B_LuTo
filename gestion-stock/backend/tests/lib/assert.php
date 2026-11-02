<?php

declare(strict_types=1);

// Assertions communes aux tests d'intégration.

$failures = 0;
$count = 0;

function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "  ok    {$label}\n";
        return;
    }
    $failures++;
    echo "  ÉCHEC {$label}" . ($detail === null ? '' : ' -> ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
}

function status(string $label, array $response, int $expected): void
{
    check("{$label} [{$expected}]", $response['status'] === $expected, ['status' => $response['status'], 'body' => $response['body']]);
}

function location(array $response): string
{
    return $response['headers']['location'][0] ?? '';
}

function finish(): never
{
    global $failures, $count;
    echo "\n{$count} vérifications, {$failures} échec(s).\n";
    exit($failures === 0 ? 0 : 1);
}
