<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('donation_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('wallets');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_settings');
    }
};
