<?php

declare(strict_types=1);

namespace App\Contracts;

use Exception;
use App\Models\User;
use DomainException;
use App\Models\UserVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
     * @throws Exception
     */
    public function verifyUser(User $verifier, string $targetUuid, ?string $ipAddress, ?string $userAgent): void;

    /**
     * Get verifications the user has issued (Sent tab on the
     * account dashboard). Returns a Laravel paginator so the
     * mobile + web clients can scroll through history rather
     * than be capped at a single fetch.
     */
    public function getVerificationsForUser(User $user, int $perPage = 25): LengthAwarePaginator;

    /**
     * Get verifications the user has received (Received tab on
     * the account dashboard). Paginated for the same reason as
     * getVerificationsForUser — and especially relevant here
     * since the received count is unbounded (verifiers can be
     * unlimited, while the verifier cap of 25 caps the Sent list).
     */
    public function getVerifiersForUser(User $user, int $perPage = 25): LengthAwarePaginator;

    /**
     * Mark a verification as cancelled by its verifier.
     *
     * Marks the row's `cancelled_at` and records the reason in
     * `cancelation_payload`. The verification stays in the DB
     * (soft-cancel) so the audit trail is preserved — list
     * endpoints filter by `whereNull('cancelled_at')` when they
     * only want active ones.
     *
     * @throws DomainException When the auth user isn't the
     *         verifier_id of the record, or when the record is
     *         already cancelled.
     */
    public function cancelVerificationByVerifier(
        User $verifier,
        UserVerification $verification,
        string $reason = 'cancelled_by_verifier',
    ): UserVerification;
}
