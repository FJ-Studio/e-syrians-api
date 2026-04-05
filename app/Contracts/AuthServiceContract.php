<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Http\JsonResponse;

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
     * @return array{user: User, token: string}|null
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
