<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('city_inside_syria', 'province');
        });

        DB::table('poll_audience_rules')
            ->where('criterion', 'city_inside_syria')
            ->update(['criterion' => 'province']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('province', 'city_inside_syria');
        });

        DB::table('poll_audience_rules')
            ->where('criterion', 'province')
            ->update(['criterion' => 'city_inside_syria']);
    }
};
