<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Device;
use App\Models\PollVote;
use App\Models\PollOption;
use Illuminate\Support\Str;
use App\Jobs\SendExpoPushJob;
use App\Models\SecurityEvent;
use App\Services\AuthService;
use App\Enums\PlatformFeature;
use App\Models\UserVerification;
use App\Services\ExpoPushService;
use App\Services\PasswordService;
use PragmaRX\Google2FA\Google2FA;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Services\SecurityEventService;
use App\Models\NotificationAnnouncement;
use Illuminate\Support\Facades\Password;
use App\Channels\Messages\ExpoPushMessage;
use App\Jobs\FanOutPlatformNotificationJob;
use App\Notifications\PlatformNotification;
use App\Services\PlatformNotificationService;
use App\Services\NotificationPreferenceService;

function granularDevice(User $user): Device
{
    return Device::create(['uuid' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
        'user_id' => $user->id, 'personal_access_token_id' => $user->createToken('device')->accessToken->id,
        'expo_push_token' => 'ExpoPushToken[private_granular_token]', 'registration_version' => 1, 'platform' => 'ios']);
}

it('defaults all transactional categories on and announcements off without granting device permission', function (): void {
    $user = User::factory()->create();
    $data = $this->getJson('/users/notification-preferences', authHeader($user))->assertOk()->json('data.categories');
    expect(count($data))->toBe(7);
    foreach (NotificationCategory::cases() as $category) {
        $expected = $category !== NotificationCategory::Announcements;
        $channels = ['in_app' => $expected, 'push' => $expected];
        if ($category === NotificationCategory::Verifications) {
            $channels['email'] = false;
        }
        if ($category === NotificationCategory::AccountStatus) {
            $channels['email'] = true;
        }
        expect($data[$category->value])->toBe($channels);
    }
    expect($user->devices()->count())->toBe(0);
});

it('updates only one account category and channel without overwriting other choices', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $headers = authHeader($user);
    foreach ([['security', 'push'], ['poll_results', 'in_app']] as [$category, $channel]) {
        $this->patchJson('/users/notification-preferences', ['user_uuid' => $user->uuid,
            'category' => $category, 'channel' => $channel, 'enabled' => false], $headers)->assertOk();
    }
    $data = $this->getJson('/users/notification-preferences', $headers)->assertOk()->json('data.categories');
    expect($data['security'])->toBe(['in_app' => true, 'push' => false])
        ->and($data['poll_results'])->toBe(['in_app' => false, 'push' => true])
        ->and($data['verifications'])->toBe(['in_app' => true, 'push' => true, 'email' => false])
        ->and($other->fresh()->notification_preferences)->toBeNull();
});

it('rejects unknown categories channels nonbooleans and stale-owner writes', function (array $bad): void {
    $user = User::factory()->create();
    $this->patchJson('/users/notification-preferences', [...['user_uuid' => $user->uuid,
        'category' => 'security', 'channel' => 'push', 'enabled' => false], ...$bad], authHeader($user))->assertUnprocessable();
    expect($user->fresh()->notification_preferences)->toBeNull();
})->with([
    [['category' => 'arbitrary']], [['channel' => 'email']], [['enabled' => 'not-a-boolean']], [['user_uuid' => '11111111-1111-4111-8111-111111111111']],
]);

it('keeps announcement consent explicit and independent for each delivery channel', function (): void {
    $user = User::factory()->create();
    $preferences = resolve(NotificationPreferenceService::class);
    $preferences->update($user, NotificationCategory::Announcements, 'push', true);
    expect($preferences->categories($user->fresh())['announcements'])->toBe(['in_app' => false, 'push' => true]);
    $preferences->update($user, NotificationCategory::Announcements, 'in_app', true);
    $preferences->update($user, NotificationCategory::Announcements, 'push', false);
    expect($user->fresh()->marketing_notifications_opt_in)->toBeTrue();
    $preferences->update($user, NotificationCategory::Announcements, 'in_app', false);
    expect($user->fresh()->marketing_notifications_opt_in)->toBeFalse()
        ->and($user->fresh()->marketing_notifications_consented_at)->toBeNull();
});

