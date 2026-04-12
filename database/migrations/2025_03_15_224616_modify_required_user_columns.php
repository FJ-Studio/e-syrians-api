<?php

use App\Enums\GenderEnum;
use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
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
            $table->string('birth_date')
                ->nullable()
                ->change();
            $table->enum('gender', array_map(fn ($case) => $case->value, GenderEnum::cases()))
                ->nullable()
                ->change();
            $table->enum('hometown', array_map(fn ($case) => $case->value, HometownEnum::cases()))
                ->nullable()
                ->change();
            $table->enum('country', array_map(fn ($case) => $case->value, CountryEnum::cases()))
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('birth_date')->nullable(false)->change();

            $table->enum('gender', array_map(fn ($case) => $case->value, GenderEnum::cases()))
                ->nullable(false)
                ->change();

            $table->enum('hometown', array_map(fn ($case) => $case->value, HometownEnum::cases()))
                ->nullable(false)
                ->change();

            $table->enum('country', array_map(fn ($case) => $case->value, CountryEnum::cases()))
                ->nullable(false)
                ->change();
        });
    }
};
