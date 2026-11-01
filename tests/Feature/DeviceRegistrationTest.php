<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Device;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->headers = authHeader($this->user);
    $this->payload = ['user_uuid' => $this->user->uuid, 'installation_id' => (string) Str::uuid(),
        'expo_push_token' => 'ExponentPushToken[test_registration_token]', 'platform' => 'ios', 'consent' => true];
});

it('requires bearer authentication and explicit owner-bound consent', function (): void {
    $this->postJson('/users/devices', $this->payload)->assertUnauthorized();
    $this->postJson('/users/devices', [...$this->payload, 'consent' => false], $this->headers)->assertUnprocessable();
    $this->postJson('/users/devices', [...$this->payload, 'user_uuid' => (string) Str::uuid()], $this->headers)->assertUnprocessable();
});

it('encrypts tokens and returns only a public device id', function (): void {
    $response = $this->postJson('/users/devices', $this->payload, $this->headers)->assertCreated();
    $device = Device::where('installation_id', $this->payload['installation_id'])->firstOrFail();
    expect($response->json('data.device_id'))->toBe($device->uuid)
        ->and($response->getContent())->not->toContain($this->payload['expo_push_token'])
        ->and(DB::table('devices')->where('id', $device->id)->value('expo_push_token'))->not->toBe($this->payload['expo_push_token'])
        ->and($device->expo_push_token)->toBe($this->payload['expo_push_token'])
        ->and($device->toArray())->not->toHaveKey('expo_push_token');
});

it('registers idempotently and versions account or token changes', function (): void {
    $this->postJson('/users/devices', $this->payload, $this->headers)->assertCreated();
    $this->postJson('/users/devices', $this->payload, $this->headers)->assertOk();
    $device = Device::where('installation_id', $this->payload['installation_id'])->firstOrFail();
    expect($device->registration_version)->toBe(1);
    $other = User::factory()->create();
    $this->app['auth']->forgetGuards();
    $this->postJson('/users/devices', [...$this->payload, 'user_uuid' => $other->uuid], authHeader($other))->assertOk();
    expect($device->fresh()->user_id)->toBe($other->id)->and($device->fresh()->registration_version)->toBe(2);
});

it('moves a token to one installation and prevents cross-account unregister', function (): void {
    $id = $this->postJson('/users/devices', $this->payload, $this->headers)->json('data.device_id');
    $other = User::factory()->create();
    $headers = authHeader($other);
    $this->app['auth']->forgetGuards();
    $otherId = $this->postJson('/users/devices', [...$this->payload, 'user_uuid' => $other->uuid,
        'installation_id' => (string) Str::uuid()], $headers)->assertCreated()->json('data.device_id');
    expect(Device::where('uuid', $id)->firstOrFail()->disabled_at)->not->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->deleteJson('/users/devices/'.$otherId, [], $this->headers)->assertNoContent();
    expect(Device::where('uuid', $otherId)->firstOrFail()->disabled_at)->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->deleteJson('/users/devices/'.$otherId, [], $headers)->assertNoContent();
    $this->deleteJson('/users/devices/'.$otherId, [], $headers)->assertNoContent();
    expect(Device::where('uuid', $otherId)->firstOrFail()->disabled_at)->not->toBeNull();
});

it('rejects malformed tokens and unsupported platforms', function (): void {
    foreach ([['expo_push_token' => 'https://attacker.test/secret'], ['platform' => 'web']] as $bad) {
        $this->postJson('/users/devices', [...$this->payload, ...$bad], $this->headers)->assertUnprocessable();
    }
});

it('makes devices unroutable for expired revoked or pending-deletion sessions', function (): void {
    $this->postJson('/users/devices', $this->payload, $this->headers)->assertCreated();
    $device = Device::where('installation_id', $this->payload['installation_id'])->firstOrFail();
    expect(Device::routable()->whereKey($device->id)->exists())->toBeTrue();
    $device->sessionToken->update(['expires_at' => now()->subMinute()]);
    expect(Device::routable()->whereKey($device->id)->exists())->toBeFalse();
    $device->sessionToken->update(['expires_at' => null]);
    $this->user->forceFill(['deletion_scheduled_for' => now()->addDay()])->save();
    expect(Device::routable()->whereKey($device->id)->exists())->toBeFalse();
    $device->sessionToken->delete();
    expect($device->fresh())->toBeNull();
});
