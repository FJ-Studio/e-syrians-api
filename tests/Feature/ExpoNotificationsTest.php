<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Device;
use Livewire\Livewire;
use Illuminate\Support\Str;
use App\Jobs\SendExpoPushJob;
use App\Enums\PlatformFeature;
use App\Models\FeatureRequest;
use Filament\Facades\Filament;
use App\Models\UserVerification;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use App\Exceptions\ExpoPushException;
use App\Jobs\ProcessExpoPushReceiptJob;
use App\Models\NotificationAnnouncement;
use App\Filament\Pages\SendAnnouncements;
use App\Channels\Messages\ExpoPushMessage;
use App\Jobs\FanOutPlatformNotificationJob;
use App\Notifications\PlatformNotification;
use App\Services\PlatformNotificationService;

function pushTestDevice(User $user): Device
{
    $token = $user->createToken('push-device')->accessToken;
    return Device::create(['uuid' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
        'user_id' => $user->id, 'personal_access_token_id' => $token->id, 'platform' => 'ios',
        'expo_push_token' => 'ExpoPushToken[private_test_token]', 'token_hash' => hash('sha256', (string) Str::uuid()),
        'registration_version' => 1]);
}

it('stores each event once and queues token-free pushes for each active device', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    pushTestDevice($user);
    $service = resolve(PlatformNotificationService::class);
    $service->send($user, new PlatformNotification('account_verified', (string) $user->id), 'source');
    $service->send($user, new PlatformNotification('account_verified', (string) $user->id), 'source');
    expect($user->notifications()->count())->toBe(1);
    Bus::assertDispatchedTimes(SendExpoPushJob::class, 1);
    Bus::assertDispatched(SendExpoPushJob::class, fn ($job) => ! str_contains(serialize($job), 'private_test_token')
        && $job->message->data['recipient_uuid'] === (string) $user->uuid);
});

it('delivers and deduplicates verification and account-verification inbox events', function (): void {
    $user = User::factory()->create(['verified_at' => now()]);
    $verification = UserVerification::create(['user_id' => $user->id, 'verifier_id' => User::factory()->create()->id]);
    foreach (['verification_received' => $verification->id, 'account_verified' => $user->id] as $event => $id) {
        (new FanOutPlatformNotificationJob($event, $id))->handle(resolve(PlatformNotificationService::class));
        (new FanOutPlatformNotificationJob($event, $id))->handle(resolve(PlatformNotificationService::class));
    }
    expect($user->notifications()->count())->toBe(2);
    $verification->update(['cancelled_at' => now()]);
    $user->notifications()->delete();
    (new FanOutPlatformNotificationJob('verification_received', $verification->id))->handle(resolve(PlatformNotificationService::class));
    expect($user->notifications()->count())->toBe(0);
});

it('wires observers to real transitions and not cosmetic timeline changes', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::NotificationEvents);
    $user = User::factory()->create();
    $user->forceFill(['verified_at' => now()])->save();
    UserVerification::create(['user_id' => $user->id, 'verifier_id' => User::factory()->create()->id]);
    $feature = FeatureRequest::forceCreate(['created_by' => $user->id, 'title' => 'Idea', 'description' => 'Test']);
    $feature->update(['coded_at' => now()]);
    $feature->update(['coded_at' => now()->subMinute()]);
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'account_verified');
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'verification_received');
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'feature_in_development');
    Bus::assertDispatchedTimes(FanOutPlatformNotificationJob::class, 3);
});

it('invites only verified eligible users and never exposes poll content in a push', function (): void {
    Bus::fake();
    $owner = User::factory()->create(['verified_at' => now()]);
    $eligible = User::factory()->create(['verified_at' => now(), 'gender' => 'f']);
    $ineligible = User::factory()->create(['verified_at' => now(), 'gender' => 'm']);
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Private question text',
        'start_date' => now()->subHour(), 'end_date' => now()->addDay(), 'max_selections' => 1,
        'audience_can_add_options' => false, 'is_private' => false]);
    $poll->audienceRules()->create(['criterion' => 'gender', 'value' => 'f']);
    (new FanOutPlatformNotificationJob('poll_opened', $poll->id))->handle(resolve(PlatformNotificationService::class));
    expect($eligible->notifications()->count())->toBe(1)->and($ineligible->notifications()->count())->toBe(0)
        ->and($owner->notifications()->count())->toBe(0)->and($poll->fresh()->invitations_dispatched_at)->not->toBeNull();
    expect(json_encode((new ExpoPushMessage(['type' => 'poll-audience', 'subject_id' => $poll->id]))->payload('token')))
        ->not->toContain('Private question text');
});

