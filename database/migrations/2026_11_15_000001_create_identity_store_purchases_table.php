<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('identity_store_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_id')->nullable()->constrained('identity_verification_credits')->nullOnDelete();
            $table->string('platform', 8);
            $table->string('environment', 12);
            $table->string('product_id', 100);
            $table->char('proof_hash', 64);
            $table->text('proof');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('check_after')->index();
            $table->timestamps();
            $table->unique(['platform', 'proof_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_store_purchases');
    }
};
