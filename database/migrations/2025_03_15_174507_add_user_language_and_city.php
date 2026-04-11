<?php

use App\Enums\SysLanguageEnum;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('language', array_column(SysLanguageEnum::cases(), 'value'))->default('ar')->after('avatar');
            $table->string('city_inside_syria')->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('language');
            $table->dropColumn('city_inside_syria');
        });
    }
};
