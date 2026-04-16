<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'created_by',
        'coded_at',
        'tested_at',
        'deployed_at',
        'deletion_reason',
    ];

    protected $casts = [
        'coded_at' => 'datetime',
        'tested_at' => 'datetime',
        'deployed_at' => 'datetime',
    ];

    protected $appends = ['ups_count', 'downs_count', 'score', 'status'];

    /**
     * User who submitted the feature request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * All votes on this feature request.
     */
    public function votes(): HasMany
    {
        return $this->hasMany(FeatureRequestVote::class);
    }

    /**
     * Upvotes only.
     */
    public function ups(): HasMany
    {
        return $this->votes()->where('vote', 'up');
    }

    /**
     * Downvotes only.
     */
    public function downs(): HasMany
    {
        return $this->votes()->where('vote', 'down');
    }

    /**
     * Upvote count. When the query ran with `withCount('ups as ups_aggregate_count')`
     * — which FeatureRequestService::buildQuery does — we read the pre-aggregated
     * column directly and avoid N+1. Otherwise we fall back to a 60-second cached
     * lookup. The cache is invalidated by the service on every vote mutation.
     */
    protected function getUpsCountAttribute(): int
    {
        if (array_key_exists('ups_aggregate_count', $this->attributes)) {
            return (int) $this->attributes['ups_aggregate_count'];
        }

        return (int) Cache::remember(
            "feature_request_{$this->id}_ups_count",
            60,
            fn (): int => $this->ups()->count(),
        );
    }

    /**
     * Downvote count. See comment on getUpsCountAttribute.
     */
    protected function getDownsCountAttribute(): int
    {
        if (array_key_exists('downs_aggregate_count', $this->attributes)) {
            return (int) $this->attributes['downs_aggregate_count'];
        }

        return (int) Cache::remember(
            "feature_request_{$this->id}_downs_count",
            60,
            fn (): int => $this->downs()->count(),
        );
    }

    /**
     * ups - downs. Used for default popularity ordering.
     */
    protected function getScoreAttribute(): int
    {
        return $this->ups_count - $this->downs_count;
    }

    /**
     * Status is derived from the four timeline timestamps — no status column
     * exists. The timeline is the single source of truth so clients never
     * see status and timestamps drift out of sync.
     */
    protected function getStatusAttribute(): string
    {
        if ($this->deployed_at !== null) {
            return 'shipped';
        }

        if ($this->tested_at !== null) {
            return 'in_testing';
        }

        if ($this->coded_at !== null) {
            return 'in_development';
        }

        return 'idea';
    }
}
