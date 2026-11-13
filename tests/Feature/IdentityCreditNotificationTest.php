<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserBlock;
use Laravel\Pennant\Feature;
use App\Enums\PlatformFeature;
use App\Enums\NotificationEvent;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\IdentitySandboxCredit;
use App\Jobs\NotifyIdentityCreditRecipient;
use App\Services\NotificationPreferenceService;
use App\Contracts\IdentitySandboxServiceContract;
use App\Services\NotificationDeliverySettingsService;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['identity-sandbox.enabled' => true, 'queue.default' => 'sync']);
    setPlatformFeature(PlatformFeature::NotificationEvents);
});

it('notifies only the grant recipient after commit and deduplicates a job retry', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $recipient = User::factory()->create();
    $outsider = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $recipient->uuid]]);
    $sandbox = resolve(IdentitySandboxServiceContract::class);
    DB::transaction(function () use ($sandbox, $admin, $recipient): void {
        $sandbox->grant($admin, $recipient, 'Private grant reason');
        expect($recipient->notifications()->count())->toBe(0);
    });

    $credit = IdentitySandboxCredit::query()->sole();
    app()->call([(new NotifyIdentityCreditRecipient(NotificationEvent::IdentityCreditGranted, $credit->id)), 'handle']);
    $notification = $recipient->notifications()->sole();
    expect($admin->notifications()->count())->toBe(0)
        ->and($outsider->notifications()->count())->toBe(0)
        ->and($notification->data)->toMatchArray([
            'type' => 'identity-verification',
            'title_key' => 'notificationEvents.identity_credit_granted.title',
            'body_key' => 'notificationEvents.identity_credit_granted.body',
        ]);
    expect($notification->data)->toHaveCount(5)->not->toHaveKey('subject_id');
    $this->getJson('/users/notifications', authHeader($recipient))
        ->assertOk()->assertJsonPath('data.data.0.id', $notification->id)
        ->assertDontSee('Private grant reason')->assertDontSee($credit->uuid);
    Auth::forgetGuards();
    $this->getJson('/users/notifications', authHeader($outsider))->assertOk()->assertJsonCount(0, 'data.data');
    Http::assertNothingSent();
});

it('notifies the gift recipient exactly once, never the sender or an unsuccessful attempt', function (): void {
    [$sender, $recipient, $other] = User::factory()->count(3)->create()->all();
    config(['identity-sandbox.test_users' => [(string) $sender->uuid, (string) $recipient->uuid, (string) $other->uuid]]);
    $recipientHeaders = authHeader($recipient);
    $senderHeaders = authHeader($sender);
    $otherHeaders = authHeader($other);
    $code = $this->postJson('/users/identity-sandbox/gift-code', [], $recipientHeaders)->assertOk()->json('data.code');
    Auth::forgetGuards();
    $credit = $this->postJson('/users/identity-sandbox/claim', [], $senderHeaders)->assertOk()->json('data.credits.0.uuid');
    Auth::forgetGuards();
    $otherCredit = $this->postJson('/users/identity-sandbox/claim', [], $otherHeaders)->assertOk()->json('data.credits.0.uuid');
    UserBlock::query()->create(['blocker_id' => $recipient->id, 'blocked_id' => $sender->id]);
    Auth::forgetGuards();
    $this->postJson("/users/identity-sandbox/{$credit}/gift", ['code' => $code], $senderHeaders)->assertUnprocessable();
    $this->assertDatabaseCount('notifications', 0);
    UserBlock::query()->where('blocker_id', $recipient->id)->delete();

    $this->postJson("/users/identity-sandbox/{$credit}/gift", ['code' => $code], $senderHeaders)->assertOk();
    Auth::forgetGuards();
    $this->postJson("/users/identity-sandbox/{$otherCredit}/gift", ['code' => $code], $otherHeaders)->assertUnprocessable();
    $this->assertDatabaseCount('notifications', 1);
    $item = $recipient->notifications()->sole();
    expect($item->data)->toMatchArray([
        'type' => 'identity-verification',
        'title_key' => 'notificationEvents.identity_credit_gifted.title',
        'body_key' => 'notificationEvents.identity_credit_gifted.body',
    ])->toHaveCount(5);
    Auth::forgetGuards();
    $this->getJson('/users/notifications', $recipientHeaders)->assertOk()
        ->assertJsonPath('data.data.0.id', $item->id)->assertDontSee($code)->assertDontSee($credit);
    Auth::forgetGuards();
    $this->getJson('/users/notifications', $senderHeaders)->assertOk()->assertJsonCount(0, 'data.data');
    Http::assertNothingSent();
});

it('does not leave a notification or a delivery record for a rolled-back grant', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $recipient = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $recipient->uuid]]);
    expect(fn () => DB::transaction(function () use ($admin, $recipient): void {
        resolve(IdentitySandboxServiceContract::class)->grant($admin, $recipient, 'Rolled back');
        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class, 'Rolled back');
    $this->assertDatabaseCount('identity_sandbox_credits', 0);
    $this->assertDatabaseCount('notification_deliveries', 0);
    $this->assertDatabaseCount('notifications', 0);
});

it('honors the platform generation flag, recipient opt-out, and the per-event in-app flag', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    [$globalOff, $muted, $eventOff] = User::factory()->count(3)->create()->all();
    config(['identity-sandbox.test_users' => [(string) $globalOff->uuid, (string) $muted->uuid, (string) $eventOff->uuid]]);
    $sandbox = resolve(IdentitySandboxServiceContract::class);
    setPlatformFeature(PlatformFeature::NotificationEvents, false);
    $sandbox->grant($admin, $globalOff, 'Generation off');
    $this->assertDatabaseCount('notification_deliveries', 0);
    setPlatformFeature(PlatformFeature::NotificationEvents);
    resolve(NotificationPreferenceService::class)->update($muted, NotificationCategory::Verifications, 'in_app', false);
    $sandbox->grant($admin, $muted, 'Muted');
    Feature::for(NotificationDeliverySettingsService::FEATURE_SCOPE)->activate(
        NotificationDeliverySettingsService::featureName(NotificationEvent::IdentityCreditGranted, 'in_app'),
        false,
    );
    $sandbox->grant($admin, $eventOff, 'Event disabled');
    $this->assertDatabaseCount('identity_sandbox_credits', 3);
    $this->assertDatabaseCount('notifications', 0);
});

it('drops queued credit notices if sandbox access is removed before delivery', function (): void {
    Bus::fake([NotifyIdentityCreditRecipient::class]);
    $admin = User::factory()->create()->assignRole('admin');
    $recipient = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $recipient->uuid]]);
    resolve(IdentitySandboxServiceContract::class)->grant($admin, $recipient, 'Delayed');
    $job = Bus::dispatched(NotifyIdentityCreditRecipient::class)->sole();
    expect($job->afterCommit)->toBeTrue();
    config(['identity-sandbox.test_users' => []]);
    app()->call([$job, 'handle']);
    $this->assertDatabaseCount('notifications', 0);
});
