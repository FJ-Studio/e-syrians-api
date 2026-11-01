<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Device;
use Livewire\Livewire;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use App\Jobs\SendExpoPushJob;
use App\Models\ProfileUpdate;
use App\Models\SecurityEvent;
use App\Enums\PlatformFeature;
use Filament\Facades\Filament;
use App\Services\BigQueryService;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\DB;
use App\Jobs\LogPollVoteToBigQuery;
use Illuminate\Support\Facades\Bus;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\Http;
use App\Services\SecurityEventService;
use App\Jobs\ProcessExpoPushReceiptJob;
use App\Jobs\LogProfileChangeToBigQuery;
use Illuminate\Support\Facades\Schedule;
use App\Services\DonationSettingsService;
use App\Channels\Messages\ExpoPushMessage;
use App\Filament\Pages\ManageFeatureFlags;
use App\Jobs\FanOutPlatformNotificationJob;
use App\Jobs\SyncPollAudienceRulesToBigQuery;
use Illuminate\Validation\ValidationException;

function featureFlagDevice(User $user): Device
{
    return Device::create(['uuid' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
        'user_id' => $user->id, 'personal_access_token_id' => $user->createToken('push-device')->accessToken->id,
        'expo_push_token' => 'ExpoPushToken[feature_flag_test_token]', 'registration_version' => 1, 'platform' => 'ios']);
}

function featureFlagsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);
    return $admin;
}

function initializeFeatureFlags(): void
{
    $migration = require database_path('migrations/2026_11_01_000002_initialize_platform_feature_flags.php');
    $migration->up();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('imports legacy values only for missing flags and never overwrites administrator choices', function (): void {
    $features = array_column(PlatformFeature::cases(), 'value');
    DB::table('features')->where('scope', FeatureFlagService::SCOPE)->whereIn('name', $features)->delete();
    Feature::flushCache();
    config(['feature-flags.initial_states' => ['notification-events' => false, 'expo-push' => false, 'bigquery-export' => true]]);
    initializeFeatureFlags();
    $flags = resolve(FeatureFlagService::class);
    expect($flags->enabled(PlatformFeature::NotificationEvents))->toBeFalse()
        ->and($flags->enabled(PlatformFeature::ExpoPush))->toBeFalse()
        ->and($flags->enabled(PlatformFeature::BigQueryExport))->toBeTrue();
    setPlatformFeature(PlatformFeature::ExpoPush);
    setPlatformFeature(PlatformFeature::CryptoDonations);
    config(['feature-flags.initial_states' => ['notification-events' => true, 'expo-push' => false, 'bigquery-export' => false]]);
    initializeFeatureFlags();
    expect($flags->enabled(PlatformFeature::NotificationEvents))->toBeFalse()
        ->and($flags->enabled(PlatformFeature::ExpoPush))->toBeTrue()
        ->and($flags->enabled(PlatformFeature::BigQueryExport))->toBeTrue()
        ->and($flags->enabled(PlatformFeature::CryptoDonations))->toBeTrue();
});

it('uses one global flag regardless of the current user or their scoped flags', function (): void {
    $user = User::factory()->create();
    setPlatformFeature(PlatformFeature::ExpoPush);
    Feature::for($user)->deactivate(PlatformFeature::ExpoPush->value);
    $this->actingAs($user);
    Feature::flushCache();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeTrue();
});

it('can upgrade using the previous release’s cached configuration without losing its initial values', function (): void {
    DB::table('features')->where('scope', FeatureFlagService::SCOPE)
        ->whereIn('name', [PlatformFeature::NotificationEvents->value, PlatformFeature::ExpoPush->value])->delete();
    Feature::flushCache();
    config(['feature-flags.initial_states' => [], 'services.notification_events_enabled' => false,
        'services.expo_push.enabled' => true]);
    initializeFeatureFlags();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeTrue()
        ->and(resolve(FeatureFlagService::class)->enabled(PlatformFeature::NotificationEvents))->toBeFalse();
    config(['services.expo_push.enabled' => false]);
    Feature::flushCache();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeTrue();
});

it('defaults push and analytics to off without opening the feature page enabling either', function (): void {
    featureFlagsAdmin();
    $flags = resolve(FeatureFlagService::class);
    Livewire::test(ManageFeatureFlags::class)->assertSuccessful();
    expect($flags->enabled(PlatformFeature::ExpoPush))->toBeFalse()
        ->and($flags->enabled(PlatformFeature::BigQueryExport))->toBeFalse();
});

it('persists first-resolution defaults once instead of following later environment changes', function (): void {
    DB::table('features')->where('scope', FeatureFlagService::SCOPE)->where('name', PlatformFeature::ExpoPush->value)->delete();
    Feature::flushCache();
    config(['feature-flags.initial_states.expo-push' => false]);
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeFalse();
    config(['feature-flags.initial_states.expo-push' => true]);
    Feature::flushCache();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeFalse();
    setPlatformFeature(PlatformFeature::ExpoPush);
    config(['feature-flags.initial_states.expo-push' => false]);
    Feature::flushCache();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeTrue();
});

it('requires admin access and does not expose server credentials', function (): void {
    $this->get(ManageFeatureFlags::getUrl())->assertRedirect();
    $this->actingAs(User::factory()->create())->get(ManageFeatureFlags::getUrl())->assertForbidden();
    featureFlagsAdmin();
    config(['services.expo_push.access_token' => 'PRIVATE-EXPO-TEST-TOKEN',
        'services.bigquery.credentials' => 'PRIVATE-BQ-TEST-CREDENTIALS']);
    Livewire::test(ManageFeatureFlags::class)->assertSuccessful()
        ->assertDontSee('PRIVATE-EXPO-TEST-TOKEN')->assertDontSee('PRIVATE-BQ-TEST-CREDENTIALS')
        ->assertDontSee('APP_DEBUG')->assertDontSee('RECAPTCHA_MIN_SCORE');
});

it('allows confirmed Filament push activation even when the old environment default is false', function (): void {
    featureFlagsAdmin();
    config(['feature-flags.initial_states.expo-push' => false]);
    $flags = resolve(FeatureFlagService::class);
    $states = $flags->states();
    $states['expo-push'] = true;
    $page = Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])
        ->mountAction('save')->assertActionMounted('save');
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeFalse();
    $page->callMountedAction()->assertHasNoFormErrors()->assertNotified('Feature flags saved');
    Feature::flushCache();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeTrue();
});

