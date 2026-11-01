<x-filament-panels::page>
    <x-filament::section>
        <p>These are global, database-backed Laravel Pennant flags. Changes apply to subsequent requests and queued jobs without editing environment variables or redeploying.</p>
        <p>Credentials, connection settings, authentication, security monitoring, and device/user consent remain separate. No credentials are displayed or editable here.</p>
        <p>
            Configure individual notification events and channels on
            <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\ManageNotificationDelivery::getUrl() }}">Notification delivery</a>.
            Configure public donation wallets on
            <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\ManageDonations::getUrl() }}">Donations</a>.
        </p>
    </x-filament::section>

    {{ $this->form }}
    {{ $this->saveAction }}
</x-filament-panels::page>
