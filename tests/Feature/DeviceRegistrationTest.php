<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Device;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    test()->user = User::factory()->create([
        'email' => 'device_owner@e-syrians.test',
        'uuid' => 'aaaa1111-bbbb-2222-cccc-333333333333',
    ]);
});

// ─────────────────────────────────────────────────────
// POST /users/devices — register
// ─────────────────────────────────────────────────────

it('rejects unauthenticated POST /users/devices', function (): void {
    $response = test()->postJson('/users/devices', [
        'subscription_id' => 'os-sub-1',
        'platform' => 'ios',
    ]);

    $response->assertStatus(401);
});

it('registers a new device with 201 + persists the row', function (): void {
    Sanctum::actingAs(test()->user);

    $response = test()->postJson('/users/devices', [
        'subscription_id' => 'os-sub-1',
        'platform' => 'ios',
        'model' => 'iPhone 15 Pro',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('data.subscription_id', 'os-sub-1');
    $response->assertJsonPath('data.platform', 'ios');

    expect(Device::where('subscription_id', 'os-sub-1')->count())->toBe(1);
    expect(Device::where('subscription_id', 'os-sub-1')->first()->user_id)->toBe(test()->user->id);
});

it('returns 200 (not 201) on idempotent re-register by same user', function (): void {
    Sanctum::actingAs(test()->user);

    test()->postJson('/users/devices', [
        'subscription_id' => 'os-sub-1',
        'platform' => 'ios',
    ])->assertStatus(201);

    // Same payload again — no row created, 200 OK.
    $response = test()->postJson('/users/devices', [
        'subscription_id' => 'os-sub-1',
        'platform' => 'ios',
    ]);

    $response->assertStatus(200);
    expect(Device::where('subscription_id', 'os-sub-1')->count())->toBe(1);
});

it('re-links a device when a different user registers the same subscription', function (): void {
    $otherUser = User::factory()->create([
        'email' => 'other@e-syrians.test',
        'uuid' => 'bbbb1111-cccc-2222-dddd-333333333333',
    ]);

    // User A registers first.
    Sanctum::actingAs($otherUser);
    test()->postJson('/users/devices', [
        'subscription_id' => 'shared-device-sub',
        'platform' => 'android',
    ])->assertStatus(201);

    expect(Device::where('subscription_id', 'shared-device-sub')->first()->user_id)
        ->toBe($otherUser->id);

    // User B signs in on the same device.
    Sanctum::actingAs(test()->user);
    $response = test()->postJson('/users/devices', [
        'subscription_id' => 'shared-device-sub',
        'platform' => 'android',
    ]);

    $response->assertStatus(200);

    // Still only one row (unique on subscription_id) — but reassigned.
    expect(Device::where('subscription_id', 'shared-device-sub')->count())->toBe(1);
    expect(Device::where('subscription_id', 'shared-device-sub')->first()->user_id)
        ->toBe(test()->user->id);
});

it('rejects invalid platform values', function (): void {
    Sanctum::actingAs(test()->user);

    $response = test()->postJson('/users/devices', [
        'subscription_id' => 'os-sub-x',
        'platform' => 'web', // not in enum
    ]);

    // Status-only assertion matches the codebase convention (see
    // AuthRoutesTest). ApiService wraps validation failures in
    // `{ success: false, messages: [...] }` rather than Laravel's
    // default `errors.field:[]` shape, so
    // `assertJsonValidationErrors()` doesn't find the field-level
    // key. The 422 status alone is what matters — if the FormRequest
    // rules or the platform enum change, this test still catches it.
    $response->assertStatus(422);
});

// ─────────────────────────────────────────────────────
// DELETE /users/devices/{subscription_id} — unregister
// ─────────────────────────────────────────────────────

it('deletes an owned device with 204', function (): void {
    Sanctum::actingAs(test()->user);

    Device::create([
        'user_id' => test()->user->id,
        'subscription_id' => 'os-sub-del',
        'platform' => 'ios',
    ]);

    $response = test()->deleteJson('/users/devices/os-sub-del');

    $response->assertStatus(204);
    expect(Device::where('subscription_id', 'os-sub-del')->exists())->toBeFalse();
});

it('returns 204 (not 404) when deleting an unregistered subscription', function (): void {
    Sanctum::actingAs(test()->user);

    // No device exists with this subscription_id at all — desired end
    // state ("not registered to me") is already true, so we return
    // success rather than leak existence info via 404.
    $response = test()->deleteJson('/users/devices/ghost-sub');

    $response->assertStatus(204);
});

it('silently no-ops when deleting another user\'s device', function (): void {
    $otherUser = User::factory()->create([
        'email' => 'other2@e-syrians.test',
        'uuid' => 'cccc1111-dddd-2222-eeee-333333333333',
    ]);

    Device::create([
        'user_id' => $otherUser->id,
        'subscription_id' => 'others-sub',
        'platform' => 'android',
    ]);

    // Authenticated as test()->user — NOT the owner.
    Sanctum::actingAs(test()->user);

    $response = test()->deleteJson('/users/devices/others-sub');

    // Returns 204 to avoid leaking "this subscription_id exists for
    // someone else". The row stays in place — only the real owner
    // can delete it.
    $response->assertStatus(204);
    expect(Device::where('subscription_id', 'others-sub')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────
// User::routeNotificationForOneSignal — the channel hook
// ─────────────────────────────────────────────────────

it('exposes registered subscription IDs via routeNotificationForOneSignal', function (): void {
    Device::create([
        'user_id' => test()->user->id,
        'subscription_id' => 'sub-1',
        'platform' => 'ios',
    ]);
    Device::create([
        'user_id' => test()->user->id,
        'subscription_id' => 'sub-2',
        'platform' => 'android',
    ]);

    $route = test()->user->routeNotificationForOneSignal();

    expect($route)->toBe(['sub-1', 'sub-2']);
});

it('returns an empty array when the user has no devices', function (): void {
    expect(test()->user->routeNotificationForOneSignal())->toBe([]);
});
