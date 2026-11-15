<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ?int $user_id
 * @property ?int $credit_id
 * @property string $platform
 * @property string $environment
 * @property string $product_id
 * @property string $proof_hash
 * @property string $proof
 * @property ?Carbon $reversed_at
 */
class IdentityStorePurchase extends Model
{
    protected $hidden = ['proof', 'proof_hash'];

    protected function casts(): array
    {
        return [
            'proof' => 'encrypted',
            'reversed_at' => 'datetime',
            'check_after' => 'datetime',
        ];
    }
}
