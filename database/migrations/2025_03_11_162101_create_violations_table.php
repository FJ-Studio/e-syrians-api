<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * NOTE: The enum values for `category` and `status` are inlined
     * here as plain string arrays rather than referencing the original
     * `App\Enums\ViolationCategoryEnum` and `App\Enums\ViolationStatusEnum`
     * classes. Those classes were removed when the violations feature
     * was retired (2026-06). Migrations represent historical schema —
     * they should be self-contained and not depend on app classes that
     * may be deleted later. The values here match what the enum cases
     * resolved to when this migration originally ran in production.
     *
     * A follow-up migration drops this table entirely once we decide
     * what (if anything) to do with any existing data in `violations`.
     */
    public function up(): void
    {
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->enum('category', [
                'violence',
                'hate-speech',
                'child-abuse',
                'sexual-abuse',
                'corruption',
                'other',
            ]);
            $table->string('target');
            $table->text('description');
            $table->date('date_of_violation');
            $table->text('location');
            $table->string('target_group');
            $table->json('attachments')->nullable();
            $table->json('links')->nullable();
            $table->enum('status', [
                'pending',
                'published',
                'removed',
            ])->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('violations');
    }
};
