<?php

use App\Enums\WeaponDeliveryStatus;
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
        Schema::create('weapon_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('citizen_id')->nullable();
            $table->unsignedBigInteger('weapon_delivery_point_id')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->json('updates')->nullable();
            $table->enum('status', WeaponDeliveryStatus::getValues())->default('new');
            $table->json('deliveries')->nullable();
            $table->timestamps();
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weapon_deliveries');
    }
};
