<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-owned, reusable list of identifiers (emails + national IDs)
 * that a poll creator can attach to a poll's audience field instead
 * of pasting the same raw list every time.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Audience extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        // user_id is set by the service from the auth context —
        // never mass-assigned from a request payload.
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Audience $audience): void {
            if (empty($audience->uuid)) {
                $audience->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Route-model binding uses uuid so the URL isn't a guessable
     * enumeration of the audiences table. Controllers additionally
     * scope by `user_id` to enforce ownership (404 for others).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generic annotation is what lets PHPStan see
     * `$audience->entries()->get()` as
     * `Eloquent\Collection<int, AudienceEntry>` instead of the
     * default `Eloquent\Collection<int, Model>`. Without it,
     * downstream callers that narrow to `Support\Collection<int,
     * AudienceEntry>` (e.g. AudienceService::resolveEntries)
     * trigger `argument.type` errors even though the runtime is
     * correct.
     *
     * @return HasMany<AudienceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AudienceEntry::class);
    }

    /** @return HasMany<AudienceAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(AudienceAudit::class);
    }

    /**
     * All polls that currently reference this audience. Used by
     * AudienceService::detectActivePolls to decide whether an edit
     * needs an audit-row emission.
     *
     * @return HasMany<Poll, $this>
     */
    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class);
    }
}
