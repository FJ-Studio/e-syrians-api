<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Create the `audiences` table — user-owned, reusable lists of
     * identifiers (emails + national IDs) that a poll creator can
     * attach to a poll instead of pasting the same raw list every
     * time.
     *
     * Design notes:
     *   - Owned by a single user (`user_id`). No cross-user sharing
     *     in v1 — the row is private to its creator. Any lookup
     *     from a controller must filter by `user_id`.
     *   - Route-bound by `uuid` (not `id`) so the URL isn't a
     *     guessable enumeration of the entire audiences table.
     *   - Soft-deleted, so an accidental delete can be recovered
     *     without losing the resolution cache on the entries side.
     *   - `name` is scoped per-user (not globally unique) so two
     *     users can each have a "Friends" audience without
     *     collision.
     *
     * Related tables (separate migrations):
     *   - `audience_entries` — one row per identifier, with the
     *     encrypted value + hashed lookup column + resolved user id.
     *   - `audience_audits` — event log emitted when an audience is
     *     edited while any active poll still references it.
     *   - `polls.audience_id` — nullable FK letting a poll delegate
     *     its allow-list to an audience (live reference; vote check
     *     joins on entries).
     */
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Every list surface (`GET /users/audiences`) filters by
            // owner. Indexed for that path even though per-user
            // volumes are expected to be small — the join with
            // entries for aggregate counts benefits from a covered
            // scan when the user has many audiences.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiences');
    }
};
