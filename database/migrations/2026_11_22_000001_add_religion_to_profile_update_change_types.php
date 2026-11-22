<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Existing databases do not re-run the original create-table migration
        // when a new PHP enum case is added. Keep this upgrade's values fixed.
        Schema::table('profile_updates', function (Blueprint $table): void {
            $table->enum('change_type', [
                'basic_data', 'address', 'regular', 'social', 'avatar', 'religion',
            ])->change();
        });
    }

    public function down(): void
    {
        // Additive and backwards-compatible: retain the expanded enum on code
        // rollback so existing religion audits are never truncated or deleted.
    }
};
