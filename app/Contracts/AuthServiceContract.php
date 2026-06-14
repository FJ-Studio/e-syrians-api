<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface AuthServiceContract
{
    /**
     * Authenticate via social provider (Google, Apple).
     *
     * The optional $clientName is used by Apple sign-in: Apple only sends the
     * user's name on the very first sign-in (and via the SDK, not the JWT),
     * so callers forward it here so the user record gets a real name on
     * creation instead of an email-derived fallback.
     *
     * @return array{user: User, token: string}|null
     */
    public function authenticateViaSocialProvider(string $provider, string $token, ?string $clientName = null): ?array;

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
     * Check whether an email is free to register.
     *
     * Called by the mobile sign-up wizard's step-1 Continue handler so
     * the user is told up-front that the email is already taken —
     * rather than filling the remaining steps and bouncing off the
     * unique validator in `register()`. Case + whitespace
     * normalization happen inside the implementation so callers may
     * pass the raw user input.
     */
    public function isEmailAvailable(string $email): bool;

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
