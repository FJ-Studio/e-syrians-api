<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('identity_sandbox_credits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issued_to_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('gifted_at')->nullable();
            $table->string('status')->default('available');
            $table->uuid('attempt_uuid')->nullable();
            $table->uuid('provider_session_id')->nullable()->unique();
            $table->text('provider_url')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });

        Schema::create('identity_sandbox_gift_requests', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_sandbox_gift_requests');
        Schema::dropIfExists('identity_sandbox_credits');
    }
};
