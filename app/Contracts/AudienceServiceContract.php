<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Models\Audience;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AudienceServiceContract
{
    /**
     * Paginated list of the user's own audiences with aggregate
     * entry + resolution counts eager-loaded.
     *
     * @return LengthAwarePaginator<int, Audience>
     */
    public function list(int $userId, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param array{name: string, description?: ?string} $attributes
     * @param array<int, string> $rawEntries  Free-text identifiers (emails or 5-20 digit national IDs)
     */
    public function create(int $userId, array $attributes, array $rawEntries): Audience;

    /**
     * Return the audience with entries + resolution flags. 404s
     * for any UUID not owned by $userId.
     */
    public function show(string $uuid, int $userId): Audience;

    /**
     * Rename / re-describe. Does NOT touch entries. Emits an
     * AudienceAudit row iff the audience is referenced by an
     * active poll (metadata changes count — the poll creator
     * changed something the voter might have seen indirectly).
     *
     * @param array{name?: string, description?: ?string} $attributes
     */
    public function update(string $uuid, int $userId, array $attributes): Audience;

    /**
     * Bulk-add entries. Dedupes against the audience's existing
     * entries via `identifier_hashed`. Resolves each new entry to
     * a user_id on write. Emits an AudienceAudit row iff any
     * entries were actually added AND the audience is referenced
     * by an active poll.
     *
     * @param array<int, string> $rawEntries
     */
    public function addEntries(string $uuid, int $userId, array $rawEntries): Audience;

    /**
     * Remove a single entry. Emits an AudienceAudit row iff the
     * audience is referenced by an active poll.
     */
    public function removeEntry(string $uuid, int $userId, int $entryId): void;

    /**
     * Manually re-check every entry against the users table and
     * refresh `resolved_user_id`. User-triggered from the audience
     * detail page. Doesn't emit an AudienceAudit row — the
     * resolution state isn't voter-facing, so refreshing it
     * doesn't count as a "who can vote?" change.
     */
    public function refreshResolution(string $uuid, int $userId): Audience;

    /**
     * Soft-delete the audience. Refuses with an AudienceException
     * (`audience_referenced_by_active_poll`) if any poll in its
     * voting window still points at it — otherwise callers would
     * accidentally break live polls.
     */
    public function softDelete(string $uuid, int $userId): void;

    /**
     * Vote-check helper: is this user in the given audience?
     * Matches the user's hashed email against email entries and
     * hashed national_id against national_id entries — never
     * cross-type, and never against phone (phones aren't an
     * accepted entry shape). Returns false if the audience is
     * trashed or if the user has neither identifier populated.
     */
    public function isUserInAudience(int $audienceId, User $user): bool;

    /**
     * Resolve an audience UUID → internal id, scoped to the caller.
     * Returns null when the UUID doesn't exist OR belongs to
     * another user (deliberately opaque so a caller can't probe).
     *
     * The poll create/update flow accepts `audience_uuid` in the
     * payload because that's what the audience list API surfaces;
     * internal foreign keys stay on numeric id.
     */
    public function resolveOwnedUuidToId(string $uuid, int $userId): ?int;
}
