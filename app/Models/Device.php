<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                              $id
 * @property int                              $user_id
 * @property string                           $subscription_id
 * @property string                           $platform        'ios' | 'android'
 * @property string|null                      $model
 * @property Carbon|null  $last_seen_at
 *
 * Route binding: routes that take `{device:subscription_id}` resolve
 * via the `subscription_id` column (NOT the auto-increment id) because
 * the mobile client knows the subscription_id but doesn't know the
 * row id. See `routes/api.php`.
 */
class Device extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'platform',
        'model',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * The route key used by Laravel's implicit route-model binding.
     * Mobile DELETE /users/devices/{subscription_id} → resolves a
     * Device by subscription_id, not by primary key.
     */
    public function getRouteKeyName(): string
    {
        return 'subscription_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
