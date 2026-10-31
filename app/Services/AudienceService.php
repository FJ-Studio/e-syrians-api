<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\User;
use App\Models\Audience;
use App\Models\AudienceAudit;
use App\Models\AudienceEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\AudienceEntryTypeEnum;
use Illuminate\Support\Facades\Date;
use App\Exceptions\AudienceException;
use Illuminate\Database\Eloquent\Builder;
use App\Contracts\AudienceServiceContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AudienceService implements AudienceServiceContract
{
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Cap on entries per single add / create call. Protects
     * against a runaway paste (imagine 100k emails) while keeping
     * normal audience imports practical.
     */
    private const MAX_ENTRIES_PER_CALL = 5000;

    public function list(int $userId, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Audience::query()
            ->where('user_id', $userId)
            ->withCount([
                'entries as entries_total_count',
                'entries as entries_resolved_count' => function (Builder $q): void {
                    $q->whereNotNull('resolved_user_id');
                },
            ])
            ->latest('updated_at')
            ->paginate($perPage);
    }

    public function create(int $userId, array $attributes, array $rawEntries): Audience
    {
        return DB::transaction(function () use ($userId, $attributes, $rawEntries): Audience {
            $audience = new Audience([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
            ]);
            $audience->user_id = $userId;
            $audience->save();

            if ($rawEntries !== []) {
                $this->insertEntries($audience, $rawEntries);
            }

            return $audience->fresh(['entries.resolvedUser']);
        });
    }

    public function show(string $uuid, int $userId): Audience
    {
        return $this->findOwnedOrFail($uuid, $userId)
            ->load(['entries' => fn ($q) => $q->orderBy('id')]);
    }

    public function update(string $uuid, int $userId, array $attributes): Audience
    {
        return DB::transaction(function () use ($uuid, $userId, $attributes): Audience {
            $audience = $this->findOwnedOrFail($uuid, $userId);

            // Distinguish "field absent from PATCH" (leave alone)
            // from "field present with null value" (clear).
            // The previous implementation used array_filter which
            // treated both cases the same and made it impossible to
            // erase an existing description.
            // `name` is required-non-null in UpdateAudienceRequest, so
            // presence alone is enough — no null guard needed.
            if (array_key_exists('name', $attributes)) {
                $audience->name = (string) $attributes['name'];
            }
            if (array_key_exists('description', $attributes)) {
                $audience->description = $attributes['description'] === null
                    ? null
                    : (string) $attributes['description'];
            }

            // Only audit if something actually changed.
            $isDirty = $audience->isDirty();
            $audience->save();

            if ($isDirty) {
                $this->maybeEmitAudit($audience, $userId, added: 0, removed: 0);
            }

            return $audience->fresh();
        });
    }

    public function addEntries(string $uuid, int $userId, array $rawEntries): Audience
    {
        if (count($rawEntries) > self::MAX_ENTRIES_PER_CALL) {
            throw new AudienceException('audience_too_many_entries_in_one_call', 422);
        }

        return DB::transaction(function () use ($uuid, $userId, $rawEntries): Audience {
            $audience = $this->findOwnedOrFail($uuid, $userId);
            $added = $this->insertEntries($audience, $rawEntries);

            if ($added > 0) {
                $this->maybeEmitAudit($audience, $userId, added: $added, removed: 0);
            }

            return $audience->fresh(['entries.resolvedUser']);
        });
    }

    public function removeEntry(string $uuid, int $userId, int $entryId): void
    {
        DB::transaction(function () use ($uuid, $userId, $entryId): void {
            $audience = $this->findOwnedOrFail($uuid, $userId);

            /** @var AudienceEntry|null $entry */
            $entry = $audience->entries()->whereKey($entryId)->first();

            if ($entry === null) {
                throw new AudienceException('audience_entry_not_found', 404);
            }

            $entry->delete();

            $this->maybeEmitAudit($audience, $userId, added: 0, removed: 1);
        });
    }

    public function removeEntries(string $uuid, int $userId, array $entryIds): int
    {
        // Cap per-call size to the same MAX_ENTRIES_PER_CALL used
        // by the add path — this endpoint is throttled at the same
        // rate and the bulk-write shape is symmetric.
        if (count($entryIds) > self::MAX_ENTRIES_PER_CALL) {
            throw new AudienceException('audience_too_many_entries_in_one_call', 422);
        }

        return DB::transaction(function () use ($uuid, $userId, $entryIds): int {
            $audience = $this->findOwnedOrFail($uuid, $userId);

            if ($entryIds === []) {
                return 0;
            }

            // Scope the delete to this audience via the relation so
            // no caller can drive-by delete entries that belong to
            // another audience by guessing ids. Unknown / already-
            // deleted ids are silently ignored — the count returned
            // to the client tells them how many actually vanished.
            $deleted = $audience->entries()
                ->whereIn('id', $entryIds)
                ->delete();

            if ($deleted > 0) {
                // ONE audit row per bulk call — the semantic
                // operation is a single user action, not N.
                $this->maybeEmitAudit($audience, $userId, added: 0, removed: (int) $deleted);
            }

            return (int) $deleted;
        });
    }

    public function refreshResolution(string $uuid, int $userId): Audience
    {
        return DB::transaction(function () use ($uuid, $userId): Audience {
            $audience = $this->findOwnedOrFail($uuid, $userId);

            // `entries()->get()` returns an Eloquent\Collection. The
            // resolver signature narrows to Support\Collection so
            // PHPStan sees a consistent generic parameterisation;
            // `toBase()` gives us the parent-class collection with
            // the same items.
            $entries = $audience->entries()->get()->toBase();
            if ($entries->isEmpty()) {
                return $audience;
            }

            $this->resolveEntries($entries);

            // No audit emission — resolution state is a cache
            // over an eventually-consistent view of the users
            // table; it doesn't change who was intended to be in
            // the audience, only who currently exists.

            return $audience->fresh(['entries.resolvedUser']);
        });
    }

    public function softDelete(string $uuid, int $userId): void
    {
        DB::transaction(function () use ($uuid, $userId): void {
            $audience = $this->findOwnedOrFail($uuid, $userId);

            $activePollIds = $this->detectActivePolls($audience);
            if ($activePollIds !== []) {
                throw new AudienceException(
                    'audience_referenced_by_active_poll',
                    409,
                    ['active_poll_ids' => $activePollIds],
                );
            }

            $audience->delete();
        });
    }

    public function isUserInAudience(int $audienceId, User $user): bool
    {
        // Trashed audiences are treated as empty — no one can
        // vote through a soft-deleted list. The service refuses
        // to soft-delete an audience that's referenced by an
        // active poll, so under normal flow this branch never
        // fires; it's a safety net for edge cases (e.g. someone
        // patches the DB directly).
        $audience = Audience::whereKey($audienceId)->first();
        if ($audience === null) {
            return false;
        }

        // Match each hash ONLY against entries of the corresponding
        // identifier_type. Two invariants depend on this:
        //   1. Phone numbers are never accepted as entry inputs
        //      (see AudienceEntryTypeEnum). Comparing a user's
        //      phone hash to any entry would be an out-of-band
        //      match a poll creator never authorised.
        //   2. Without the type filter, a national-ID entry whose
        //      hash coincidentally equals a user's email hash
        //      (astronomically unlikely with the current hash
        //      algorithm, but non-zero) would count as a match.
        //      Scoping the comparison per column removes the
        //      ambiguity entirely.
        // Neither identifier populated → nothing to match against.
        // Bail before building the query so an empty inner closure
        // can't accidentally match every entry in the audience.
        if (! $user->email_hashed && ! $user->national_id_hashed) {
            return false;
        }

        return AudienceEntry::query()
            ->where('audience_id', $audienceId)
            ->where(function ($q) use ($user): void {
                if ($user->email_hashed) {
                    $q->orWhere(function ($sub) use ($user): void {
                        $sub->where('identifier_type', AudienceEntryTypeEnum::Email->value)
                            ->where('identifier_hashed', $user->email_hashed);
                    });
                }
                if ($user->national_id_hashed) {
                    $q->orWhere(function ($sub) use ($user): void {
                        $sub->where('identifier_type', AudienceEntryTypeEnum::NationalId->value)
                            ->where('identifier_hashed', $user->national_id_hashed);
                    });
                }
            })
            ->exists();
    }

    public function resolveOwnedUuidToId(string $uuid, int $userId): ?int
    {
        // Explicit column projection so we don't hydrate the whole
        // Audience row just to grab an id — this runs on every poll
        // create/update.
        $id = Audience::query()
            ->where('uuid', $uuid)
            ->where('user_id', $userId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    // ─────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────

    /**
     * Look up an audience by uuid AND owner. 404s if either check
     * fails — deliberately opaque so a caller can't probe which
     * uuids exist.
     */
    private function findOwnedOrFail(string $uuid, int $userId): Audience
    {
        /** @var Audience|null $audience */
        $audience = Audience::query()
            ->where('uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        if ($audience === null) {
            throw new AudienceException('audience_not_found', 404);
        }

        return $audience;
    }

    /**
     * Normalise, validate, dedupe, insert. Returns the number of
     * NEW rows written (already-present identifiers are skipped
     * silently — the paste-in UX expects it).
     *
     * @param array<int, string> $rawEntries
     */
    private function insertEntries(Audience $audience, array $rawEntries): int
    {
        // Normalise + classify.
        //
        // WHY a keyed dedupe map with a type-prefixed key instead of
        // a bare array: PHP silently casts numeric-string array keys
        // to int. That means a national ID like `"12345678901"`
        // becomes a real integer key, and later feeding it to
        // `StrService::hash()` (which requires string) blows up with
        // a TypeError — turning a legitimate audience creation into
        // a 500. Prefixing with the type value keeps the key a
        // guaranteed string AND doubles as intra-batch dedupe that
        // won't collide across types (email "12345678901" vs national
        // ID "12345678901", if that ever happens).
        $normalised = [];
        foreach ($rawEntries as $raw) {
            $trimmed = trim((string) $raw);
            if ($trimmed === '') {
                continue;
            }
            $type = AudienceEntryTypeEnum::detect($trimmed);
            if ($type === null) {
                // FormRequest should have caught this, but service
                // is also a public boundary — reject rather than
                // silently store a garbage row.
                throw new AudienceException(
                    'audience_entry_invalid_format',
                    422,
                    ['identifier' => $trimmed],
                );
            }
            // Emails go to lowercase so `foo@x.com` and `Foo@X.com`
            // dedupe against each other. National IDs are already
            // digits-only.
            $identifier = $type === AudienceEntryTypeEnum::Email
                ? mb_strtolower($trimmed)
                : $trimmed;
            $normalised[$type->value.':'.$identifier] = [
                'identifier' => $identifier,
                'type' => $type,
            ];
        }
        if ($normalised === []) {
            return 0;
        }

        // Dedupe against what's already in the audience.
        $existingHashes = $audience->entries()
            ->pluck('identifier_hashed')
            ->all();

        $toInsert = [];
        foreach ($normalised as $row) {
            $hash = StrService::hash($row['identifier']);
            if (in_array($hash, $existingHashes, true)) {
                continue;
            }
            $toInsert[] = [
                'identifier' => $row['identifier'],
                'identifier_type' => $row['type'],
                'audience_id' => $audience->id,
            ];
        }

        if ($toInsert === []) {
            return 0;
        }

        // Insert one by one via the model so the encrypted cast
        // and the boot() hash-sync fire. `insert()` would bypass
        // both. Volume is bounded by MAX_ENTRIES_PER_CALL.
        $inserted = collect();
        foreach ($toInsert as $row) {
            $entry = $audience->entries()->create([
                'identifier' => $row['identifier'],
                'identifier_type' => $row['identifier_type'],
            ]);
            $inserted->push($entry);
        }

        // Resolve fresh entries against the users table.
        $this->resolveEntries($inserted);

        return $inserted->count();
    }

    /**
     * Populate resolved_user_id on the given collection of
     * entries. Batched: one SELECT per identifier_type, matching
     * on the corresponding *_hashed column on users.
     *
     * Accepts a `Support\Collection` OR an `Eloquent\Collection`
     * (the latter is what `$audience->entries()->get()` returns).
     * PHPStan needs the union spelled out because the two aren't
     * generic-covariant on their element type.
     *
     * @param Collection<int, AudienceEntry> $entries
     */
    private function resolveEntries(Collection $entries): void
    {
        [$emailEntries, $nationalIdEntries] = $entries->partition(
            fn (AudienceEntry $e) => $e->identifier_type === AudienceEntryTypeEnum::Email,
        );

        $this->resolveEntriesByColumn($emailEntries, 'email_hashed');
        $this->resolveEntriesByColumn($nationalIdEntries, 'national_id_hashed');
    }

    /**
     * @param Collection<int, AudienceEntry> $entries
     */
    private function resolveEntriesByColumn(Collection $entries, string $userColumn): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $hashes = $entries->pluck('identifier_hashed')->all();
        $matches = User::query()
            ->whereIn($userColumn, $hashes)
            ->pluck('id', $userColumn)
            ->all();

        foreach ($entries as $entry) {
            $newResolvedId = $matches[$entry->identifier_hashed] ?? null;
            if ($entry->resolved_user_id !== $newResolvedId) {
                $entry->resolved_user_id = $newResolvedId;
                $entry->save();
            }
        }
    }

    /**
     * Emit an AudienceAudit row iff the audience is referenced by
     * at least one poll currently inside its voting window.
     */
    private function maybeEmitAudit(Audience $audience, int $editedByUserId, int $added, int $removed): void
    {
        $activePollIds = $this->detectActivePolls($audience);
        if ($activePollIds === []) {
            return;
        }

        AudienceAudit::create([
            'audience_id' => $audience->id,
            'edited_by_user_id' => $editedByUserId,
            'affected_poll_ids' => $activePollIds,
            'entries_added_count' => $added,
            'entries_removed_count' => $removed,
        ]);
    }

    /**
     * Return IDs of polls that reference this audience AND are
     * currently inside [start_date, end_date] AND not trashed.
     *
     * NOTE: private polls must still count as "active" for audit
     * purposes — the Poll model applies a `public_polls` global
     * scope that filters `is_private = false`, so we bypass it
     * here. Otherwise a modification to an audience used by a
     * private poll would silently skip both the audit row and
     * the softDelete guard, breaking the invariants for the whole
     * private-poll surface.
     *
     * @return array<int, int>
     */
    private function detectActivePolls(Audience $audience): array
    {
        $now = Date::now();

        return Poll::query()
            ->withoutGlobalScope('public_polls')
            ->where('audience_id', $audience->id)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->pluck('id')
            ->all();
    }

}
