<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use App\Models\IdentitySandboxCredit;
use App\Jobs\NotifyIdentityCreditRecipient;
use App\Contracts\IdentitySandboxServiceContract;

const IDENTITY_CREDIT_HISTORY = '/users/identity-sandbox/history';

beforeEach(function (): void {
    config(['identity-sandbox.enabled' => true]);
    Bus::fake([NotifyIdentityCreditRecipient::class]);
});

it('limits the credit history to authenticated, allowlisted sandbox accounts', function (): void {
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertNotFound();
    config(['identity-sandbox.test_users' => [(string) $user->uuid]]);
    $this->getJson(IDENTITY_CREDIT_HISTORY.'?page=0')->assertUnprocessable();
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.items', []);
});

it('records claims, grants and both sides of a gift without exposing any counterpart or credit identifiers', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    [$giver, $receiver, $other] = User::factory()->count(3)->create()->all();
    config(['identity-sandbox.test_users' => [(string) $giver->uuid, (string) $receiver->uuid, (string) $other->uuid]]);
    $sandbox = resolve(IdentitySandboxServiceContract::class);
    $sandbox->claim($giver);
    $sandbox->claim($giver);
    $credit = IdentitySandboxCredit::query()->where('owner_id', $giver->id)->sole();
    $sandbox->grant($admin, $receiver, 'Private grant reason');
    $code = $sandbox->giftCode($receiver)['code'];
    $sandbox->gift($giver, $credit->uuid, $code);

    Sanctum::actingAs($receiver);
    $receiverHistory = $this->getJson(IDENTITY_CREDIT_HISTORY.'?user_id='.$giver->id)
        ->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.event', 'gift_received')
        ->assertJsonPath('data.items.1.event', 'admin_granted')
        ->assertDontSee('Private grant reason')->assertDontSee($code)->assertDontSee($credit->uuid);
    expect(array_keys($receiverHistory->json('data.items.0')))->toBe(['id', 'event', 'occurred_at']);
    Sanctum::actingAs($giver);
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.event', 'gift_sent')->assertJsonPath('data.items.1.event', 'claimed');
    Sanctum::actingAs($other);
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertOk()->assertJsonPath('data.items', []);
});

it('records the giver of a seeded credit and rolls back the gift and both history entries together', function (): void {
    [$giver, $receiver] = User::factory()->count(2)->create()->all();
    config(['identity-sandbox.test_users' => [(string) $giver->uuid, (string) $receiver->uuid]]);
    $sandbox = resolve(IdentitySandboxServiceContract::class);
    $credit = IdentitySandboxCredit::query()->create(['uuid' => (string) Str::uuid(), 'owner_id' => $giver->id]);
    $code = $sandbox->giftCode($receiver)['code'];

    expect(fn () => DB::transaction(function () use ($sandbox, $giver, $credit, $code): void {
        $sandbox->gift($giver, $credit->uuid, $code);
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    $this->assertDatabaseCount('identity_sandbox_credit_events', 0);
    expect($credit->refresh()->owner_id)->toBe($giver->id);
    $sandbox->gift($giver, $credit->uuid, $code);
    expect($sandbox->history($giver, 1)['items'][0]['event'])->toBe('gift_sent')
        ->and($sandbox->history($receiver, 1)['items'][0]['event'])->toBe('gift_received');
});

it('returns newer credit events first and bounds pages to ten items', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $user->uuid]]);
    $sandbox = resolve(IdentitySandboxServiceContract::class);
    $sandbox->claim($user);
    for ($i = 0; $i < 10; $i++) {
        $sandbox->grant($admin, $user, 'History pagination test');
    }
    Sanctum::actingAs($user);
    $this->getJson(IDENTITY_CREDIT_HISTORY)->assertOk()->assertJsonCount(10, 'data.items')
        ->assertJsonPath('data.page', 1)->assertJsonPath('data.last_page', 2)
        ->assertJsonPath('data.items.9.event', 'admin_granted');
    $this->getJson(IDENTITY_CREDIT_HISTORY.'?page=2')->assertOk()->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.page', 2)->assertJsonPath('data.items.0.event', 'claimed');
});
