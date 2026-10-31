<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Two-stage account-deletion timestamps.
 *
 * When a user requests deletion we set both columns; the mobile app and
 * web dashboard read the pair to render the "pending deletion" banner
 * and the `EnsureAccountNotPendingDeletion` middleware uses them to
 * lock every route except cancel-deletion / deletion-status / logout.
 *
 * `HardDeleteExpiredAccountsJob` (scheduled daily) queries by
 * `deletion_scheduled_for <= now()`, so it must be indexed. We index
 * `deletion_requested_at` too — the admin panel and support scripts
 * filter on it when investigating cancelled requests.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable()->after('recovery_codes_total');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_requested_at');

            $table->index('deletion_requested_at');
            $table->index('deletion_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_requested_at']);
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });
    }
};
