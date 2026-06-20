<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `recovery_codes_total` — a snapshot of the count of recovery codes
 * the user was issued at generation time.
 *
 * Background: `recovery_codes` is a JSON array of UNUSED codes; consumed
 * codes are spliced out (see `TwoFactorService::verifyAndConsumeRecoveryCode`).
 * That makes it impossible to derive "used N of M" from the column alone.
 * The mobile + web UIs want to show "7 of 8 remaining" so the user can
 * see how depleted their set is.
 *
 * Backfill: existing rows with a non-empty `recovery_codes` array get
 * `recovery_codes_total = json_length(recovery_codes)`. This assumes none
 * of the codes have been consumed yet for those users; if some had been,
 * the displayed total will be slightly low until they regenerate. Since
 * recovery codes are rarely used in practice (they're the "I lost my
 * phone" fallback) this skew is acceptable for a one-shot migration.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('recovery_codes_total')
                ->nullable()
                ->after('recovery_codes');
        });

        // Backfill — count the JSON array length on existing rows. Loop in
        // PHP rather than rely on DB-specific JSON functions (MySQL has
        // JSON_LENGTH, Postgres has jsonb_array_length, SQLite needs the
        // JSON1 extension) so the migration runs identically across the
        // dev / test / staging / prod combinations the project supports.
        DB::table('users')
            ->whereNotNull('recovery_codes')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $codes = is_string($user->recovery_codes)
                        ? json_decode($user->recovery_codes, true)
                        : $user->recovery_codes;
                    if (!is_array($codes) || $codes === []) {
                        continue;
                    }
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['recovery_codes_total' => count($codes)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('recovery_codes_total');
        });
    }
};
