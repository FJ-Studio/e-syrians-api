<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * suspicious_activities.user_id was originally created as a plain
 * unsignedBigInteger with an index but no FK, so account deletion left
 * orphan rows pointing at nonexistent user ids. Two product-shape
 * options were on the table:
 *
 *   (a) hard-delete the rows alongside the user
 *   (b) retain for moderation history and null the pointer
 *
 * We take (b) — moderation/audit history should outlive an account
 * deletion so the same person can't wipe their trail by re-registering.
 * Adding `ON DELETE SET NULL` lets the User row's `forceDelete()` sweep
 * through without leaving orphans while keeping the fraud-detection
 * record intact for the review pipeline. `AccountDeletionService::
 * hardDeleteAccount()` has a matching code comment explaining the
 * retention decision.
 */
return new class () extends Migration {
    public function up(): void
    {
        // 1. Widen the column to NULL first so the orphan-cleanup UPDATE
        //    below can set stale rows to NULL, and so the FK's SET NULL
        //    clause is valid.
        Schema::table('suspicious_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // 2. Cleanup: null out any orphans BEFORE attaching the FK.
        //    Without this step the FK creation blows up on any prod DB
        //    that accumulated dangling user_ids while the column had no
        //    referential integrity. Portable subquery form works on
        //    MySQL and PostgreSQL — both are supported here.
        DB::statement(
            'UPDATE suspicious_activities '
            .'SET user_id = NULL '
            .'WHERE user_id IS NOT NULL '
            .'  AND user_id NOT IN (SELECT id FROM users)'
        );

        // 3. Attach the FK now that the data is clean.
        Schema::table('suspicious_activities', function (Blueprint $table): void {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Down is intentionally a partial rollback — we drop the FK
        // (safe, reversible) but leave the column nullable. Flipping it
        // back to NOT NULL would fail on any row this migration's up
        // path (or subsequent user deletions) nulled out, and we don't
        // have a defensible way to invent replacement user_ids. A DBA
        // running this down migration should decide the retention
        // policy for the null'd rows manually.
        Schema::table('suspicious_activities', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }
};
