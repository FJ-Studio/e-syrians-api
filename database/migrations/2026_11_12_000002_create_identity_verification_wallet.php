<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('identity_verification_credits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('grant_reason', 240)->nullable();
            $table->string('source');
            $table->string('status')->default('available');
            $table->timestamp('gifted_at')->nullable();
            $table->uuid('attempt_uuid')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->uuid('provider_session_id')->nullable()->unique();
            $table->text('provider_url')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status'], 'idv_owner_status');
        });

        Schema::create('identity_verification_gift_codes', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
        });

        Schema::create('identity_verification_credit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_id')->nullable()->constrained('identity_verification_credits', indexName: 'idv_events_credit_fk')->nullOnDelete();
            $table->string('event', 32);
            $table->timestamp('occurred_at');
            $table->unique(['credit_id', 'event'], 'idv_event_once');
            $table->index(['user_id', 'occurred_at', 'id'], 'idv_user_timeline');
        });

        Schema::create('identity_verification_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_id')->nullable()->constrained('identity_verification_credits', indexName: 'idv_orders_credit_fk')->nullOnDelete();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent')->nullable()->unique();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('locale', 2);
            $table->boolean('livemode');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verification_orders');
        Schema::dropIfExists('identity_verification_credit_events');
        Schema::dropIfExists('identity_verification_gift_codes');
        Schema::dropIfExists('identity_verification_credits');
    }
};
