<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('poll_audience_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 50);
            $table->string('value', 255);
            $table->timestampsTz();

            $table->index(['poll_id', 'criterion']);
            $table->unique(['poll_id', 'criterion', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_audience_rules');
    }
};
