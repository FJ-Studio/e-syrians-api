<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Create the `audience_entries` table — one row per identifier
     * inside an audience. An identifier is either an email address
     * or a Syrian national ID (5–20 digits); the two live side by
     * side because a real-world list ("my extended family") mixes
     * both — some contacts you have an email for, others only an
     * ID.
     *
     * PII handling — mirrors the pattern on `users` (national_id
     * column with `encrypted` cast + separate `national_id_hashed`
     * lookup column):
     *   - `identifier` uses Laravel's `encrypted` cast on the
     *     model. Stored ciphertext, decrypted on read. Column is
     *     `text` (not string) so the encrypted payload doesn't
     *     hit MySQL's 191/255 byte limits on long email addresses.
     *   - `identifier_hashed` is SHA-256 (via StrService::hash) of
     *     the plaintext identifier, populated by the model's
     *     boot() event. Enables fast (equality) lookups without
     *     ever decrypting — used by the poll vote-check join.
     *   - Unique index on (audience_id, identifier_hashed) dedupes
     *     duplicate paste-ins on the same audience while allowing
     *     the same identifier to appear across different audiences
     *     owned by the same user (or across users).
     *
     * `identifier_type` is a plain string enum handled at the
     * Laravel level via `AudienceEntryTypeEnum` casting. Avoids
     * MySQL-level ENUM columns which are painful to migrate.
     *
     * `resolved_user_id` is a cached resolution result — populated
     * on entry-add and refreshed on demand via the manual
     * "Refresh resolution" button. Nullable because most entries
     * won't correspond to a registered user at add-time. FK uses
     * nullOnDelete so a soft-deleted user leaves the resolution
     * hanging (it'll be nulled by the FK constraint automatically
     * on hard delete). We deliberately DO NOT re-resolve on new
     * user signup — that's a v2 concern (background job) if the
     * manual refresh proves too clunky.
     *
     * No soft deletes on entries — an "undo" for a removed entry
     * doesn't make sense; the user can just paste it back in. The
     * audience-level soft-delete + cascade covers accidental
     * whole-list loss.
     */
    public function up(): void
    {
        Schema::create('audience_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audience_id')
                ->constrained('audiences')
                ->cascadeOnDelete();

            // See PII handling block above. `identifier` holds the
            // ciphertext at rest; the model's `encrypted` cast
            // handles the round-trip. `identifier_hashed` is the
            // fast-lookup column populated on save.
            $table->text('identifier');
            $table->string('identifier_hashed', 64);
            $table->string('identifier_type', 16); // AudienceEntryTypeEnum

            $table->foreignId('resolved_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Dedup guard within an audience.
            $table->unique(['audience_id', 'identifier_hashed']);

            // Vote-check path: given a user's email_hashed /
            // national_id_hashed / phone_hashed, find every audience
            // that includes them. Also feeds the future
            // "background resolve on signup" job.
            $table->index('identifier_hashed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_entries');
    }
};
