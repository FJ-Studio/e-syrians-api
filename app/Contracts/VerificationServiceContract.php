<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface VerificationServiceContract
{
    /**
     * Check if user A can verify user B
     *
     * @return array{0: bool, 1: string}
     */
    public function canUserVerify(User|int|string $verifier, User|int|string $target): array;

    /**
     * Verify a target user by a verifier
     *
     * @throws \Exception
     */
    public function verifyUser(User $verifier, string $targetUuid, ?string $ipAddress, ?string $userAgent): void;

    /**
     * Get verifications received by a user
     */
    public function getVerificationsForUser(User $user): mixed;

    /**
     * Get verifiers for a user
     */
    public function getVerifiersForUser(User $user): mixed;
}
