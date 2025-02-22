<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->dateTimeTz('start_date')->default(now());
            $table->dateTimeTz('end_date');
            $table->json('audience')->nullable();
            $table->unsignedTinyInteger('max_selections')->default(1);
            $table->boolean('audience_can_add_options')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->text('deletion_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
