<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Pennant\Feature;
use App\Enums\NotificationEvent;
use App\Mail\UserAccountVerified;
use App\Events\VerificationReceived;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserReceivedVerification;
use App\Listeners\CheckUserVerificationStatus;
use App\Services\NotificationDeliverySettingsService;

it('reads the existing optional email choices as booleans alongside inbox and push', function (): void {
    $user = User::factory()->create(['received_verification_email' => true, 'account_verified_email' => false]);
    $this->getJson('/users/notification-preferences', authHeader($user))->assertOk()
        ->assertJsonPath('data.categories.verifications.email', true)
        ->assertJsonPath('data.categories.account_status.email', false)
        ->assertJsonMissingPath('data.categories.security.email');
});

it('changes only the selected email preference and preserves other channels and accounts', function (): void {
    $user = User::factory()->create(['received_verification_email' => false, 'account_verified_email' => true]);
    $other = User::factory()->create(['received_verification_email' => false]);
    $this->patchJson('/users/notification-preferences', ['user_uuid' => $user->uuid,
        'category' => 'verifications', 'channel' => 'email', 'enabled' => true], authHeader($user))->assertOk()
        ->assertJsonPath('data.categories.verifications.email', true)
        ->assertJsonPath('data.categories.verifications.in_app', true)
        ->assertJsonPath('data.categories.verifications.push', true)
        ->assertJsonPath('data.categories.account_status.email', true);
    expect((bool) $user->fresh()->received_verification_email)->toBeTrue()
        ->and((bool) $other->fresh()->received_verification_email)->toBeFalse()
        ->and($user->fresh()->notification_preferences['verifications'])->not->toHaveKey('email');
});

it('supports opting out of account-verification email independently', function (): void {
    $user = User::factory()->create(['account_verified_email' => true]);
    $this->patchJson('/users/notification-preferences', ['user_uuid' => $user->uuid,
        'category' => 'account_status', 'channel' => 'email', 'enabled' => false], authHeader($user))->assertOk()
        ->assertJsonPath('data.categories.account_status.email', false)
        ->assertJsonPath('data.categories.account_status.push', true);
    expect((bool) $user->fresh()->account_verified_email)->toBeFalse();
});

it('rejects email for notification categories that do not send optional emails', function (string $category): void {
    $user = User::factory()->create();
    $this->patchJson('/users/notification-preferences', ['user_uuid' => $user->uuid,
        'category' => $category, 'channel' => 'email', 'enabled' => true], authHeader($user))->assertUnprocessable();
    expect($user->fresh()->notification_preferences)->toBeNull();
})->with(['poll_invitations', 'poll_results', 'feature_requests', 'announcements', 'security']);

it('requires both the verification email user preference and global channel flag', function (bool $flag, bool $preference): void {
    Mail::fake();
    Feature::for(NotificationDeliverySettingsService::FEATURE_SCOPE)->activate(
        NotificationDeliverySettingsService::featureName(NotificationEvent::VerificationReceived, 'email'),
        $flag,
    );
    $sender = User::factory()->create();
    $recipient = User::factory()->create(['verified_at' => now(), 'received_verification_email' => $preference]);
    (new CheckUserVerificationStatus())->handle(new VerificationReceived($sender, $recipient));
    Mail::assertSentCount($flag && $preference ? 1 : 0);
    if ($flag && $preference) {
        Mail::assertSent(UserReceivedVerification::class);
    }
})->with([[false, true], [true, false], [false, false], [true, true]]);

it('can mute account verification email without preventing the account becoming verified', function (bool $flag): void {
    Mail::fake();
    Feature::for(NotificationDeliverySettingsService::FEATURE_SCOPE)->activate(
        NotificationDeliverySettingsService::featureName(NotificationEvent::AccountVerified, 'email'),
        $flag,
    );
    config(['e-syrians.verification.min' => 3]);
    $recipient = User::factory()->create(['verified_at' => null, 'account_verified_email' => true]);
    $senders = User::factory()->count(3)->create();
    foreach ($senders as $sender) {
        $recipient->verifiers()->create(['verifier_id' => $sender->id]);
    }
    (new CheckUserVerificationStatus())->handle(new VerificationReceived($senders->last(), $recipient));
    expect($recipient->fresh()->verified_at)->not->toBeNull();
    Mail::assertSentCount($flag ? 1 : 0);
    if ($flag) {
        Mail::assertSent(UserAccountVerified::class);
    }
})->with([false, true]);
