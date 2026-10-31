<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface AccountDeletionServiceContract
{
    /**
     * Mark the account for deletion — sets both timestamps
     * (deletion_requested_at = now, deletion_scheduled_for = now +
     * grace window). Sessions/tokens are NOT revoked so the user
     * can still hit cancel-deletion / deletion-status during the
     * grace window. Throws AccountDeletionException on wrong
     * password or when the account already has a pending deletion.
     *
     * @return array{deletion_requested_at: string|null, deletion_scheduled_for: string|null, is_pending: bool, requires_password: bool}
     */
    public function requestDeletion(User $user, string $password): array;

    /**
     * Clear both timestamps. Throws AccountDeletionException on
     * wrong password or when no deletion is currently scheduled.
     *
     * @return array{deletion_requested_at: string|null, deletion_scheduled_for: string|null, is_pending: bool, requires_password: bool}
     */
    public function cancelDeletion(User $user, string $password): array;

    /**
     * @return array{deletion_requested_at: string|null, deletion_scheduled_for: string|null, is_pending: bool, requires_password: bool}
     */
    public function getDeletionStatus(User $user): array;

    /**
     * Hard-delete a user's account and cascade-clean everything they
     * own (polls, votes, reactions, audiences, verifications,
     * notifications, avatar). Wrapped in a DB transaction by the caller.
     */
    public function hardDeleteAccount(User $user): void;
}
