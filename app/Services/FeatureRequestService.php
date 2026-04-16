<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeatureRequest;
use App\Models\FeatureRequestVote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;
use App\Exceptions\FeatureRequestException;
use App\Contracts\FeatureRequestServiceContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FeatureRequestService implements FeatureRequestServiceContract
{
    private const PER_PAGE = 20;

    /**
     * Allowed status filters. Matches FeatureRequest::status accessor.
     */
    private const STATUSES = ['idea', 'in_development', 'in_testing', 'shipped'];

    public function getPaginatedFeatureRequests(string $sort, ?string $status, ?int $userId): LengthAwarePaginator
    {
        $query = $this->buildQuery($userId);

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $this->applyStatusFilter($query, $status);
        }

        $this->applySort($query, $sort);

        return $query->paginate(self::PER_PAGE);
    }

    public function getFeatureRequestById(int $id, ?int $userId): FeatureRequest
    {
        /** @var FeatureRequest */
        return $this->buildQuery($userId)->findOrFail($id);
    }

    public function createFeatureRequest(array $data, int $userId): FeatureRequest
    {
        $feature = new FeatureRequest([
            'title' => $data['title'],
            'description' => $data['description'],
        ]);
        $feature->created_by = $userId;
        $feature->save();

        return $feature->fresh();
    }

    public function vote(int $featureId, string $direction, int $userId): string
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new FeatureRequestException('invalid_vote_direction', 400);
        }

        $feature = FeatureRequest::findOrFail($featureId);

        // We treat a soft-deleted feature as gone for voting purposes.
        if ($feature->trashed()) {
            throw new FeatureRequestException('feature_request_unavailable', 404);
        }

        // Wrap in a transaction so the read-then-write is atomic against
        // concurrent double-clicks. The unique (feature_request_id, user_id)
        // index provides a last-resort safety net.
        $result = DB::transaction(function () use ($feature, $direction, $userId): string {
            $existing = FeatureRequestVote::where('feature_request_id', $feature->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                FeatureRequestVote::create([
                    'feature_request_id' => $feature->id,
                    'user_id' => $userId,
                    'vote' => $direction,
                ]);

                return 'added';
            }

            if ($existing->vote === $direction) {
                $existing->delete();

                return 'removed';
            }

            $existing->update(['vote' => $direction]);

            return 'switched';
        });

        $this->forgetVoteCaches($feature->id);

        return $result;
    }

    public function unvote(int $featureId, int $userId): void
    {
        $feature = FeatureRequest::findOrFail($featureId);

        $deleted = FeatureRequestVote::where('feature_request_id', $feature->id)
            ->where('user_id', $userId)
            ->delete();

        if ($deleted > 0) {
            $this->forgetVoteCaches($feature->id);
        }
    }

    /**
     * Admin: set or clear timeline stamps. Only keys present in `$stamps` are
     * touched. The plan explicitly keeps ordering invariants (coded ≤ tested ≤
     * deployed) as soft advisories surfaced in the UI, not as hard DB/service
     * constraints — admins sometimes need to correct mistakes by stamping
     * out-of-order on purpose.
     */
    public function setTimeline(int $featureId, array $stamps): FeatureRequest
    {
        $allowed = ['coded_at', 'tested_at', 'deployed_at'];
        $updates = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $stamps)) {
                $updates[$col] = $stamps[$col];
            }
        }

        $feature = FeatureRequest::findOrFail($featureId);

        if ($feature->trashed()) {
            throw new FeatureRequestException('feature_request_unavailable', 404);
        }

        if ($updates !== []) {
            $feature->forceFill($updates)->save();
        }

        // Reload with the same eager shape callers of getFeatureRequestById use
        // so the resource renders the same envelope (score, counts, status).
        return $this->getFeatureRequestById($feature->id, null);
    }

    /**
     * Admin: soft-delete with a required reason for the audit trail.
     */
    public function softDelete(int $featureId, string $reason): void
    {
        $feature = FeatureRequest::findOrFail($featureId);

        // Save the reason separately so it's still available on the row after
        // the soft-delete write (doing both in a single save() would still work
        // but this order makes the intent obvious).
        $feature->forceFill(['deletion_reason' => $reason])->save();
        $feature->delete();

        $this->forgetVoteCaches($feature->id);
    }

    /**
     * Admin: restore a soft-deleted feature and clear the deletion_reason.
     * Idempotent — a restore on a live feature is a no-op.
     */
    public function restore(int $featureId): void
    {
        $feature = FeatureRequest::withTrashed()->findOrFail($featureId);

        if (! $feature->trashed()) {
            return;
        }

        $feature->restore();
        $feature->forceFill(['deletion_reason' => null])->save();

        $this->forgetVoteCaches($feature->id);
    }

    /**
     * Bust the up/down count caches for a feature. Called on every write.
     */
    private function forgetVoteCaches(int $featureId): void
    {
        Cache::forget("feature_request_{$featureId}_ups_count");
        Cache::forget("feature_request_{$featureId}_downs_count");
    }

    /**
     * Base query with aggregate counts and — when authenticated — the
     * current user's vote direction pulled in a single round trip.
     * Mirrors PollService::buildPollQuery.
     *
     * @return Builder<FeatureRequest>
     */
    private function buildQuery(?int $userId): Builder
    {
        return FeatureRequest::query()
            ->with('user')
            ->withCount([
                'ups as ups_aggregate_count',
                'downs as downs_aggregate_count',
            ])
            ->when($userId !== null, function (Builder $query) use ($userId): void {
                $query->withExists([
                    'ups as has_upvoted' => fn ($q) => $q->where('user_id', $userId),
                    'downs as has_downvoted' => fn ($q) => $q->where('user_id', $userId),
                ]);
            });
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'idea' => $query
                ->whereNull('coded_at')
                ->whereNull('tested_at')
                ->whereNull('deployed_at'),
            'in_development' => $query
                ->whereNotNull('coded_at')
                ->whereNull('tested_at')
                ->whereNull('deployed_at'),
            'in_testing' => $query
                ->whereNotNull('tested_at')
                ->whereNull('deployed_at'),
            'shipped' => $query->whereNotNull('deployed_at'),
            default => null,
        };
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'shipped' => $query
                ->whereNotNull('deployed_at')
                ->orderByDesc('deployed_at')
                ->orderByDesc('created_at'),
            'popular' => $query
                ->orderByRaw('(ups_aggregate_count - downs_aggregate_count) DESC')
                ->orderByDesc('created_at'),
            // 'newest' and any unknown value
            default => $query->orderByDesc('created_at'),
        };
    }
}
