<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profile_updates', function (Blueprint $table) {
            $table->json('changes')->nullable()->after('meta_data');
            $table->string('request_source', 20)->nullable()->after('user_agent');
            $table->string('session_id')->nullable()->after('request_source');
            $table->boolean('blocked')->default(false)->after('session_id');
            $table->string('block_reason')->nullable()->after('blocked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profile_updates', function (Blueprint $table) {
            $table->dropColumn(['changes', 'request_source', 'session_id', 'blocked', 'block_reason']);
        });
    }
};
