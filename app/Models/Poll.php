<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'max_selections' => 'integer',
        'audience_can_add_options' => 'boolean',
        'voters_are_visible' => 'boolean',
        'is_private' => 'boolean',
        'audience_only' => 'boolean',
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
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the audience rules for the poll.
     */
    public function audienceRules()
    {
        return $this->hasMany(PollAudienceRule::class);
    }

    /**
     * Get the options for the poll.
     */
    public function options()
    {
        return $this->hasMany(PollOption::class);
    }

    /**
     * Get the votes for the poll.
     */
    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Get the voters for the poll.
     */
    public function voters()
    {
        return $this->hasManyThrough(User::class, PollVote::class, 'poll_id', 'id', 'id', 'user_id');
    }

    public function uniqueVotersCount()
    {
        return $this->votes()->distinct('user_id')->count('user_id');
    }

    /**
     * Get the reactions for the poll.
     */
    public function reactions()
    {
        return $this->hasMany(PollReaction::class);
    }

    /**
     * Build the audience array from normalized rules for API responses.
     */
    public function getAudienceAttribute(): array
    {
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
                'min' => (int) ($rules->where('criterion', 'age_min')->first()?->value ?? 13),
                'max' => (int) ($rules->where('criterion', 'age_max')->first()?->value ?? 120),
            ],
            'country' => $rules->where('criterion', 'country')->pluck('value')->all(),
            'religious_affiliation' => $rules->where('criterion', 'religious_affiliation')->pluck('value')->all(),
            'hometown' => $rules->where('criterion', 'hometown')->pluck('value')->all(),
            'ethnicity' => $rules->where('criterion', 'ethnicity')->pluck('value')->all(),
            'city_inside_syria' => $rules->where('criterion', 'city_inside_syria')->pluck('value')->all(),
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
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
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
                        'city_inside_syria' => $user->city_inside_syria,
                    ];

                    foreach ($standardCriteria as $criterion => $userValue) {
                        $q->where(function (Builder $q) use ($criterion, $userValue): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', $criterion));

                            if ($userValue) {
                                $q->orWhereHas('audienceRules', fn ($r) => $r->where('criterion', $criterion)->where('value', $userValue));
                            }
                        });
                    }

                    // Handle age_min
                    if ($userAge !== null) {
                        $q->where(function (Builder $q) use ($userAge): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_min'))
                                ->orWhereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_min')
                                    ->where(DB::raw('CAST(value AS UNSIGNED)'), '>', $userAge));
                        });

                        $q->where(function (Builder $q) use ($userAge): void {
                            $q->whereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_max'))
                                ->orWhereDoesntHave('audienceRules', fn ($r) => $r->where('criterion', 'age_max')
                                    ->where(DB::raw('CAST(value AS UNSIGNED)'), '<', $userAge));
                        });
                    }
                });
        });
    }

    /**
     * Get the upvote reactions for the poll.
     */
    public function ups()
    {
        return $this->reactions()->where('reaction', 'up');
    }

    /**
     * Get the downvote reactions for the poll.
     */
    public function downs()
    {
        return $this->reactions()->where('reaction', 'down');
    }
}