it('applies each Filament switch to runtime independently of legacy environment defaults', function (PlatformFeature $feature): void {
    featureFlagsAdmin();
    // Simulate a configured integration without contacting Expo or BigQuery.
    // Missing-integration safeguards are exercised separately below.
    $flags = $this->partialMock(FeatureFlagService::class, function ($mock): void {
        $mock->shouldReceive('unavailableReason')->andReturnNull();
    });
    setPlatformFeature($feature, false);
    $runtimeEnabled = fn (): bool => match ($feature) {
        PlatformFeature::ExpoPush => resolve(ExpoPushService::class)->isEnabled(),
        PlatformFeature::BigQueryExport => resolve(BigQueryService::class)->isEnabled(),
        default => $flags->enabled($feature),
    };

    foreach ([true, false] as $enabled) {
        config(['feature-flags.initial_states.'.$feature->value => ! $enabled]);
        $states = $flags->states();
        $states[$feature->value] = $enabled;
        Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])
            ->callAction('save')->assertHasNoFormErrors()->assertNotified('Feature flags saved');
        Feature::flushCache();
        expect($runtimeEnabled())->toBe($enabled);
        Livewire::test(ManageFeatureFlags::class)->assertFormSet(['flags.'.$feature->value => $enabled]);
    }
})->with([PlatformFeature::ExpoPush, PlatformFeature::NotificationEvents, PlatformFeature::BigQueryExport]);

