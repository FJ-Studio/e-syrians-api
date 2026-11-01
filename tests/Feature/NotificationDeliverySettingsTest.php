<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Device;
use Livewire\Livewire;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use App\Jobs\SendExpoPushJob;
use App\Models\SecurityEvent;
use Filament\Facades\Filament;
use App\Enums\NotificationEvent;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use App\Services\SecurityEventService;
use App\Jobs\ProcessExpoPushReceiptJob;
use App\Channels\Messages\ExpoPushMessage;
use App\Jobs\FanOutPlatformNotificationJob;
use App\Notifications\PlatformNotification;
use App\Services\PlatformNotificationService;
use Illuminate\Validation\ValidationException;
use App\Services\NotificationPreferenceService;
use App\Filament\Pages\ManageNotificationDelivery;
use App\Services\NotificationDeliverySettingsService;

function notificationPolicyDevice(User $user): Device
{
    return Device::create([
        'uuid' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
        'user_id' => $user->id, 'personal_access_token_id' => $user->createToken('push-device')->accessToken->id,
        'expo_push_token' => 'ExpoPushToken[policy_test_token]', 'registration_version' => 1, 'platform' => 'ios',
    ]);
}

function setNotificationPolicy(NotificationEvent $event, string $channel, bool $enabled): void
{
    Feature::for(NotificationDeliverySettingsService::FEATURE_SCOPE)->activate(
        NotificationDeliverySettingsService::featureName($event, $channel),
        $enabled,
    );
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('defines a global persistent channel flag for every supported event without enabling the Expo transport', function (): void {
    $settings = resolve(NotificationDeliverySettingsService::class);
    $matrix = $settings->matrix();
    expect($matrix)->toHaveCount(16)->and(config('services.expo_push.enabled'))->toBeFalse();
    foreach (NotificationEvent::cases() as $event) {
        expect($matrix[$event->value])->toBe(['in_app' => true, 'push' => true])
            ->and(NotificationCategory::forEvent($event->value))->toBe($event->category());
    }

    setNotificationPolicy(NotificationEvent::AccountVerified, 'push', false);
    Feature::flushCache();
    $this->actingAs(User::factory()->create());
    Feature::for(auth()->user())->activate(NotificationDeliverySettingsService::featureName(NotificationEvent::AccountVerified, 'push'));
    expect($settings->allows('account_verified', 'push'))->toBeFalse()
        ->and($settings->allows('account_verified', 'in_app'))->toBeTrue()
        ->and($settings->allows('account_unverified', 'push'))->toBeTrue();
});

it('rejects unknown events channels and nonboolean stored flag values', function (): void {
    $settings = resolve(NotificationDeliverySettingsService::class);
    Feature::for(NotificationDeliverySettingsService::FEATURE_SCOPE)->activate(
        NotificationDeliverySettingsService::featureName(NotificationEvent::AccountVerified, 'push'),
        'false',
    );
    expect($settings->allows('account_verified', 'push'))->toBeFalse()
        ->and($settings->allows('account_verified', 'email'))->toBeFalse()
        ->and($settings->allows('security_unknown', 'push'))->toBeFalse()
        ->and($settings->allows(null, 'push'))->toBeFalse();
});

it('applies independent event channel flags to delivery and keeps sibling events unchanged', function (NotificationEvent $event, bool $inApp, bool $push): void {
    Bus::fake();
    $user = User::factory()->create();
    notificationPolicyDevice($user);
    if ($event === NotificationEvent::Announcement) {
        $preferences = resolve(NotificationPreferenceService::class);
        foreach (['in_app', 'push'] as $channel) {
            $preferences->update($user, NotificationCategory::Announcements, $channel, true);
        }
    }
    setNotificationPolicy($event, 'in_app', $inApp);
    setNotificationPolicy($event, 'push', $push);

    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification($event->value, '1'), 'policy');
    expect($user->notifications()->count())->toBe($inApp ? 1 : 0);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, $push ? 1 : 0);
    $delivery = DB::table('notification_deliveries')->first();
    expect((bool) $delivery->in_app)->toBe($inApp)->and((bool) $delivery->push)->toBe($push);
    foreach (NotificationEvent::cases() as $other) {
        if ($other !== $event) {
            expect(resolve(NotificationDeliverySettingsService::class)->allows($other->value, 'push'))->toBeTrue();
        }
    }
})->with(NotificationEvent::cases())->with([[false, true], [true, false], [false, false], [true, true]]);

