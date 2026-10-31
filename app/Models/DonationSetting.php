<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{name: string, currency: string, network: ?string, address: string}> $wallets
 */
class DonationSetting extends Model
{
    public const int SINGLETON_ID = 1;

    protected $fillable = ['id', 'wallets'];

    protected function casts(): array
    {
        return ['wallets' => 'array'];
    }
}
