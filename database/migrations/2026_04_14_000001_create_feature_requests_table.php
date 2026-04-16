<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('description');
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('coded_at')->nullable();
            $table->timestampTz('tested_at')->nullable();
            $table->timestampTz('deployed_at')->nullable();
            $table->text('deletion_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('created_by');
            $table->index(['deployed_at', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
