<x-filament-panels::page>
    <x-filament::section>
        <p>Platform-wide Laravel Pennant flags. A notification is delivered only when both its channel here and the recipient's preference are enabled. Push also requires device consent.</p>
        <p>Expo push transport is currently <strong>{{ app(\App\Services\ExpoPushService::class)->isEnabled() ? 'enabled' : 'disabled' }}</strong> by its global Pennant flag. Change the master switch on <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\ManageFeatureFlags::getUrl() }}">Feature flags</a>; no environment-variable edit is required.</p>
        <p>Changes apply to future deliveries and queued push jobs. Existing inbox entries are kept; suppressed events are not replayed when re-enabled. Security Activity, OTPs and password-recovery emails are unaffected.</p>
    </x-filament::section>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
            Save delivery settings
        </x-filament::button>
    </form>
</x-filament-panels::page>
