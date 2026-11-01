<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Laravel\Pennant\Feature;
use Filament\Facades\Filament;
use App\Models\DonationSetting;
use App\Filament\Pages\ManageDonations;
use App\Services\DonationSettingsService;

function donationWallet(array $overrides = []): array
{
    return array_replace([
        'name' => 'Bitcoin',
        'currency' => 'BTC',
        'network' => null,
        'address' => 'bc1qtestreceivingaddress',
    ], $overrides);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('defaults crypto donations to off for public visitors', function (): void {
    $this->getJson('/donations/settings')
        ->assertOk()
        ->assertJsonPath('data.crypto_enabled', false)
        ->assertJsonPath('data.crypto_wallets', [])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('never exposes configured wallets while disabled', function (): void {
    resolve(DonationSettingsService::class)->save(false, [donationWallet()]);

    $this->getJson('/donations/settings')
        ->assertOk()
        ->assertJsonPath('data.crypto_enabled', false)
        ->assertJsonPath('data.crypto_wallets', [])
        ->assertDontSee('bc1qtestreceivingaddress');

    expect(resolve(DonationSettingsService::class)->wallets())->toHaveCount(1);
});

it('uses the same persistent global flag for guests and signed-in users', function (): void {
    $wallets = [donationWallet()];
    resolve(DonationSettingsService::class)->save(true, $wallets);

    // Resolving fresh must use the database value, not a per-request override.
    Feature::flushCache();
    $this->getJson('/donations/settings')
        ->assertOk()
        ->assertJsonPath('data.crypto_enabled', true)
        ->assertJsonPath('data.crypto_wallets', $wallets);

    $user = User::factory()->create();
    Feature::for($user)->deactivate(DonationSettingsService::CRYPTO_FEATURE);
    $this->actingAs($user, 'sanctum')->getJson('/donations/settings')
        ->assertOk()
        ->assertJsonPath('data.crypto_enabled', true)
        ->assertJsonPath('data.crypto_wallets', $wallets);

    Feature::for(DonationSettingsService::FEATURE_SCOPE)->deactivate(DonationSettingsService::CRYPTO_FEATURE);
    $this->getJson('/donations/settings')
        ->assertJsonPath('data.crypto_enabled', false)
        ->assertJsonPath('data.crypto_wallets', []);
});

it('returns no wallets if the feature is enabled before wallets are configured', function (): void {
    Feature::for(DonationSettingsService::FEATURE_SCOPE)->activate(DonationSettingsService::CRYPTO_FEATURE);

    $this->getJson('/donations/settings')
        ->assertOk()
        ->assertJsonPath('data.crypto_enabled', true)
        ->assertJsonPath('data.crypto_wallets', []);
});

it('requires a signed-in admin to access donation settings', function (): void {
    $this->get(ManageDonations::getUrl())->assertRedirect();

    $this->actingAs(User::factory()->create())
        ->get(ManageDonations::getUrl())
        ->assertForbidden();
});

it('allows admins to edit currencies networks addresses and their order from Filament', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $wallets = [
        donationWallet(['name' => 'Tether', 'currency' => 'usdt', 'network' => 'TRC-20', 'address' => 'TRontestaddress']),
        donationWallet(['name' => 'Litecoin', 'currency' => 'ltc', 'address' => 'ltc1qtestaddress']),
    ];

    Livewire::test(ManageDonations::class)
        ->fillForm(['crypto_enabled' => true, 'wallets' => $wallets])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Donation settings saved');

    Feature::flushCache();
    $this->getJson('/donations/settings')
        ->assertJsonPath('data.crypto_enabled', true)
        ->assertJsonPath('data.crypto_wallets.0.name', 'Tether')
        ->assertJsonPath('data.crypto_wallets.0.currency', 'USDT')
        ->assertJsonPath('data.crypto_wallets.0.network', 'TRC-20')
        ->assertJsonPath('data.crypto_wallets.0.address', 'TRontestaddress')
        ->assertJsonPath('data.crypto_wallets.1.currency', 'LTC');

    // A second save updates the singleton and removes deleted wallets.
    Livewire::test(ManageDonations::class)
        ->fillForm(['crypto_enabled' => false, 'wallets' => [donationWallet()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(DonationSetting::query()->count())->toBe(1)
        ->and(resolve(DonationSettingsService::class)->wallets())->toEqual([donationWallet()]);
    $this->getJson('/donations/settings')->assertJsonPath('data.crypto_wallets', []);
});

it('does not permit enabling crypto donations with no wallets', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ManageDonations::class)
        ->fillForm(['crypto_enabled' => true, 'wallets' => []])
        ->call('save')
        ->assertHasFormErrors(['wallets']);

    expect(resolve(DonationSettingsService::class)->cryptoEnabled())->toBeFalse();
});

it('validates wallet fields without saving or enabling the feature', function (string $field, mixed $value): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ManageDonations::class)
        ->fillForm(['crypto_enabled' => true, 'wallets' => [donationWallet([$field => $value])]])
        ->call('save')
        ->assertHasFormErrors();

    expect(DonationSetting::query()->count())->toBe(0)
        ->and(resolve(DonationSettingsService::class)->cryptoEnabled())->toBeFalse();
})->with([
    'missing currency name' => ['name', ''],
    'invalid currency symbol' => ['currency', 'BTC / ETH'],
    'missing address' => ['address', ''],
    'whitespace in address' => ['address', 'not a public address'],
    'URL instead of address' => ['address', 'https://example.com/wallet'],
    'oversized network' => ['network', str_repeat('a', 61)],
]);

it('rechecks admin authorization before every Livewire save', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $page = Livewire::test(ManageDonations::class)
        ->fillForm(['crypto_enabled' => true, 'wallets' => [donationWallet()]]);

    $admin->removeRole('admin');
    $page->call('save')->assertForbidden();

    expect(DonationSetting::query()->count())->toBe(0)
        ->and(resolve(DonationSettingsService::class)->cryptoEnabled())->toBeFalse();
});
