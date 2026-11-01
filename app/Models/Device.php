<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $installation_id
 * @property int $user_id
 * @property string|null $expo_push_token
 * @property string|null $token_hash
 * @property int $registration_version
 * @property int|null $personal_access_token_id
 * @property string $platform
 * @property string|null $model
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $disabled_at
 */
class Device extends Model
{
    protected $guarded = [];

    protected $hidden = ['expo_push_token', 'token_hash', 'personal_access_token_id', 'subscription_id'];

    protected function casts(): array
    {
        return ['expo_push_token' => 'encrypted', 'last_seen_at' => 'datetime',
            'disabled_at' => 'datetime', 'registration_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function sessionToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    protected function scopeRoutable(Builder $query): void
    {
        $query->whereNull('disabled_at')->whereNotNull('expo_push_token')
            ->whereHas('user', fn (Builder $user) => $user->whereNull('deletion_scheduled_for')->whereNull('marked_as_fake_at'))
            ->whereHas('sessionToken', fn (Builder $token) => $token
                ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now())));
        $expiration = config('sanctum.expiration');
        if (is_numeric($expiration) && (int) $expiration > 0) {
            $query->whereHas('sessionToken', fn (Builder $token) => $token->where('created_at', '>', now()->subMinutes((int) $expiration)));
        }
    }
}
