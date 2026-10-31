<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Add `audience_id` to `polls` — nullable FK letting a poll
     * delegate its allow-list to a reusable audience owned by the
     * poll creator, instead of pasting the same raw identifiers
     * every time.
     *
     * Semantics: LIVE reference.
     *   - Vote check joins `audience_entries` on every vote attempt
     *     (see PollService audience-membership helper).
     *   - Editing the audience while a poll is in its voting window
     *     changes who can vote immediately. That behaviour is
     *     recorded in `audience_audits` for moderation.
     *   - `nullOnDelete()` fires only on HARD delete of the parent
     *     `audiences` row (a cascade path Laravel almost never
     *     invokes today because audiences use soft-deletes). Soft
     *     deletion of an audience leaves `polls.audience_id`
     *     pointing at the trashed row and the vote-check helper
     *     is expected to treat a trashed audience as empty (no
     *     one can vote through it). AudienceService::softDelete
     *     is responsible for gating: if any ACTIVE poll references
     *     the audience, refuse the soft-delete and return an error
     *     asking the user to detach first. Enforcement lives in
     *     the service, not the schema, because the "active poll"
     *     window is a temporal check that a FK can't express.
     *
     * Mutual exclusion with demographic rules in
     * `poll_audience_rules` is enforced in StorePollRequest /
     * UpdatePollRequest — at most one branch per poll. That
     * validation lives at the request layer, not the schema, because
     * the choice is behavioural (which branch PollService reads) not
     * structural.
     *
     * Nullable + nullOnDelete rather than a hard constraint so we
     * can drop the column later without a data migration if the
     * feature is ever rolled back.
     */
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table): void {
            // No `after(...)` — the historical `audience` json
            // column was dropped in
            // 2026_04_13_000004_drop_audience_from_polls_table.php,
            // so referencing it here would produce invalid SQL.
            // MySQL appends the new column at the end of the table
            // when no position is given, which is fine for us —
            // callers use it by name, not by ordinal.
            $table->foreignId('audience_id')
                ->nullable()
                ->constrained('audiences')
                ->nullOnDelete();

            // Reverse-lookup path for AudienceService::detectActivePolls
            // ("which polls reference this audience?"). Without this
            // index, every audience edit would trigger a full-table
            // scan on polls.
            $table->index('audience_id');
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table): void {
            $table->dropForeign(['audience_id']);
            $table->dropIndex(['audience_id']);
            $table->dropColumn('audience_id');
        });
    }
};
