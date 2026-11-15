<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** @property ?Carbon $consented_at */
class IdentityVerificationCredit extends IdentitySandboxCredit
{
    protected $table = 'identity_verification_credits';

    protected $hidden = ['attempt_uuid', 'provider_session_id', 'provider_url', 'granted_by_user_id', 'grant_reason', 'consented_at'];

    protected function casts(): array
    {
        return [...parent::casts(), 'consented_at' => 'datetime'];
    }

    public function isTransferableSource(): bool
    {
        return $this->source === self::SOURCE_PURCHASE;
    }
}
