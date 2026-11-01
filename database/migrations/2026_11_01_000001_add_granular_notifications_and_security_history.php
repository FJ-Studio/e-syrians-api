<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->json('notification_preferences')->nullable());
        Schema::table('polls', fn (Blueprint $table) => $table->timestamp('closure_notified_at')->nullable());
        DB::table('polls')->where('end_date', '<=', now())->update(['closure_notified_at' => now()]);
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('in_app')->default(false);
            $table->boolean('push')->default(false);
            $table->timestamp('created_at');
        });
        // Deduplication must survive inbox deletion and work for push-only deliveries.
        DB::table('notification_deliveries')->insertUsing(
            ['id', 'user_id', 'created_at'],
            DB::table('notifications')->join('users', 'users.id', '=', 'notifications.notifiable_id')
                ->where('notifications.notifiable_type', 'App\\Models\\User')
                ->select('notifications.id', 'users.id')->selectRaw('COALESCE(notifications.created_at, CURRENT_TIMESTAMP)')
        );
        DB::table('notification_deliveries')->update(['in_app' => true]);
        Schema::create('security_known_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('installation_hash', 64);
            $table->timestamps();
            $table->unique(['user_id', 'installation_hash']);
        });
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->text('device_info')->nullable();
            $table->text('ip_address')->nullable();
            $table->boolean('new_device')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_known_devices');
        Schema::dropIfExists('notification_deliveries');
        Schema::table('polls', fn (Blueprint $table) => $table->dropColumn('closure_notified_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
    }
};
