<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('marketing_notifications_opt_in')->default(false);
            $table->timestamp('marketing_notifications_consented_at')->nullable();
        });
        Schema::table('polls', function (Blueprint $table): void {
            $table->timestamp('invitations_dispatched_at')->nullable();
        });
        // Do not unexpectedly invite the entire user base to pre-rollout polls.
        DB::table('polls')->update(['invitations_dispatched_at' => now()]);
        Schema::create('notification_announcements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_announcements');
        Schema::table('polls', fn (Blueprint $table) => $table->dropColumn('invitations_dispatched_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['marketing_notifications_opt_in', 'marketing_notifications_consented_at']));
    }
};