it('cannot override user opt-out even when all admin flags are enabled', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    notificationPolicyDevice($user);
    $preferences = resolve(NotificationPreferenceService::class);
    foreach (['in_app', 'push'] as $channel) {
        $preferences->update($user, NotificationCategory::AccountStatus, $channel, false);
    }
    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification('account_verified', '1'), 'muted');
    expect($user->notifications()->count())->toBe(0);
    Bus::assertNotDispatched(SendExpoPushJob::class);
});

it('does not replay suppressed events after flags are re-enabled but delivers new events', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    notificationPolicyDevice($user);
    $service = resolve(PlatformNotificationService::class);
    foreach (['in_app', 'push'] as $channel) {
        setNotificationPolicy(NotificationEvent::AccountVerified, $channel, false);
    }
    $service->send($user, new PlatformNotification('account_verified', '1'), 'old');
    foreach (['in_app', 'push'] as $channel) {
        setNotificationPolicy(NotificationEvent::AccountVerified, $channel, true);
    }
    $service->send($user, new PlatformNotification('account_verified', '1'), 'old');
    expect($user->notifications()->count())->toBe(0);
    Bus::assertNotDispatched(SendExpoPushJob::class);
    $service->send($user, new PlatformNotification('account_verified', '1'), 'new');
    expect($user->notifications()->count())->toBe(1);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, 1);
});

it('rechecks the exact event flag when a previously queued push executes using fresh database state', function (): void {
    Bus::fake();
    Http::fake();
    config(['services.expo_push.enabled' => true]);
    $user = User::factory()->create();
    notificationPolicyDevice($user);
    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification('account_verified', '1'), 'queued');
    $job = Bus::dispatched(SendExpoPushJob::class)->first();
    expect($job->message->event)->toBe('account_verified')
        ->and($job->message->payload('token')['data'])->not->toHaveKey('event');

    // Simulate an admin in a different process; the current Pennant cache still says true.
    DB::table('features')->where('name', NotificationDeliverySettingsService::featureName(NotificationEvent::AccountVerified, 'push'))
        ->update(['value' => 'false']);
    expect(resolve(NotificationDeliverySettingsService::class)->allows('account_verified', 'push'))->toBeTrue();
    $job->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
    expect($user->notifications()->count())->toBe(1);
});

it('does not block a queued push because a different event in the same category was muted', function (): void {
    Bus::fake();
    config(['services.expo_push.enabled' => true]);
    Http::fake(['*/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'policy-receipt']])]);
    $user = User::factory()->create();
    notificationPolicyDevice($user);
    setNotificationPolicy(NotificationEvent::AccountUnverified, 'push', false);
    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification('account_verified', '1'), 'queued');
    Bus::dispatched(SendExpoPushJob::class)->first()->handle(resolve(ExpoPushService::class));
    Http::assertSentCount(1);
});

it('keeps the Expo environment kill switch authoritative even with every admin flag enabled', function (): void {
    Http::fake();
    $user = User::factory()->create();
    $device = notificationPolicyDevice($user);
    (new SendExpoPushJob($device->id, $user->id, 1, 'disabled-transport', new ExpoPushMessage(
        ['type' => 'verification'],
        event: 'verification_received',
    )))->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
});

it('applies an updated flag to receipt-triggered resends too', function (): void {
    Bus::fake();
    config(['services.expo_push.enabled' => true]);
    Http::fake(['*/getReceipts' => Http::response(['data' => ['receipt' => [
        'status' => 'error', 'details' => ['error' => 'MessageRateExceeded'],
    ]]])]);
    $user = User::factory()->create();
    $device = notificationPolicyDevice($user);
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 1, 'resend', new ExpoPushMessage(
        ['type' => 'verification'],
        event: 'verification_received',
    )))->handle(resolve(ExpoPushService::class));
    $job = Bus::dispatched(SendExpoPushJob::class)->first();
    expect($job->message->event)->toBe('verification_received');
    setNotificationPolicy(NotificationEvent::VerificationReceived, 'push', false);
    $job->handle(resolve(ExpoPushService::class));
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/send'));
});

it('fails closed for legacy or unknown push events instead of bypassing policy', function (?string $event): void {
    config(['services.expo_push.enabled' => true]);
    Http::fake();
    $user = User::factory()->create();
    $device = notificationPolicyDevice($user);
    (new SendExpoPushJob($device->id, $user->id, 1, 'unknown-event', new ExpoPushMessage(event: $event)))
        ->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
})->with([null, 'security_unknown']);

