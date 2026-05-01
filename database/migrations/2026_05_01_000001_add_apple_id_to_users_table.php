<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Stable Apple `sub` claim from the identity token. Indexed so we
            // can look users up by their Apple id without scanning the whole
            // table (Apple doesn't always send the email on subsequent logins).
            $table->string('apple_id')->nullable()->after('google_id');
            $table->index('apple_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