it('delivers inbox and push independently and deduplicates even after inbox deletion', function (bool $inApp, bool $push): void {
    Bus::fake();
    $user = User::factory()->create();
    granularDevice($user);
    $preferences = resolve(NotificationPreferenceService::class);
    $preferences->update($user, NotificationCategory::AccountStatus, 'in_app', $inApp);
    $preferences->update($user, NotificationCategory::AccountStatus, 'push', $push);
    $service = resolve(PlatformNotificationService::class);
    $service->send($user, new PlatformNotification('account_verified', (string) $user->id), 'transition');
    expect($user->notifications()->count())->toBe($inApp ? 1 : 0);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, $push ? 1 : 0);
    $user->notifications()->delete();
    $service->send($user, new PlatformNotification('account_verified', (string) $user->id), 'transition');
    expect($user->notifications()->count())->toBe(0);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, $push ? 1 : 0);
})->with([[true, true], [false, true], [true, false], [false, false]]);

it('rechecks category push opt-out when a queued delivery executes', function (): void {
    Http::fake();
    setPlatformFeature(PlatformFeature::ExpoPush);
    $user = User::factory()->create();
    $device = granularDevice($user);
    $job = new SendExpoPushJob($device->id, $user->id, 1, 'queued', new ExpoPushMessage(
        ['type' => 'verification'],
        category: NotificationCategory::AccountStatus,
        event: 'account_verified'
    ));
    resolve(NotificationPreferenceService::class)->update($user, NotificationCategory::AccountStatus, 'push', false);
    $job->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
});

it('records password and two-factor audit history even when both security channels are muted', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create();
    $preferences = resolve(NotificationPreferenceService::class);
    $preferences->update($user, NotificationCategory::Security, 'in_app', false);
    $preferences->update($user, NotificationCategory::Security, 'push', false);
    $user->password = Hash::make('changed-private-password');
    $user->save();
    $user->forceFill(['two_factor_enabled' => true, 'two_factor_confirmed_at' => now()])->save();
    $user->forceFill(['two_factor_enabled' => false, 'two_factor_confirmed_at' => null])->save();
    $events = SecurityEvent::where('user_id', $user->id)->get();
    expect($events->pluck('event')->all())->toBe(['security_password_changed', 'security_two_factor_enabled', 'security_two_factor_disabled']);
    foreach ($events as $event) {
        (new FanOutPlatformNotificationJob($event->event, $event->id))->handle(resolve(PlatformNotificationService::class));
    }
    expect($user->notifications()->count())->toBe(0)->and(json_encode($events))->not->toContain('changed-private-password');
    Bus::assertNotDispatched(SendExpoPushJob::class);
});

it('does not report incomplete two-factor setup or a failed password change as security events', function (): void {
    $user = User::factory()->create();
    resolve(TwoFactorService::class)->setupTwoFactor($user);
    $result = resolve(PasswordService::class)->changePassword($user, 'wrong-password', 'new-password');
    expect($result['success'])->toBeFalse()->and(SecurityEvent::where('user_id', $user->id)->count())->toBe(0);
});

it('records a successful password reset without storing the reset credential in audit or jobs', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $result = resolve(PasswordService::class)->resetPassword($user->email, $token, 'private-new-password');
    expect($result['success'])->toBeTrue()->and(SecurityEvent::where('user_id', $user->id)->count())->toBe(1)
        ->and(SecurityEvent::first()->event)->toBe('security_password_changed');
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => ! str_contains(serialize($job), $token)
        && ! str_contains(serialize($job), 'private-new-password'));
});

it('alerts on new installations but retains every successful sign-in in independent audit history', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create();
    $installation = (string) Str::uuid();
    request()->headers->set('X-Installation-ID', $installation);
    request()->headers->set('X-Device-Info', 'Test iPhone');
    $service = resolve(AuthService::class);
    expect($service->authenticateViaCredentials($user->email, 'wrong'))->toBeNull();
    expect(SecurityEvent::count())->toBe(0);
    $service->authenticateViaCredentials($user->email, 'password');
    $service->authenticateViaCredentials($user->email, 'password');
    request()->headers->set('X-Installation-ID', (string) Str::uuid());
    $service->authenticateViaCredentials($user->email, 'password');
    $events = SecurityEvent::where('user_id', $user->id)->get();
    expect($events->pluck('new_device')->all())->toBe([true, false, true]);
    Bus::assertDispatchedTimes(FanOutPlatformNotificationJob::class, 2);
    expect(DB::table('security_events')->value('device_info'))->not->toBe('Test iPhone')
        ->and(json_encode(DB::table('security_known_devices')->get()))->not->toContain($installation);
});

