<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uuid
 * @property ?int $user_id
 * @property ?int $credit_id
 * @property ?string $stripe_session_id
 * @property ?string $stripe_payment_intent
 * @property ?string $stripe_price_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $locale
 * @property bool $livemode
 * @property string $status
 * @property Carbon $created_at
 */
class IdentityVerificationOrder extends Model
{
    protected $fillable = ['uuid', 'user_id', 'amount_minor', 'currency', 'locale', 'livemode', 'stripe_price_id'];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['stripe_session_id', 'stripe_payment_intent', 'stripe_price_id'];

    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'amount_minor' => 'integer'];
    }
}
