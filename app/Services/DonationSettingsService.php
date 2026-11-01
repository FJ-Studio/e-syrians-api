<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Pennant\Feature;
use App\Enums\PlatformFeature;
use App\Models\DonationSetting;
use Illuminate\Support\Facades\DB;

class DonationSettingsService
{
    public const string CRYPTO_FEATURE = 'crypto-donations';

    // This is a site-wide switch, never a flag scoped to the current user.
    public const string FEATURE_SCOPE = 'global';

    public function cryptoEnabled(): bool
    {
        return resolve(FeatureFlagService::class)->enabled(PlatformFeature::CryptoDonations);
    }

    /**
     * @return list<array{name: string, currency: string, network: ?string, address: string}>
     */
    public function wallets(): array
    {
        return DonationSetting::query()->find(DonationSetting::SINGLETON_ID)->wallets ?? [];
    }

    /**
     * @param list<array{name: string, currency: string, network: ?string, address: string}> $wallets
     */
    public function save(bool $cryptoEnabled, array $wallets): void
    {
        DB::transaction(function () use ($cryptoEnabled, $wallets): void {
            DonationSetting::query()->updateOrCreate(
                ['id' => DonationSetting::SINGLETON_ID],
                ['wallets' => $wallets],
            );

            Feature::for(self::FEATURE_SCOPE)->activate(self::CRYPTO_FEATURE, $cryptoEnabled);
        });
    }
}
