<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Filament\Resources\Users\Pages\ListUsers;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('omits sensitive user columns entirely rather than making them toggleable', function (): void {
    $table = Livewire::test(ListUsers::class)->assertSuccessful();

    foreach (['national_id', 'national_id_hashed', 'email', 'email_hashed', 'two_factor_secret'] as $column) {
        $table->assertTableColumnDoesNotExist($column);
    }
    $table->assertTableColumnExists('uuid')->assertTableColumnExists('name');
});

it('does not render removed values while preserving the underlying user data', function (): void {
    $user = User::factory()->create([
        'name' => 'Table privacy test member',
        'national_id' => '98765432101',
        'email' => 'privacy-table-member@example.test',
        'two_factor_secret' => 'PRIVATE-TABLE-TOTP-SECRET',
    ])->fresh();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertDontSee('98765432101')
        ->assertDontSee('privacy-table-member@example.test')
        ->assertDontSee($user->national_id_hashed)
        ->assertDontSee($user->email_hashed)
        ->assertDontSee('PRIVATE-TABLE-TOTP-SECRET');

    expect($user->fresh()->national_id)->toBe('98765432101')
        ->and($user->fresh()->email)->toBe('privacy-table-member@example.test')
        ->and($user->fresh()->two_factor_secret)->toBe('PRIVATE-TABLE-TOTP-SECRET');
});

it('does not search removed identifiers through the users table', function (string $identifier): void {
    $user = User::factory()->create([
        'name' => 'Table privacy search member',
        'national_id' => '87654321098',
        'email' => 'privacy-search-member@example.test',
    ]);

    Livewire::test(ListUsers::class)->searchTable($identifier)->assertCanNotSeeTableRecords([$user]);
})->with(['87654321098', 'privacy-search-member@example.test']);
