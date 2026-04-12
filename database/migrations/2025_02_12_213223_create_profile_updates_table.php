<?php

use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('profile_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('change_type', array_map(fn ($case) => $case->value, ProfileChangeTypeEnum::cases()));
            $table->json('meta_data')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_updates');
    }
};
