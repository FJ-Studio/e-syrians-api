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
        Schema::table('users', function (Blueprint $table) {
            $table->string('facebook_link')->nullable()->after('record_id');
            $table->string('twitter_link')->nullable()->after('facebook_link');
            $table->string('linkedin_link')->nullable()->after('twitter_link');
            $table->string('github_link')->nullable()->after('linkedin_link');
            $table->string('instagram_link')->nullable()->after('github_link');
            $table->string('snapchat_link')->nullable()->after('instagram_link');
            $table->string('tiktok_link')->nullable()->after('snapchat_link');
            $table->string('youtube_link')->nullable()->after('tiktok_link');
            $table->string('pinterest_link')->nullable()->after('youtube_link');
            $table->string('twitch_link')->nullable()->after('pinterest_link');
            $table->string('website')->nullable()->after('twitch_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
