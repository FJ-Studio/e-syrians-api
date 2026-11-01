<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Pennant\Feature;
use App\Enums\PlatformFeature;
use Illuminate\Support\Facades\DB;
use Google\Cloud\BigQuery\BigQueryClient;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeatureFlagService
{
    public const string SCOPE = 'global';

    public static function defineFeatures(): void
    {
        foreach (PlatformFeature::cases() as $feature) {
            Feature::define($feature->value, static fn (): bool => self::initialState($feature));
        }
    }

    private static function initialState(PlatformFeature $feature): bool
    {
        // Also support an upgrade while the previous release's config cache still exists.
        // This resolver runs only for a missing Pennant record, never for saved flags.
        $oldConfig = match ($feature) {
            PlatformFeature::NotificationEvents => 'services.notification_events_enabled',
            PlatformFeature::ExpoPush => 'services.expo_push.enabled',
            PlatformFeature::BigQueryExport => 'services.bigquery.enabled',
            PlatformFeature::CryptoDonations => null,
        };
        $fallback = $oldConfig ? config($oldConfig, $feature->defaultEnabled()) : $feature->defaultEnabled();
        return filter_var(config('feature-flags.initial_states.'.$feature->value, $fallback), FILTER_VALIDATE_BOOLEAN);
    }

    public function enabled(PlatformFeature $feature): bool
    {
        return Feature::for(self::SCOPE)->value($feature->value) === true;
    }

    /** @return array<string, bool> */
    public function states(): array
    {
        Feature::for(self::SCOPE)->loadMissing(array_column(PlatformFeature::cases(), 'value'));
        $states = [];
        foreach (PlatformFeature::cases() as $feature) {
            $states[$feature->value] = $this->enabled($feature);
        }
        return $states;
    }

    public function unavailableReason(PlatformFeature $feature): ?string
    {
        if ($feature === PlatformFeature::BigQueryExport) {
            if (! class_exists(BigQueryClient::class)) {
                return 'The BigQuery client package is not installed on this server.';
            }
            if (! config('services.bigquery.project_id') || ! config('services.bigquery.dataset')) {
                return 'The BigQuery project and dataset must be configured on the server first.';
            }
        }
        if ($feature === PlatformFeature::CryptoDonations && resolve(DonationSettingsService::class)->wallets() === []) {
            return 'Configure at least one receiving wallet on the Donations page first.';
        }
        return null;
    }

    /**
     * @param array<string, bool> $states
     * @param array<string, bool>|null $expectedStates
     */
    public function save(array $states, ?array $expectedStates = null): void
    {
        $rules = ['flags' => ['required', 'array:'.implode(',', array_column(PlatformFeature::cases(), 'value'))]];
        foreach (PlatformFeature::cases() as $feature) {
            $rules['flags.'.$feature->value] = ['required', 'boolean'];
        }
        Validator::make(['flags' => $states], $rules)->validate();

        // Initialize any missing records before acquiring the row locks.
        Feature::flushCache();
        $this->states();
        try {
            DB::transaction(function () use ($states, $expectedStates): void {
                DB::table('features')->where('scope', self::SCOPE)
                    ->whereIn('name', array_column(PlatformFeature::cases(), 'value'))->lockForUpdate()->get();
                Feature::flushCache();
                $current = $this->states();
                if ($expectedStates !== null && $current !== $expectedStates) {
                    throw ValidationException::withMessages(['data.flags' => ['Feature flags changed in another session. Reload this page before saving.']]);
                }
                foreach (PlatformFeature::cases() as $feature) {
                    if ($states[$feature->value] && ! $current[$feature->value] && ($reason = $this->unavailableReason($feature))) {
                        throw ValidationException::withMessages(['data.flags.'.$feature->value => [$reason]]);
                    }
                }
                foreach (PlatformFeature::cases() as $feature) {
                    Feature::for(self::SCOPE)->activate($feature->value, (bool) $states[$feature->value]);
                }
            });
        } finally {
            Feature::flushCache();
        }
    }
}
