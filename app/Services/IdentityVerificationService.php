<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
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

    public function providerReady(): bool
    {
        return $this->didit->configured();
    }

    public function mode(): string
    {
        return 'live';
    }

    // Caller must hold a lock on the order and issue this in the same transaction.
    public function fulfillPurchase(User $buyer): IdentityVerificationCredit
    {
        $credit = $this->newCredit();
        $credit->forceFill([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $buyer->id,
            'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        ])->save();
        $this->recordCreditEvent($buyer, $credit, 'purchased', now());

        return $credit;
    }

    public function eraseSessions(User $user): void
    {
        User::query()->lockForUpdate()->findOrFail($user->id);
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