it('does not infer device trust from user-agent for clients without an installation hint', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create();
    resolve(AuthService::class)->issueLoginToken($user, 'web');
    resolve(AuthService::class)->issueLoginToken($user, 'web');
    expect(SecurityEvent::where('user_id', $user->id)->count())->toBe(2);
    Bus::assertDispatchedTimes(FanOutPlatformNotificationJob::class, 2);
});

it('creates a sign-in audit only after successful second-factor verification', function (): void {
    Bus::fake();
    $google = resolve(Google2FA::class);
    $secret = $google->generateSecretKey();
    $user = User::factory()->create(['two_factor_secret' => $secret, 'two_factor_enabled' => true, 'two_factor_confirmed_at' => now()]);
    $challenge = resolve(AuthService::class)->authenticateViaCredentials($user->email, 'password');
    expect($challenge['requires_2fa'])->toBeTrue()->and(SecurityEvent::count())->toBe(0);
    $this->postJson('/users/2fa/verify', ['challenge_token' => $challenge['challenge_token'],
        'code' => $google->getCurrentOtp($secret)])->assertOk();
    expect(SecurityEvent::where('user_id', $user->id)->count())->toBe(1);
});

it('scopes paginated security history to its owner and keeps it when inbox entries are deleted', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    for ($i = 0; $i < 16; $i++) {
        resolve(SecurityEventService::class)->record($owner, 'security_password_changed');
    }
    resolve(SecurityEventService::class)->record($other, 'security_password_changed');
    $headers = authHeader($owner);
    $page = $this->getJson('/users/security-events', $headers)->assertOk()->assertJsonPath('data.total', 16)->assertJsonCount(15, 'data.data');
    expect($page->json('data.data.0'))->not->toHaveKey('user_id');
    resolve(PlatformNotificationService::class)->send($owner, new PlatformNotification('security_password_changed', '1'), 'audit');
    $notification = $owner->notifications()->first();
    $this->deleteJson('/users/notifications/'.$notification->id, [], $headers)->assertOk();
    expect(SecurityEvent::where('user_id', $owner->id)->count())->toBe(16);
});

it('sends revocation and loss-of-status updates and allows later verification transitions again', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create(['verified_at' => now()]);
    $verification = UserVerification::create(['user_id' => $user->id, 'verifier_id' => User::factory()->create()->id]);
    $verification->update(['cancelled_at' => now()]);
    $user->forceFill(['verified_at' => null])->save();
    (new FanOutPlatformNotificationJob('verification_revoked', $verification->id))->handle(resolve(PlatformNotificationService::class));
    (new FanOutPlatformNotificationJob('account_unverified', $user->id, sourceKey: 'loss-one'))->handle(resolve(PlatformNotificationService::class));
    $user->forceFill(['verified_at' => now()])->save();
    (new FanOutPlatformNotificationJob('account_verified', $user->id, sourceKey: 'gain-one'))->handle(resolve(PlatformNotificationService::class));
    $user->forceFill(['verified_at' => null])->save();
    (new FanOutPlatformNotificationJob('account_unverified', $user->id, sourceKey: 'loss-two'))->handle(resolve(PlatformNotificationService::class));
    expect($user->notifications()->count())->toBe(4);
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'verification_revoked');
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'account_unverified' && $job->sourceKey !== null);
});

