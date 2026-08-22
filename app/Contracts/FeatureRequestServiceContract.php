<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeInterface;
use App\Models\FeatureRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FeatureRequestServiceContract
{
    /**
     * Paginated list of feature requests.
     *
     * @param  'popular'|'newest'|'shipped'  $sort
     * @param  ?string  $status  one of 'idea', 'in_development', 'in_testing', 'shipped'
     */
    public function getPaginatedFeatureRequests(string $sort, ?string $status, ?int $userId): LengthAwarePaginator;

    /**
     * Single feature request with eager-loaded relations.
     */
    public function getFeatureRequestById(int $id, ?int $userId): FeatureRequest;

    /**
     * Create a new feature request authored by $userId.
     *
     * @param  array{title: string, description: string}  $data
     */
    public function createFeatureRequest(array $data, int $userId): FeatureRequest;

    /**
     * Register a user's vote on a feature request.
     *
     * Semantics (fully toggleable, one active vote):
     *  - No existing vote → insert in the requested direction.
     *  - Existing vote same direction → remove it (toggle off).
     *  - Existing vote opposite direction → switch direction (UPDATE, not a new row).
     *
     * Returns 'added' | 'removed' | 'switched' so the caller can message accordingly.
     *
     * @param  'up'|'down'  $direction
     * @return 'added'|'removed'|'switched'
     */
    public function vote(int $featureId, string $direction, int $userId): string;

    /**
     * Remove a user's vote on a feature request (idempotent — no error if none).
     */
    public function unvote(int $featureId, int $userId): void;

    /**
     * Admin: set or clear timeline stamps on a feature request.
     *
     * Only keys present in `$stamps` are written — passing an empty array is a
     * no-op. Passing `null` for a key clears the stamp. Valid keys:
     * `coded_at`, `tested_at`, `deployed_at`.
     *
     * @param  array<string, string|DateTimeInterface|null>  $stamps
     */
    public function setTimeline(int $featureId, array $stamps): FeatureRequest;

    /**
     * Admin: soft-delete a feature request, recording the reason for audit.
     * Also used to hide spam / off-topic submissions from the public feed.
     */
    public function softDelete(int $featureId, string $reason): void;

    /**
     * Admin: restore a previously soft-deleted feature request and clear the
     * recorded deletion reason. Idempotent — calling restore on an un-deleted
     * feature is a no-op rather than an error.
     */
    public function restore(int $featureId): void;
}
