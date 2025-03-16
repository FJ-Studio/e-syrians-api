<?php

use App\Enums\ViolationCategoryEnum;
use App\Enums\ViolationStatusEnum;
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
        Schema::create('violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->enum('category', array_column(ViolationCategoryEnum::cases(), 'value'));
            $table->string('target');
            $table->text('description');
            $table->date('date_of_violation');
            $table->text('location');
            $table->string('target_group');
            $table->json('attachments')->nullable();
            $table->json('links')->nullable();
            $table->enum('status', array_column(ViolationStatusEnum::cases(), 'value'))->default('pending');
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
