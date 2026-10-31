<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $client_id
 * @property string $refresh_token
 */
class AppleRefreshToken extends Model
{
    protected $fillable = ['user_id', 'client_id', 'token_hash', 'refresh_token'];

    protected $hidden = ['refresh_token', 'token_hash'];

    protected static function booted(): void
    {
        static::saving(function (self $grant): void {
            $grant->token_hash = hash('sha256', $grant->refresh_token);
        });
    }

    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted'];
    }
}
