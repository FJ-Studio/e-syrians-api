<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('identity_sandbox_credits', function (Blueprint $table): void {
            $table->string('source')->default('self_claim');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('grant_reason', 240)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('identity_sandbox_credits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by_user_id');
            $table->dropColumn(['source', 'grant_reason']);
        });
    }
};
