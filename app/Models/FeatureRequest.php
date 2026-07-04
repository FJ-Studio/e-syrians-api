<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use App\Enums\FeatureRequestStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
     *
     * Return type stays `string` (not the enum instance) so the public API
     * contract is unchanged — mobile and web keep receiving `"idea"`,
     * `"in_development"`, `"in_testing"`, `"shipped"` verbatim. The enum is
     * the derivation authority; the accessor is just a thin shim.
     */
    protected function getStatusAttribute(): string
    {
        return FeatureRequestStatusEnum::fromFeatureRequest($this)->value;
    }

    /**
     * Restrict a query to rows currently at the given stage. Each stage
     * requires its own column set AND all later-stage columns null —
     * otherwise "in_development" would leak rows that have advanced past
     * it.
     *
     * Both `FeatureRequestService::applyStatusFilter` and the Filament
     * admin status filter delegate here so the "what does this stage
     * mean?" answer lives in exactly one place.
     *
     * @param  Builder<FeatureRequest>  $query
     * @return Builder<FeatureRequest>
     */
    protected function scopeAtStage(Builder $query, FeatureRequestStatusEnum $stage): Builder
    {
        return match ($stage) {
            FeatureRequestStatusEnum::Idea => $query
                ->whereNull('coded_at')
                ->whereNull('tested_at')
                ->whereNull('deployed_at'),
            FeatureRequestStatusEnum::InDevelopment => $query
                ->whereNotNull('coded_at')
                ->whereNull('tested_at')
                ->whereNull('deployed_at'),
            FeatureRequestStatusEnum::InTesting => $query
                ->whereNotNull('tested_at')
                ->whereNull('deployed_at'),
            FeatureRequestStatusEnum::Shipped => $query
                ->whereNotNull('deployed_at'),
        };
    }
}