it('notifies feature creators and voters but skips stale stages', function (): void {
    $creator = User::factory()->create();
    $voter = User::factory()->create();
    $other = User::factory()->create();
    $feature = FeatureRequest::forceCreate(['created_by' => $creator->id, 'title' => 'Idea', 'description' => 'Test', 'coded_at' => now()]);
    $feature->votes()->create(['user_id' => $voter->id, 'vote' => 'up']);
    (new FanOutPlatformNotificationJob('feature_in_development', $feature->id))->handle(resolve(PlatformNotificationService::class));
    (new FanOutPlatformNotificationJob('feature_shipped', $feature->id))->handle(resolve(PlatformNotificationService::class));
    expect($creator->notifications()->count())->toBe(1)->and($voter->notifications()->count())->toBe(1)
        ->and($other->notifications()->count())->toBe(0);
});

it('keeps announcement consent account scoped and false by default', function (): void {
    $user = User::factory()->create();
    $headers = authHeader($user);
    $this->getJson('/users/notification-preferences', $headers)->assertOk()->assertJsonPath('data.marketing_opt_in', false);
    $payload = ['category' => 'announcements', 'channel' => 'in_app', 'enabled' => true, 'user_uuid' => $user->uuid];
    $this->patchJson('/users/notification-preferences', [...$payload, 'user_uuid' => (string) Str::uuid()], $headers)->assertUnprocessable();
    $this->patchJson('/users/notification-preferences', $payload, $headers)->assertOk();
    expect($user->fresh()->marketing_notifications_consented_at)->not->toBeNull();
    $this->patchJson('/users/notification-preferences', [...$payload, 'enabled' => false], $headers)->assertOk();
    expect($user->fresh()->marketing_notifications_consented_at)->toBeNull();
    $this->actingAs($user);
    expect(SendAnnouncements::canAccess())->toBeFalse();
});

it('fans announcements out only to opted-in nonpending accounts', function (): void {
    $yes = User::factory()->create(['marketing_notifications_opt_in' => true]);
    $no = User::factory()->create();
    $pending = User::factory()->create(['marketing_notifications_opt_in' => true, 'deletion_scheduled_for' => now()->addDay()]);
    $announcement = NotificationAnnouncement::create(['uuid' => (string) Str::uuid(), 'title' => 'News', 'body' => 'Announcement body']);
    (new FanOutPlatformNotificationJob('announcement', $announcement->id))->handle(resolve(PlatformNotificationService::class));
    expect($yes->notifications()->count())->toBe(1)->and($no->notifications()->count())->toBe(0)
        ->and($pending->notifications()->count())->toBe(0)->and($announcement->fresh()->processed_at)->not->toBeNull();
});

it('sends a generic payload and processes tickets without persisting tokens in jobs', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::ExpoPush);
    Http::fake(['*/send' => Http::response(['data' => ['status' => 'ok', 'id' => 'receipt-1']])]);
    $user = User::factory()->create();
    $device = pushTestDevice($user);
    $job = new SendExpoPushJob($device->id, $user->id, 1, 'notification-1', new ExpoPushMessage(['type' => 'verification'], event: 'verification_received'));
    $job->handle(resolve(ExpoPushService::class));
    $job->handle(resolve(ExpoPushService::class));
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['body'] === 'You have a new update in E-Syrians.' && ! isset($request['data']['name']));
    Bus::assertDispatched(ProcessExpoPushReceiptJob::class);
    expect(serialize($job))->not->toContain('private_test_token');
});

it('drops stale jobs after reassignment and announcements after opting out', function (): void {
    setPlatformFeature(PlatformFeature::ExpoPush);
    Http::fake();
    $user = User::factory()->create();
    $device = pushTestDevice($user);
    (new SendExpoPushJob($device->id, $user->id, 0, 'old', new ExpoPushMessage(event: 'account_verified')))->handle(resolve(ExpoPushService::class));
    (new SendExpoPushJob($device->id, $user->id, 1, 'announcement', new ExpoPushMessage(['type' => 'announcement'], event: 'announcement')))
        ->handle(resolve(ExpoPushService::class));
    Http::assertNothingSent();
});

