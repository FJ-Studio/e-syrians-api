<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('feature_request_votes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('feature_request_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('vote', ['up', 'down']);
            $table->timestampsTz();

            $table->foreign('feature_request_id')
                ->references('id')->on('feature_requests')
                ->cascadeOnDelete();

            // One active vote per (feature, user). Switching direction is an
            // UPDATE on this row, not an INSERT — the service layer relies on
            // this uniqueness to make toggling race-free.
            $table->unique(['feature_request_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_request_votes');
    }
};
