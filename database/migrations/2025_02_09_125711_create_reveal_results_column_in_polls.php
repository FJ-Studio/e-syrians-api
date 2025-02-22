<?php

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
        Schema::table('polls', function (Blueprint $table) {
            $table->enum('reveal_results', array_map(fn($case) => $case->value, \App\Enums\RevealResultsEnum::cases()))
                ->default('before-voting')
                ->after('audience_can_add_options');
            $table->boolean('voters_are_visible')->default(false)->after('reveal_results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
