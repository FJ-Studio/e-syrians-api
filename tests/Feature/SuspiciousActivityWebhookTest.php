<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Mail\SuspiciousActivityAlert;

beforeEach(function (): void {
    Mail::fake();

    test()->user = User::factory()->create([
        'email' => 'suspect@gmail.com',
        'name' => 'Suspect',
        'surname' => 'User',
    ]);

    config(['services.internal_api_key' => 'test-internal-key']);
    config(['e-syrians.admin_notification_email' => 'admin@e-syrians.com']);
});

function internalHeader(): array
{
    return ['Authorization' => 'Bearer test-internal-key'];
}

// ───────────────────────────────────────────────
// Authentication
// ───────────────────────────────────────────────

it('rejects requests without authorization', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['profile_flip_flop'],
        'detected_at' => now()->toIso8601String(),
    ]);

    $response->assertStatus(401);
});

it('rejects requests with wrong token', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['profile_flip_flop'],
        'detected_at' => now()->toIso8601String(),
    ], ['Authorization' => 'Bearer wrong-token']);

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Successful Reports
// ───────────────────────────────────────────────

it('creates suspicious activity record from webhook', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['profile_flip_flop', 'change_before_vote'],
        'evidence' => ['country_changes' => 4],
        'detected_at' => '2026-04-18T10:00:00Z',
    ], internalHeader());

    $response->assertOk();

    $this->assertDatabaseHas('suspicious_activities', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'status' => 'pending',
    ]);
});

// ───────────────────────────────────────────────
// Email Notifications
// ───────────────────────────────────────────────

it('sends admin email for high severity', function (): void {
    $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['profile_flip_flop'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    Mail::assertQueued(SuspiciousActivityAlert::class, function ($mail) {
        return $mail->hasTo('admin@e-syrians.com');
    });
});

it('sends admin email for medium severity', function (): void {
    $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'medium',
        'score' => 45,
        'rules' => ['api_tool_usage'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    Mail::assertQueued(SuspiciousActivityAlert::class);
});

it('does not send admin email for low severity', function (): void {
    $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'low',
        'score' => 20,
        'rules' => ['api_tool_usage'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    Mail::assertNotQueued(SuspiciousActivityAlert::class);
});

it('does not send email when admin email is not configured', function (): void {
    config(['e-syrians.admin_notification_email' => null]);

    $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['profile_flip_flop'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    Mail::assertNotQueued(SuspiciousActivityAlert::class);
});

// ───────────────────────────────────────────────
// Validation
// ───────────────────────────────────────────────

it('rejects invalid severity', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'critical',
        'score' => 100,
        'rules' => ['test'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    $response->assertStatus(422);
});

it('rejects non-existent user', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => 99999,
        'severity' => 'high',
        'score' => 80,
        'rules' => ['test'],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    $response->assertStatus(422);
});

it('rejects empty rules array', function (): void {
    $response = $this->postJson('/internal/suspicious-activity', [
        'user_id' => test()->user->id,
        'severity' => 'high',
        'score' => 80,
        'rules' => [],
        'detected_at' => now()->toIso8601String(),
    ], internalHeader());

    $response->assertStatus(422);
});
