<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Laravel\Sanctum\Sanctum;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use App\Models\IdentitySandboxCredit;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Contracts\IdentitySandboxServiceContract;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('grants one private account-bound credit from Filament without changing the free claim or verification badge', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create()->assignRole('admin');
    $recipient = User::factory()->create();
    $other = User::factory()->create();
    config([
        'identity-sandbox.enabled' => true,
        'identity-sandbox.test_users' => [(string) $recipient->uuid, (string) $other->uuid],
    ]);
    Http::preventStrayRequests();
    $this->actingAs($admin);
    $page = Livewire::test(ViewUser::class, ['record' => $recipient->uuid]);
    $page->assertActionVisible('grantVerificationCredit')
        ->callAction('grantVerificationCredit', data: ['reason' => 'Pilot credit for the test account'])
        ->assertHasNoActionErrors()->assertNotified('Sandbox verification credit granted');

    $credit = IdentitySandboxCredit::query()->sole();
    $this->assertDatabaseHas('identity_sandbox_credits', [
        'uuid' => $credit->uuid,
        'owner_id' => $recipient->id,
        'issued_to_user_id' => null,
        'granted_by_user_id' => $admin->id,
        'grant_reason' => 'Pilot credit for the test account',
        'source' => IdentitySandboxCredit::SOURCE_ADMIN_GRANT,
        'status' => 'available',
    ]);
    expect($recipient->fresh()->verified_at)->toBeNull();

    Sanctum::actingAs($other);
    $code = $this->postJson('/users/identity-sandbox/gift-code')->assertOk()->json('data.code');
    Sanctum::actingAs($recipient);
    $this->getJson('/users/identity-sandbox')->assertOk()
        ->assertJsonPath('data.can_claim', true)
        ->assertJsonPath('data.credits.0.source', 'admin_grant')
        ->assertJsonPath('data.credits.0.can_gift', false)
        ->assertDontSee('Pilot credit for the test account');
    $this->postJson("/users/identity-sandbox/{$credit->uuid}/gift", ['code' => $code])->assertConflict();
    $this->postJson('/users/identity-sandbox/claim')->assertOk()->assertJsonCount(2, 'data.credits');
    Http::assertNothingSent();
});

it('denies sandbox grants by non-admins, for non-allowlisted accounts, and in production', function (): void {
    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    config(['identity-sandbox.enabled' => true, 'identity-sandbox.test_users' => [(string) $user->uuid]]);
    $service = app(IdentitySandboxServiceContract::class);

    expect(fn () => $service->grant($user, $user, 'Not allowed'))->toThrow(HttpException::class);
    expect(fn () => $service->grant($admin, $outsider, 'Not allowlisted'))->toThrow(HttpException::class);
    app()->instance('env', 'production');
    expect(fn () => $service->grant($admin, $user, 'Not sandbox'))->toThrow(HttpException::class);
    $this->assertDatabaseCount('identity_sandbox_credits', 0);
});
