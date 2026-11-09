<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;

/** Utilisateur authentifié pour la requête en cours, avec ses permissions. */
final class AuthContext
{
    /** @param array<string, true> $permissions */
    public function __construct(
        public readonly int $userId,
        public readonly ?string $email,
        public readonly string $displayName,
        public readonly int $roleId,
        public readonly string $roleName,
        public readonly array $permissions,
        public readonly string $sessionId,
        public readonly string $csrfToken,
    ) {
    }

    public function can(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new HttpException(403, "Vous n'avez pas la permission d'effectuer cette action.", ['permission' => $permission]);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => [
                'id' => $this->userId,
                'email' => $this->email,
                'display_name' => $this->displayName,
                'role' => ['id' => $this->roleId, 'name' => $this->roleName],
            ],
            'permissions' => array_keys($this->permissions),
            'csrf_token' => $this->csrfToken,
        ];
    }
}
