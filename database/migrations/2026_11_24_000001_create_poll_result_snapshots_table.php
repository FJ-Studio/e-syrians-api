<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('poll_result_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('question');
            $table->dateTimeTz('start_date');
            $table->dateTimeTz('end_date');
            $table->unsignedTinyInteger('max_selections');
            $table->unsignedBigInteger('total_votes');
            $table->unsignedBigInteger('unique_voters_count');
            // Aggregate option results only. Never voter IDs, names or profiles.
            $table->json('options');
            $table->dateTimeTz('closed_at');
            $table->dateTimeTz('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_result_snapshots');
    }
};
