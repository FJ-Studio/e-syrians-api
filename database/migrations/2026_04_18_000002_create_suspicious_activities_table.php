<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suspicious_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->enum('severity', ['low', 'medium', 'high']);
            $table->unsignedInteger('score');
            $table->json('rules_triggered');
            $table->json('evidence')->nullable();
            $table->enum('status', ['pending', 'reviewed', 'dismissed', 'confirmed'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('detected_at');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspicious_activities');
    }
};
