<?php

declare(strict_types=1);

use Laravel\Pennant\Feature;
use App\Enums\PlatformFeature;
use App\Services\FeatureFlagService;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Pennant imports legacy defaults only for missing global records. Never
        // overwrite an existing administrator choice, including crypto donations.
        Feature::for(FeatureFlagService::SCOPE)->load(array_column(PlatformFeature::cases(), 'value'));
        Feature::flushCache();
    }

    public function down(): void
    {
        // Preserve administrator choices during rollback; no user data or flag state is deleted.
    }
};
