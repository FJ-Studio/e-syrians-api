<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface AuthServiceContract
{
    /**
     * Authenticate via social provider (Google, etc.)
     *
     * @return array{user: User, token: string}|null
     */
    public function authenticateViaSocialProvider(string $provider, string $token): ?array;

    /**
     * Authenticate via credentials (email/phone/national_id + password)
     *
     * Returns token on success, or 2FA challenge data if 2FA is enabled.
     *
     * @return array{user: User, token?: string, requires_2fa?: bool, challenge_token?: string, expires_at?: string}|null
     */
    public function authenticateViaCredentials(string $identifier, string $password): ?array;

    /**
     * Register a new user
     */
    public function register(array $data): User;

    /**
     * Revoke all tokens for a user
     */
    public function logout(User $user): void;

    /**
     * Verify a user's email address
     *
     * @return array{success: bool, message: string, code: int}
     */
    public function verifyEmail(int $userId, string $hash, string $signature): array;
}
