<?php

declare(strict_types=1);

use App\Models\User;

it('lists only the account’s unexpired sessions and identifies the current one', function (): void {
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->create();
    $current = $user->createToken('current');
    $other = $user->createToken('other');
    $other->accessToken->forceFill(['created_at' => now()->subMinutes(20)])->save();
    $user->devices()->create([
        'personal_access_token_id' => $other->accessToken->getKey(),
        'model' => 'Pixel 9',
        'platform' => 'android',
    ]);
    $user->createToken('expired', expiresAt: now()->subMinute());
    $user->createToken('aged-out')->accessToken->forceFill(['created_at' => now()->subHours(2)])->save();
    User::factory()->create()->createToken('foreign');

    $response = $this->getJson('/users/sessions', [
        'Authorization' => 'Bearer ' . $current->plainTextToken,
        'Accept-Language' => 'en',
    ])->assertOk()->assertJsonCount(2, 'data.sessions')
        ->assertJsonPath('data.sessions.0.id', (string) $current->accessToken->getKey())
        ->assertJsonPath('data.sessions.0.is_current', true)
        ->assertJsonPath('data.sessions.0.device', 'This device')
        ->assertJsonPath('data.sessions.1.id', (string) $other->accessToken->getKey())
        ->assertJsonPath('data.sessions.1.is_current', false)
        ->assertJsonPath('data.sessions.1.device', 'Pixel 9')
        ->assertJsonPath('data.sessions.1.last_active_at', $other->accessToken->created_at->toIso8601String());

    expect(array_keys($response->json('data.sessions.0')))->toBe(['id', 'device', 'last_active_at', 'is_current'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('ends only the selected other session owned by the authenticated account', function (): void {
    $user = User::factory()->create();
    $current = $user->createToken('current');
    $selected = $user->createToken('selected');
    $keep = $user->createToken('keep');
    $foreign = User::factory()->create()->createToken('foreign');
    $headers = ['Authorization' => 'Bearer ' . $current->plainTextToken];

    $this->deleteJson('/users/sessions/' . $current->accessToken->getKey(), [], $headers)->assertNotFound();
    $this->deleteJson('/users/sessions/' . $foreign->accessToken->getKey(), [], $headers)->assertNotFound();
    $this->deleteJson('/users/sessions/' . $selected->accessToken->getKey(), [], $headers)->assertOk();

    expect($selected->accessToken->fresh())->toBeNull()
        ->and($keep->accessToken->fresh())->not->toBeNull()
        ->and($foreign->accessToken->fresh())->not->toBeNull();
    auth()->forgetGuards();
    $this->getJson('/users/sessions', ['Authorization' => 'Bearer ' . $selected->plainTextToken])->assertUnauthorized();
    auth()->forgetGuards();
    $this->getJson('/users/sessions', $headers)->assertOk()->assertJsonCount(2, 'data.sessions');
});

it('ends all other sessions without ending the current session', function (): void {
    $user = User::factory()->create();
    $current = $user->createToken('current');
    $user->createToken('other');
    $user->createToken('third');
    $foreign = User::factory()->create()->createToken('foreign');
    $headers = ['Authorization' => 'Bearer ' . $current->plainTextToken];

    $this->deleteJson('/users/sessions', [], $headers)->assertOk();

    expect($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->getKey()])
        ->and($foreign->accessToken->fresh())->not->toBeNull();
    auth()->forgetGuards();
    $this->getJson('/users/sessions', $headers)->assertOk()->assertJsonCount(1, 'data.sessions');
});

it('requires an authenticated bearer session to manage sessions', function (): void {
    $this->getJson('/users/sessions')->assertUnauthorized();
    $this->deleteJson('/users/sessions')->assertUnauthorized();

    $user = User::factory()->create();
    $token = $user->createToken('mobile');
    $this->actingAs($user, 'web')->deleteJson('/users/sessions')->assertForbidden();
    expect($token->accessToken->fresh())->not->toBeNull();
});
