<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\IdentityVerificationCredit;

class IdentityVerificationService extends IdentitySandboxService
{
    public function __construct(DiditLive $didit)
    {
        parent::__construct($didit);
    }

    public function allowed(User $user): bool
    {
        return config('identity-verification.enabled') === true && ! $user->trashed() && ! $user->hasPendingDeletion();
    }

    public function mode(): string
    {
        return 'live';
    }

    public function eraseSessions(User $user): void
    {
        // Erasure is part of permanent deletion, including legacy soft-deleted
        // accounts. Normal verification actions still use active-only lookups.
        User::withTrashed()->lockForUpdate()->findOrFail($user->id);
        $credits = IdentityVerificationCredit::query()->where('owner_id', $user->id)
            ->whereNotNull('provider_session_id')->lockForUpdate()->get(['provider_session_id']);
        $didit = resolve(DiditLive::class);
        foreach ($credits as $credit) {
            if ($credit->provider_session_id !== null) {
                $didit->erase($credit->provider_session_id);
            }
        }
    }

    protected function lockUser(User $user): void
    {
        $current = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_unless($this->allowed($current), 404);
    }

    protected function canClaim(User $user): bool
    {
        return false;
    }

    protected function newCredit(): IdentityVerificationCredit
    {
        return new IdentityVerificationCredit();
    }

    protected function ledgerTable(): string
    {
        return 'identity_verification_credit_events';
    }

    protected function giftTable(): string
    {
        return 'identity_verification_gift_codes';
    }
}
