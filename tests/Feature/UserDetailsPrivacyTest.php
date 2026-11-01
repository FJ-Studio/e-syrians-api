<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Filament\Resources\Users\Pages\ViewUser;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('omits sensitive fields from the user detail schema entirely', function (): void {
    $user = User::factory()->create();
    $page = Livewire::test(ViewUser::class, ['record' => $user->uuid])->assertSuccessful();

    foreach (['national_id', 'national_id_hashed', 'email', 'email_hashed', 'two_factor_secret'] as $field) {
        $page->assertSchemaComponentDoesNotExist($field, 'infolist');
    }
    $page->assertSchemaComponentExists('uuid', 'infolist')
        ->assertSchemaComponentExists('name', 'infolist');
});

it('does not render sensitive values on the uuid detail page or change account data', function (): void {
    $user = User::factory()->create([
        'name' => 'Details privacy test member',
        'national_id' => '98765432109',
        'email' => 'privacy-detail-member@example.test',
        'two_factor_secret' => 'PRIVATE-DETAIL-TOTP-SECRET',
    ])->fresh();

    Livewire::test(ViewUser::class, ['record' => $user->uuid])
        ->assertSuccessful()
        ->assertSee('Details privacy test member')
        ->assertDontSee('98765432109')
        ->assertDontSee('privacy-detail-member@example.test')
        ->assertDontSee($user->national_id_hashed)
        ->assertDontSee($user->email_hashed)
        ->assertDontSee('PRIVATE-DETAIL-TOTP-SECRET');

    expect($user->fresh()->national_id)->toBe('98765432109')
        ->and($user->fresh()->email)->toBe('privacy-detail-member@example.test')
        ->and($user->fresh()->two_factor_secret)->toBe('PRIVATE-DETAIL-TOTP-SECRET');
});
