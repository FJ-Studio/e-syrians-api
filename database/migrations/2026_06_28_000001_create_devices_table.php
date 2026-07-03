<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Create the `devices` table — registry of OneSignal push
     * subscriptions per user.
     *
     * Mirrors the contract in the mobile spec at
     * `e-syrians-mobile/docs/push-notifications-backend-spec.md`:
     *   - `subscription_id` is the OneSignal per-device id (modern
     *     OneSignal term; older docs call it "player id"). UNIQUE
     *     because a single push subscription should never belong
     *     to two users simultaneously — if the same device signs
     *     in as a different user, the existing row is reassigned
     *     (see `DeviceService::registerDevice`).
     *   - `platform` is 'ios' | 'android' — used at send time when
     *     the OneSignal payload needs platform-specific fields
     *     (rich-media wrapper, sound, etc.).
     *   - `model` is optional (e.g. "iPhone 15 Pro") — surfaced
     *     when the user lists their active devices.
     *   - `last_seen_at` is intentionally separate from
     *     `updated_at` so an idempotent re-register from the same
     *     device doesn't appear as a "changed" row in audit logs.
     *
     * No soft deletes — the table is small (≤ ~5 rows per user)
     * and rows are cheap to recreate when the user re-installs.
     * Hard delete on logout / DELETE endpoint hit / cascade on
     * user delete.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('subscription_id')->unique();
            $table->enum('platform', ['ios', 'android']);
            $table->string('model')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Lookup pattern in `User::routeNotificationForOneSignal`:
            // `where user_id = ?` → pluck subscription_id. Indexed
            // for that path even though small per-user volumes mean
            // the index gain is modest — the read happens on every
            // notification send.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