it('rechecks authorization before any Livewire mutation', function (): void {
    $admin = featureFlagsAdmin();
    $states = resolve(FeatureFlagService::class)->states();
    $states['expo-push'] = true;
    $page = Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states]);
    $admin->removeRole('admin');
    $page->call('save')->assertForbidden();
    expect(resolve(ExpoPushService::class)->isEnabled())->toBeFalse();
});

it('rejects stale admin saves instead of silently overwriting another tab’s feature changes', function (): void {
    featureFlagsAdmin();
    $flags = resolve(FeatureFlagService::class);
    $states = $flags->states();
    $first = Livewire::test(ManageFeatureFlags::class);
    $stale = Livewire::test(ManageFeatureFlags::class);
    $states['expo-push'] = true;
    $first->fillForm(['flags' => $states])->call('save')->assertHasNoFormErrors();
    $stale->call('save')->assertHasFormErrors(['flags']);
    expect($flags->enabled(PlatformFeature::ExpoPush))->toBeTrue();
});

it('rejects arbitrary security flags incomplete payloads and invalid booleans atomically', function (string $invalid): void {
    $flags = resolve(FeatureFlagService::class);
    $states = $flags->states();
    $states['expo-push'] = true;
    match ($invalid) {
        'unknown' => $states['APP_DEBUG'] = true,
        'missing' => $states = array_diff_key($states, ['notification-events' => true]),
        'nonboolean' => $states['notification-events'] = 'false',
    };
    expect(fn () => $flags->save($states))->toThrow(ValidationException::class);
    Feature::flushCache();
    expect($flags->enabled(PlatformFeature::ExpoPush))->toBeFalse();
})->with(['unknown', 'missing', 'nonboolean']);

it('blocks crypto activation without wallets and shares the existing flag after setup', function (): void {
    featureFlagsAdmin();
    $flags = resolve(FeatureFlagService::class);
    $states = $flags->states();
    $states['crypto-donations'] = true;
    Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])->call('save')
        ->assertHasFormErrors(['flags.crypto-donations']);
    expect($flags->enabled(PlatformFeature::CryptoDonations))->toBeFalse();
    resolve(DonationSettingsService::class)->save(false, [
        ['name' => 'Bitcoin', 'currency' => 'BTC', 'network' => null, 'address' => 'bc1qfeatureflagtest'],
    ]);
    Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])->callAction('save')
        ->assertHasNoFormErrors();
    Feature::flushCache();
    expect(resolve(DonationSettingsService::class)->cryptoEnabled())->toBeTrue();
});

it('does not newly enable BigQuery without its package or project configuration and always allows disabling', function (): void {
    featureFlagsAdmin();
    config(['services.bigquery.project_id' => null]);
    $flags = resolve(FeatureFlagService::class);
    expect($flags->unavailableReason(PlatformFeature::BigQueryExport))->not->toBeNull();
    $states = $flags->states();
    $states['bigquery-export'] = true;
    Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])->call('save')
        ->assertHasFormErrors(['flags.bigquery-export']);
    expect($flags->enabled(PlatformFeature::BigQueryExport))->toBeFalse();
    setPlatformFeature(PlatformFeature::BigQueryExport);
    $states['bigquery-export'] = false;
    Livewire::test(ManageFeatureFlags::class)->fillForm(['flags' => $states])->callAction('save')
        ->assertHasNoFormErrors();
    expect($flags->enabled(PlatformFeature::BigQueryExport))->toBeFalse();
});

