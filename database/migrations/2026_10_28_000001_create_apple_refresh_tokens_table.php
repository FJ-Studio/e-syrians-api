<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('apple_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Native App ID and web Services ID have separate grants.
            $table->string('client_id');
            $table->char('token_hash', 64);
            $table->text('refresh_token');
            $table->timestamps();
            $table->unique(['user_id', 'client_id', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_refresh_tokens');
    }
};