it('notifies poll creators and voters at closing once without exposing vote totals or inviting bystanders', function (): void {
    $owner = User::factory()->create();
    $voter = User::factory()->create();
    $other = User::factory()->create();
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Closed poll', 'start_date' => now()->subDays(2),
        'end_date' => now()->subMinute(), 'is_private' => false, 'reveal_results' => 'after-voting']);
    $option = PollOption::forceCreate(['poll_id' => $poll->id, 'created_by' => $owner->id, 'option_text' => 'A']);
    PollVote::forceCreate(['poll_id' => $poll->id, 'poll_option_id' => $option->id, 'user_id' => $voter->id]);
    $job = new FanOutPlatformNotificationJob('poll_closed', $poll->id);
    $job->handle(resolve(PlatformNotificationService::class));
    $job->handle(resolve(PlatformNotificationService::class));
    expect($owner->notifications()->count())->toBe(1)->and($voter->notifications()->count())->toBe(1)
        ->and($other->notifications()->count())->toBe(0)->and($poll->fresh()->closure_notified_at)->not->toBeNull();
    expect($voter->notifications()->first()->data)->not->toHaveKey('results');
});

it('does not notify closing before the deadline or expose private polls to nonowners', function (): void {
    $owner = User::factory()->create();
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Private poll', 'start_date' => now()->subDay(),
        'end_date' => now()->addHour(), 'is_private' => true]);
    $job = new FanOutPlatformNotificationJob('poll_closed', $poll->id);
    $job->handle(resolve(PlatformNotificationService::class));
    expect($owner->notifications()->count())->toBe(0);
    $this->travel(2)->hours();
    $job->handle(resolve(PlatformNotificationService::class));
    expect($owner->notifications()->count())->toBe(1);
});

it('preserves legacy inbox deduplication when adding the independent delivery ledger', function (): void {
    $user = User::factory()->create();
    $id = (string) Str::uuid();
    DB::table('notifications')->insert(['id' => $id, 'type' => PlatformNotification::class,
        'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => '{}',
        'created_at' => null, 'updated_at' => null]);
    $migration = require database_path('migrations/2026_11_01_000001_add_granular_notifications_and_security_history.php');
    $migration->down();
    $migration->up();
    expect(DB::table('notification_deliveries')->where('id', $id)->value('user_id'))->toBe($user->id)
        ->and(DB::table('notification_deliveries')->where('id', $id)->value('created_at'))->not->toBeNull();
});

it('lets push-only announcement recipients read the message without inserting an inbox entry', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    resolve(NotificationPreferenceService::class)->update($user, NotificationCategory::Announcements, 'push', true);
    $announcement = NotificationAnnouncement::create(['uuid' => (string) Str::uuid(), 'title' => 'Recipient-only news', 'body' => 'Full announcement content']);
    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification(
        'announcement',
        (string) $announcement->id,
        $announcement->title,
        $announcement->body
    ), (string) $announcement->id);
    expect($user->notifications()->count())->toBe(0);
    $this->getJson('/users/announcements/'.$announcement->id, authHeader($user))->assertOk()
        ->assertJsonPath('data.body', 'Full announcement content')->assertHeader('Cache-Control', 'no-store, private');
    $this->app['auth']->forgetGuards();
    $this->getJson('/users/announcements/'.$announcement->id, authHeader($other))->assertNotFound();
});

it('does not make muted announcements readable but preserves previously received ones after opting out', function (): void {
    $user = User::factory()->create();
    $announcement = NotificationAnnouncement::create(['uuid' => (string) Str::uuid(), 'title' => 'News', 'body' => 'Content']);
    $service = resolve(PlatformNotificationService::class);
    $service->send($user, new PlatformNotification('announcement', (string) $announcement->id), (string) $announcement->id);
    $headers = authHeader($user);
    $this->getJson('/users/announcements/'.$announcement->id, $headers)->assertNotFound();
    resolve(NotificationPreferenceService::class)->update($user, NotificationCategory::Announcements, 'in_app', true);
    $new = NotificationAnnouncement::create(['uuid' => (string) Str::uuid(), 'title' => 'Another', 'body' => 'New content']);
    $service->send($user, new PlatformNotification('announcement', (string) $new->id), (string) $new->id);
    resolve(NotificationPreferenceService::class)->update($user, NotificationCategory::Announcements, 'in_app', false);
    $user->notifications()->delete();
    $this->getJson('/users/announcements/'.$new->id, $headers)->assertOk();
    $service->send($user, new PlatformNotification('announcement', (string) $announcement->id), (string) $announcement->id);
    $this->getJson('/users/announcements/'.$announcement->id, $headers)->assertNotFound();
});
