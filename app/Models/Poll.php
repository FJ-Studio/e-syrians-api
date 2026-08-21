<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Contracts\AudienceServiceContract;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property bool $is_restricted Dynamically set by PollService::getPollById()
 *
 * @method static Builder<static> visibleTo(?User $user)
 */
class Poll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'question',
        'start_date',
        'end_date',
        'max_selections',
        'audience_can_add_options',
        'deletion_reason',
        'deleted_at',
        'reveal_results',
        'voters_are_visible',
        'is_private',
        'audience_only',
        'audience_id',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'max_selections' => 'integer',
        'audience_can_add_options' => 'boolean',
        'voters_are_visible' => 'boolean',
        'is_private' => 'boolean',
        'audience_only' => 'boolean',
        'audience_id' => 'integer',
    ];

    protected $appends = ['ups_count', 'downs_count'];

    protected static function booted(): void
    {
        static::addGlobalScope('public_polls', function (Builder $builder): void {
            $builder->where('is_private', false);
        });
    }

    protected function getUpsCountAttribute()
    {
        return Cache::remember("poll_{$this->id}_ups_count", 60, function () {
            return $this->ups()->count();
        });
    }

    protected function getDownsCountAttribute()
    {
        return Cache::remember("poll_{$this->id}_downs_count", 60, function () {
            return $this->downs()->count();
        });
    }

    /**
     * Get the user that created the poll.
     */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the audience rules for the poll.
     *
     * @return HasMany<PollAudienceRule, $this>
     */
    public function audienceRules(): HasMany
    {
        return $this->hasMany(PollAudienceRule::class);
    }

    /**
     * Reusable audience list gating this poll's vote eligibility.
     *
     * Nullable — legacy polls (and polls that use the inline
     * `allowed_voters` list or demographic criteria) leave this
     * column NULL. When present, {@see User::isInAudience()}
     * short-circuits every other rule and asks
     * {@see AudienceServiceContract::isUserInAudience()}
     * whether the caller's hashed identifiers match an entry.
     *
     * The relation is intentionally named `savedAudience` — NOT
     * `audience` — because {@see self::getAudienceAttribute()}
     * already owns the `audience` attribute name for the API
     * payload. Colocating a relation and an accessor under the
     * same name works at runtime (accessor wins for bare property
     * access) but confuses PHPStan into inferring `Audience|null`
     * for `$poll->audience` reads.
     *
     * @return BelongsTo<Audience, $this>
     */
    public function savedAudience(): BelongsTo
    {
        return $this->belongsTo(Audience::class, 'audience_id');
    }

    /**
     * Get the options for the poll.
     *
     * @return HasMany<PollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class);
    }

    /**
     * Get the votes for the poll.
     *
     * @return HasMany<PollVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Get the voters for the poll.
     *
     * @return HasManyThrough<User, PollVote, $this>
     */
    public function voters(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, PollVote::class, 'poll_id', 'id', 'id', 'user_id');
    }

    public function uniqueVotersCount()
    {
        return $this->votes()->distinct('user_id')->count('user_id');
    }

    /**
     * Get the reactions for the poll.
     *
     * @return HasMany<PollReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(PollReaction::class);
    }

    /**
     * Build the audience array from normalized rules for API responses.
     *
     * Precedence:
     *   1. Reusable saved Audience (`audience_id`) — return a
     *      dedicated shape so the client can render "gated by
     *      audience: <name>" instead of the demographic scaffold.
     *      Also loads a small view-only summary (uuid + name +
     *      entry counts). We deliberately return an OBJECT under
     *      `audience` (not `allowed_voters`) so the client can
     *      distinguish "one saved list" from "an ad-hoc paste".
     *   2. Inline `allowed_voters` list.
     *   3. Demographic rules (the historical default shape).
     */
    protected function getAudienceAttribute(): array
    {
        if ($this->audience_id !== null) {
            // Relation is `savedAudience()` (see note on the method),
            // not `audience()` — accessing `$this->audience` here
            // would re-enter THIS accessor and blow the stack.
            if (! $this->relationLoaded('savedAudience')) {
                $this->load(['savedAudience' => function ($q): void {
                    // Same aggregate columns AudienceService::list uses,
                    // so PollResource can render an entry-count badge
                    // without a second round-trip.
                    $q->withCount([
                        'entries as entries_total_count',
                        'entries as entries_resolved_count' => function ($sub): void {
                            $sub->whereNotNull('resolved_user_id');
                        },
                    ]);
                }]);
            }

            /** @var Audience|null $audience */
            $audience = $this->getRelation('savedAudience');

            return [
                'audience' => $audience === null
                    // Trashed / detached FK — surface an explicit
                    // "missing" marker rather than pretending the
                    // poll has no audience. Vote check treats a
                    // trashed audience as empty anyway.
                    ? ['status' => 'missing']
                    : [
                        'uuid' => $audience->uuid,
                        'name' => $audience->name,
                        'entries_total_count' => (int) ($audience->entries_total_count ?? 0),
                        'entries_resolved_count' => (int) ($audience->entries_resolved_count ?? 0),
                    ],
            ];
        }

        if (! $this->relationLoaded('audienceRules')) {
            $this->load('audienceRules');
        }

        $rules = $this->audienceRules;

        $allowedVoters = $rules->where('criterion', 'allowed_voter')->pluck('value')->all();
        if (count($allowedVoters) > 0) {
            return ['allowed_voters' => $allowedVoters];
        }

        return [
            'gender' => $rules->where('criterion', 'gender')->pluck('value')->all(),
            'age_range' => [
                'min' => (int) ($rules->where('criterion', 'age_min')->first()?->value ?? 13), // @phpstan-ignore nullsafe.neverNull
                'max' => (int) ($rules->where('criterion', 'age_max')->first()?->value ?? 120), // @phpstan-ignore nullsafe.neverNull
            ],
            'country' => $rules->where('criterion', 'country')->pluck('value')->all(),
            'religious_affiliation' => $rules->where('criterion', 'religious_affiliation')->pluck('value')->all(),
            'hometown' => $rules->where('criterion', 'hometown')->pluck('value')->all(),
            'ethnicity' => $rules->where('criterion', 'ethnicity')->pluck('value')->all(),
            'province' => $rules->where('criterion', 'province')->pluck('value')->all(),
        ];
    }

    /**
     * Check if the poll is visible to the given user.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $this->audience_only) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->id === $this->created_by) {
            return true;
        }

        [$eligible] = $user->isInAudience($this);

        return $eligible;
    }

    /**
     * Compute audience eligibility for the given user.
     *
     * @return array{0: bool, 1: array<int, string>} [is_in_audience, failures]
     */
    public function audienceCheckFor(?User $user): array
    {
        if (! $this->relationLoaded('audienceRules')) {
            $this->load('audienceRules');
        }

        // No rules defined → everyone is in the audience.
        if ($this->audienceRules->isEmpty()) {
            return [true, []];
        }

        // Creator always counts as in-audience for their own poll.
        if ($user && $user->id === $this->created_by) {
            return [true, []];
        }

        // Guests fail without leaking which specific criteria they miss.
        if (! $user) {
            return [false, ['unauthenticated']];
        }

        return $user->isInAudience($this);
    }

    /**
     * Scope: only polls visible to the given user.
     * Filters audience_only polls at the SQL level.
     */
    protected function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->where('audience_only', false);
        }

        $userAge = $user->birth_date
            ? (int) now()->diffInYears($user->birth_date)
            : null;

        return $query->where(function (Builder $q) use ($user, $userAge): void {
            $q->where('audience_only', false)
                ->orWhere('created_by', $user->id)
                ->orWhere(function (Builder $q) use ($user, $userAge): void {
                    // The user matches the audience: no criterion type is unmatched

                    // Handle allowed_voter: if poll has allowed_voter rules,
                    // user must match by email or national_id
                    $q->where(function (Builder $q) use ($user): void {
                        $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'allowed_voter'))
                            ->orWhereHas('audienceRules', fn ($r) => $r->where('criterion', 'allowed_voter')
                                ->where(function ($r) use ($user): void {
                                    $r->where('value', strtolower($user->email ?? ''));
                                    if ($user->national_id) {
                                        $r->orWhere('value', strtolower($user->national_id));
                                    }
                                }));
                    });

                    // Handle standard criteria: for each type that exists, user must match
                    $standardCriteria = [
                        'gender' => $user->gender,
                        'country' => $user->country,
                        'hometown' => $user->hometown,
                        'religious_affiliation' => $user->religious_affiliation,
                        'ethnicity' => $user->ethnicity,
                        'province' => $user->province,
                    ];

                    foreach ($standardCriteria as $criterion => $userValue) {
                        $q->where(function (Builder $q) use ($criterion, $userValue): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', $criterion));

                            if ($userValue) {
                                $q->orWhereHas('audienceRules', fn ($r) => $r->where('criterion', $criterion)->where('value', $userValue));
                            }
                        });
                    }

                    // Handle age rules.
                    // When the user has no birth_date we cannot verify age,
                    // so exclude polls that define any age criterion.
                    if ($userAge !== null) {
                        $q->where(function (Builder $q) use ($userAge): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_min'))
                                ->orWhereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_min')
                                    ->where(DB::raw('CAST(value AS SIGNED)'), '>', $userAge));
                        });

                        $q->where(function (Builder $q) use ($userAge): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_max'))
                                ->orWhereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_max')
                                    ->where(DB::raw('CAST(value AS SIGNED)'), '<', $userAge));
                        });
                    } else {
                        $q->whereDoesntHave('audienceRules', fn ($r) => $r->whereIn('criterion', ['age_min', 'age_max']));
                    }
                });
        });
    }

    /**
     * Get the upvote reactions for the poll.
     *
     * @return HasMany<PollReaction, $this>
     */
    public function ups(): HasMany
    {
        return $this->reactions()->where('reaction', 'up');
    }

    /**
     * Get the downvote reactions for the poll.
     *
     * @return HasMany<PollReaction, $this>
     */
    public function downs(): HasMany
    {
        return $this->reactions()->where('reaction', 'down');
    }
}