it('handles pre-policy serialized messages with an uninitialized event property safely', function (): void {
    config(['services.expo_push.enabled' => true]);
    Http::fake();
    $user = User::factory()->create();
    $device = notificationPolicyDevice($user);
    // The new promoted property does not exist in messages already serialized before deployment.
    $legacy = new ReflectionClass(ExpoPushMessage::class)->newInstanceWithoutConstructor();
    (new SendExpoPushJob($device->id, $user->id, 1, 'legacy', $legacy))->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
});

it('retains security audit history when both delivery flags are off', function (): void {
    Bus::fake();
    foreach (['in_app', 'push'] as $channel) {
        setNotificationPolicy(NotificationEvent::SecurityPasswordChanged, $channel, false);
    }
    $user = User::factory()->create();
    $record = resolve(SecurityEventService::class)->record($user, 'security_password_changed');
    (new FanOutPlatformNotificationJob($record->event, $record->id))->handle(resolve(PlatformNotificationService::class));
    expect(SecurityEvent::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(0);
    Bus::assertNotDispatched(SendExpoPushJob::class);
});

it('consumes a poll invitation fanout while disabled without replaying it after enabling', function (): void {
    Bus::fake();
    $owner = User::factory()->create(['verified_at' => now()]);
    $recipient = User::factory()->create(['verified_at' => now()]);
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Policy test',
        'start_date' => now()->subHour(), 'end_date' => now()->addDay(), 'max_selections' => 1,
        'audience_can_add_options' => false, 'is_private' => false]);
    foreach (['in_app', 'push'] as $channel) {
        setNotificationPolicy(NotificationEvent::PollOpened, $channel, false);
    }
    $job = new FanOutPlatformNotificationJob('poll_opened', $poll->id);
    $job->handle(resolve(PlatformNotificationService::class));
    expect($poll->fresh()->invitations_dispatched_at)->not->toBeNull();
    foreach (['in_app', 'push'] as $channel) {
        setNotificationPolicy(NotificationEvent::PollOpened, $channel, true);
    }
    $job->handle(resolve(PlatformNotificationService::class));
    expect($recipient->notifications()->count())->toBe(0);
    Bus::assertNotDispatched(SendExpoPushJob::class);
});

it('limits the Filament delivery settings page to admins', function (): void {
    $this->get(ManageNotificationDelivery::getUrl())->assertRedirect();
    $this->actingAs(User::factory()->create())->get(ManageNotificationDelivery::getUrl())->assertForbidden();
});

it('allows admins to save every per-event channel flag persistently through Filament', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $matrix = resolve(NotificationDeliverySettingsService::class)->matrix();
    $matrix['verification_received']['push'] = false;
    $matrix['poll_opened']['in_app'] = false;
    $matrix['announcement'] = ['in_app' => false, 'push' => false];
    Livewire::test(ManageNotificationDelivery::class)
        ->assertSee('Expo push transport is currently')
        ->fillForm(['events' => $matrix])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Notification delivery settings saved');
    Feature::flushCache();
    expect(resolve(NotificationDeliverySettingsService::class)->matrix())->toBe($matrix)
        ->and(config('services.expo_push.enabled'))->toBeFalse();
});

it('rechecks admin authorization on Livewire saves', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $matrix = resolve(NotificationDeliverySettingsService::class)->matrix();
    $matrix['account_verified']['push'] = false;
    $page = Livewire::test(ManageNotificationDelivery::class)->fillForm(['events' => $matrix]);
    $admin->removeRole('admin');
    $page->call('save')->assertForbidden();
    expect(resolve(NotificationDeliverySettingsService::class)->allows('account_verified', 'push'))->toBeTrue();
});

it('rejects invalid settings atomically rather than partially saving flags', function (string $invalid): void {
    $settings = resolve(NotificationDeliverySettingsService::class);
    $matrix = $settings->matrix();
    $matrix['verification_received']['push'] = false;
    match ($invalid) {
        'missing-event' => $matrix = array_diff_key($matrix, ['account_verified' => true]),
        'unknown-event' => $matrix['security_unknown'] = ['in_app' => true, 'push' => true],
        'unknown-channel' => $matrix['account_verified']['email'] = true,
        'missing-channel' => $matrix['account_verified'] = ['in_app' => true],
        'invalid-boolean' => $matrix['account_verified']['push'] = 'yes',
    };
    expect(fn () => $settings->save($matrix))->toThrow(ValidationException::class);
    Feature::flushCache();
    expect($settings->allows('verification_received', 'push'))->toBeTrue();
})->with(['missing-event', 'unknown-event', 'unknown-channel', 'missing-channel', 'invalid-boolean']);