it('refreshes the push master flag before sending a queued message', function (bool $wasEnabled, bool $nowEnabled): void {
    Bus::fake();
    Http::fake(['*/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'feature-flag-receipt']])]);
    $user = User::factory()->create();
    $device = featureFlagDevice($user);
    setPlatformFeature(PlatformFeature::ExpoPush, $wasEnabled);
    $push = resolve(ExpoPushService::class);
    expect($push->isEnabled())->toBe($wasEnabled);
    DB::table('features')->where('name', PlatformFeature::ExpoPush->value)->where('scope', FeatureFlagService::SCOPE)
        ->update(['value' => json_encode($nowEnabled)]);
    (new SendExpoPushJob($device->id, $user->id, 1, 'master-flag', new ExpoPushMessage(
        ['type' => 'verification'],
        event: 'verification_received',
    )))->handle($push);
    Http::assertSentCount($nowEnabled ? 1 : 0);
})->with([[true, false], [false, true]]);

it('refreshes the push master flag before receipt processing or resends', function (): void {
    Bus::fake();
    Http::fake();
    $user = User::factory()->create();
    $device = featureFlagDevice($user);
    setPlatformFeature(PlatformFeature::ExpoPush);
    $push = resolve(ExpoPushService::class);
    expect($push->isEnabled())->toBeTrue();
    DB::table('features')->where('name', PlatformFeature::ExpoPush->value)->where('scope', FeatureFlagService::SCOPE)->update(['value' => 'false']);
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 1, 'master-flag', new ExpoPushMessage()))->handle($push);
    Http::assertNothingSent();
    Bus::assertNotDispatched(SendExpoPushJob::class);
});

it('controls new automatic alert generation without deleting security audit history', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    setPlatformFeature(PlatformFeature::NotificationEvents, false);
    config(['feature-flags.initial_states.notification-events' => true]);
    $record = resolve(SecurityEventService::class)->record($user, 'security_password_changed');
    expect($record->exists)->toBeTrue();
    $user->forceFill(['verified_at' => now()])->save();
    Bus::assertNotDispatched(FanOutPlatformNotificationJob::class);
    setPlatformFeature(PlatformFeature::NotificationEvents);
    resolve(SecurityEventService::class)->record($user, 'security_password_changed');
    Bus::assertDispatchedTimes(FanOutPlatformNotificationJob::class, 1);
    expect(SecurityEvent::where('user_id', $user->id)->count())->toBe(2);
});

it('uses the Pennant flag for the scheduled poll sweep', function (bool $enabled): void {
    Bus::fake();
    $owner = User::factory()->create();
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Flag scheduler test',
        'start_date' => now()->subHour(), 'end_date' => now()->addDay(), 'max_selections' => 1,
        'audience_can_add_options' => false, 'is_private' => false]);
    setPlatformFeature(PlatformFeature::NotificationEvents, $enabled);
    config(['feature-flags.initial_states.notification-events' => ! $enabled]);
    require base_path('routes/console.php');
    $event = collect(Schedule::events())->first(fn ($event) => $event->description === 'dispatch-open-poll-invitations');
    expect($event)->not->toBeNull();
    $event->run(app());
    if ($enabled) {
        Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->subjectId === $poll->id);
    } else {
        Bus::assertNotDispatched(FanOutPlatformNotificationJob::class);
    }
})->with([false, true]);

it('refreshes BigQuery export flags at each queued job boundary', function (string $jobType): void {
    $flags = $this->partialMock(FeatureFlagService::class, function ($mock): void {
        $mock->shouldReceive('unavailableReason')->andReturnNull();
    });
    $bigQuery = $this->partialMock(BigQueryService::class, function ($mock): void {
        $mock->shouldNotReceive('insert');
        $mock->shouldNotReceive('insertBatch');
    });
    setPlatformFeature(PlatformFeature::BigQueryExport);
    expect($flags->enabled(PlatformFeature::BigQueryExport))->toBeTrue();
    DB::table('features')->where('name', PlatformFeature::BigQueryExport->value)->where('scope', FeatureFlagService::SCOPE)->update(['value' => 'false']);
    $job = match ($jobType) {
        'profile' => new LogProfileChangeToBigQuery(new ProfileUpdate()),
        'vote' => new LogPollVoteToBigQuery(1, 1, [1], null, null),
        'audience' => new SyncPollAudienceRulesToBigQuery(1),
    };
    $job->handle($bigQuery);
    expect($flags->enabled(PlatformFeature::BigQueryExport))->toBeFalse();
})->with(['profile', 'vote', 'audience']);
