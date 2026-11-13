<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('identity_verification_orders', function (Blueprint $table): void {
            $table->string('stripe_price_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_verification_orders', function (Blueprint $table): void {
            $table->dropColumn('stripe_price_id');
        });
    }
};
