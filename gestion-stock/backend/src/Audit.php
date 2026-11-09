<?php

declare(strict_types=1);

namespace App;

/** Journal d'audit des opérations importantes (connexions, droits, configuration). */
final class Audit
{
    /** @param array<string, mixed> $details */
    public static function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        string $ip = '',
    ): void {
        Database::connection()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $ip === '' ? null : $ip,
        ]);
    }
}
