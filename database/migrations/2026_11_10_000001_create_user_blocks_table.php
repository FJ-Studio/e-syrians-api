<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['blocker_id', 'blocked_id']);
            $table->index(['blocked_id', 'blocker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
    }
};
