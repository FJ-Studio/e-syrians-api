<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            // Keep legacy rows for a reversible migration; they have no Expo token
            // and are never eligible for delivery by the new transport.
            $table->string('subscription_id')->nullable()->change();
            $table->uuid('uuid')->nullable()->unique();
            $table->uuid('installation_id')->nullable()->unique();
            $table->text('expo_push_token')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('registration_version')->default(1);
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->string('locale', 3)->default('en');
        });
    }

    public function down(): void
    {
        // subscription_id remains nullable: Expo registrations have no OneSignal id.
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropForeign(['personal_access_token_id']);
            $table->dropUnique(['uuid']);
            $table->dropUnique(['installation_id']);
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['uuid', 'installation_id', 'expo_push_token', 'token_hash',
                'registration_version', 'personal_access_token_id', 'disabled_at', 'locale']);
        });
    }
};
