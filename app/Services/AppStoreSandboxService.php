<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/** Isolated StoreKit review wallet; never creates a paid credit or public badge. */
class AppStoreSandboxService extends IdentitySandboxService
{
    public function allowed(User $user): bool
    {
        return config('identity-verification.store.ios.sandbox_enabled') === true
            && in_array((string) $user->uuid, (array) config('identity-sandbox.test_users'), true)
            && ! $user->trashed() && ! $user->hasPendingDeletion();
    }

    protected function canClaim(User $user): bool
    {
        return false;
    }
}
