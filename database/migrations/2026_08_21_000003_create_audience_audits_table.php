<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Create the `audience_audits` table — event log for the case
     * where an audience is edited while it's already in use by an
     * active poll.
     *
     * Why we log this:
     *   Audiences are LIVE references from polls (`polls.audience_id`),
     *   not snapshots. That's an intentional design — edits made to
     *   a list DURING a poll's voting window take effect immediately
     *   for the next vote check. That's powerful (you can add a
     *   friend mid-poll) but also risky (you can retroactively lock
     *   someone out). We record every such edit so we can answer
     *   "who changed this audience while poll #42 was running?"
     *   during moderation or dispute resolution.
     *
     * Emission rule (in AudienceService::update / addEntries /
     * removeEntries):
     *   IF the audience is referenced by at least one poll whose
     *   NOW() falls between start_date and end_date AND that poll
     *   isn't soft-deleted → write one AudienceAudit row per edit
     *   call, capturing the diff counts + affected poll IDs.
     *
     * v1 is log-only — no UI surface for editors. The Filament
     * AudienceAuditResource (Phase D) makes the log queryable by
     * admins.
     *
     * `affected_poll_ids` is stored as a JSON array (not a
     * separate `audience_audit_polls` pivot) because:
     *   - The row is a snapshot at emit time. It doesn't need to
     *     follow poll renames or deletes.
     *   - We never join FROM this table into polls; we only display
     *     the list on the audit detail view. JSON is enough.
     *
     * No soft-deletes, no updates — an audit row is immutable once
     * written. Retention: keep indefinitely; audit trails are cheap.
     */
    public function up(): void
    {
        Schema::create('audience_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audience_id')
                ->constrained('audiences')
                ->cascadeOnDelete();
            $table->foreignId('edited_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // JSON array of poll IDs that were in their voting
            // window at the time of the edit. See emission rule
            // above. Cast as array on the model.
            $table->json('affected_poll_ids');

            // Diff counts — not the actual identifiers, because
            // logging the raw list at each edit would balloon the
            // table and duplicate PII. The current state is on
            // `audience_entries`; deltas are enough for "who
            // changed how much when".
            $table->unsignedInteger('entries_added_count')->default(0);
            $table->unsignedInteger('entries_removed_count')->default(0);

            $table->timestamps();

            // Common admin queries: "audits for this audience"
            // (chronological) and "recent audits by this editor".
            $table->index(['audience_id', 'created_at']);
            $table->index(['edited_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_audits');
    }
};