it('disables invalid registrations from tickets or receipts but not rotated tokens', function (): void {
    setPlatformFeature(PlatformFeature::ExpoPush);
    Http::fake(['*/send' => Http::response(['data' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]),
        '*/getReceipts' => Http::response(['data' => ['receipt' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    $user = User::factory()->create();
    $device = pushTestDevice($user);
    (new SendExpoPushJob($device->id, $user->id, 1, 'a', new ExpoPushMessage(event: 'account_verified')))->handle(resolve(ExpoPushService::class));
    expect($device->fresh()->disabled_at)->not->toBeNull();
    $device->update(['disabled_at' => null, 'registration_version' => 2]);
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 1, 'a', new ExpoPushMessage()))->handle(resolve(ExpoPushService::class));
    expect($device->fresh()->disabled_at)->toBeNull();
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 2, 'a', new ExpoPushMessage()))->handle(resolve(ExpoPushService::class));
    expect($device->fresh()->disabled_at)->not->toBeNull();
});

it('sanitizes provider errors rather than leaking echoed tokens', function (): void {
    Http::fake(['*' => Http::response(['data' => ['status' => 'error', 'message' => 'private_test_token']], 200)]);
    try {
        resolve(ExpoPushService::class)->send('private_test_token', new ExpoPushMessage());
        test()->fail('Expected sanitized transport error');
    } catch (ExpoPushException $error) {
        expect($error->getMessage())->not->toContain('private_test_token');
    }
});

it('uses live saved-audience membership when a future poll opens', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    $member = User::factory()->create(['verified_at' => now()]);
    $outsider = User::factory()->create(['verified_at' => now()]);
    $audience = $owner->audiences()->create(['name' => 'Reusable list']);
    $audience->entries()->create(['identifier' => $member->email, 'identifier_type' => 'email']);
    $poll = Poll::forceCreate(['created_by' => $owner->id, 'question' => 'Future poll',
        'start_date' => now()->addHour(), 'end_date' => now()->addDay(), 'max_selections' => 1,
        'audience_can_add_options' => false, 'is_private' => false, 'audience_id' => $audience->id]);
    $job = new FanOutPlatformNotificationJob('poll_opened', $poll->id);
    $job->handle(resolve(PlatformNotificationService::class));
    expect($member->notifications()->count())->toBe(0);
    $this->travel(2)->hours();
    $job->handle(resolve(PlatformNotificationService::class));
    expect($member->notifications()->count())->toBe(1)->and($outsider->notifications()->count())->toBe(0);
    $job->handle(resolve(PlatformNotificationService::class));
    expect($member->notifications()->count())->toBe(1);
});

it('queues bounded fanout pages and resumes without duplicate inbox rows', function (): void {
    Bus::fake();
    $users = User::factory()->count(201)->create(['marketing_notifications_opt_in' => true]);
    $announcement = NotificationAnnouncement::create(['uuid' => (string) Str::uuid(), 'title' => 'News', 'body' => 'Body']);
    (new FanOutPlatformNotificationJob('announcement', $announcement->id))->handle(resolve(PlatformNotificationService::class));
    expect($announcement->fresh()->processed_at)->toBeNull();
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->afterId === $users[199]->id);
    (new FanOutPlatformNotificationJob('announcement', $announcement->id, $users[199]->id))->handle(resolve(PlatformNotificationService::class));
    expect($users->last()->notifications()->count())->toBe(1)->and($announcement->fresh()->processed_at)->not->toBeNull();
});

it('rechecks consent rather than trusting a cached opted-in recipient', function (): void {
    $user = User::factory()->create(['marketing_notifications_opt_in' => true]);
    User::whereKey($user->id)->update(['marketing_notifications_opt_in' => false]);
    resolve(PlatformNotificationService::class)->send($user, new PlatformNotification('announcement', '1'), '1');
    expect($user->notifications()->count())->toBe(0);
});

it('allows only admins to queue validated announcements from Filament', function (): void {
    Bus::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Livewire::test(SendAnnouncements::class)->fillForm(['title' => 'News', 'body' => 'New platform update'])
        ->callAction('send')->assertHasNoFormErrors();
    expect(NotificationAnnouncement::count())->toBe(1);
    Bus::assertDispatched(FanOutPlatformNotificationJob::class, fn ($job) => $job->event === 'announcement');
    $page = Livewire::test(SendAnnouncements::class)->fillForm(['title' => 'Unauthorized', 'body' => 'No']);
    $admin->removeRole('admin');
    $page->call('mountAction', 'send')->assertForbidden();
    expect(NotificationAnnouncement::count())->toBe(1);
});

it('bounds receipt rate-limit resends and does not disable a healthy token', function (): void {
    Bus::fake();
    setPlatformFeature(PlatformFeature::ExpoPush);
    Http::fake(['*' => Http::response(['data' => ['receipt' => ['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']]]])]);
    $user = User::factory()->create();
    $device = pushTestDevice($user);
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 1, 'a', new ExpoPushMessage(), 0))->handle(resolve(ExpoPushService::class));
    (new ProcessExpoPushReceiptJob('receipt', $device->id, $user->id, 1, 'a', new ExpoPushMessage(), 2))->handle(resolve(ExpoPushService::class));
    Bus::assertDispatchedTimes(SendExpoPushJob::class, 1);
    expect($device->fresh()->disabled_at)->toBeNull();
});
