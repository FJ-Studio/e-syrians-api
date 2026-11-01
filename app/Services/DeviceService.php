<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Device;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Contracts\DeviceServiceContract;
use Illuminate\Database\UniqueConstraintViolationException;

class DeviceService implements DeviceServiceContract
{
    public function registerDevice(User $user, array $payload): Device
    {
        // StoreDeviceRequest requires a persistent bearer token. Recheck ownership/existence.
        $session = $user->tokens()->findOrFail($user->currentAccessToken()->id);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $session, $payload): Device {
                    $hash = hash('sha256', $payload['expo_push_token']);
                    $device = Device::query()->where('installation_id', $payload['installation_id'])
                        ->lockForUpdate()->first();

                    // The same native token must never remain routable for two installs/users.
                    // Lock the token row as well; unique indexes guard concurrent registration.
                    $previous = Device::query()->where('token_hash', $hash)->lockForUpdate()->first();
                    if ($previous && $previous->id !== $device?->id) {
                        $previous->update(['disabled_at' => now(), 'expo_push_token' => null, 'token_hash' => null]);
                    }

                    $changed = ! $device || $device->user_id !== $user->id || $device->token_hash !== $hash
                        || $device->personal_access_token_id !== $session->id || $device->disabled_at !== null;
                    $version = $device ? $device->registration_version + ($changed ? 1 : 0) : 1;
                    $device ??= new Device(['uuid' => (string) Str::uuid(), 'installation_id' => $payload['installation_id']]);
                    $device->fill(['user_id' => $user->id, 'personal_access_token_id' => $session->id,
                        'expo_push_token' => $payload['expo_push_token'], 'token_hash' => $hash,
                        'registration_version' => $version, 'platform' => $payload['platform'],
                        'model' => $payload['model'] ?? null, 'locale' => $payload['locale'] ?? 'en',
                        'disabled_at' => null, 'last_seen_at' => now()])->save();

                    return $device;
                }, 3);
            } catch (UniqueConstraintViolationException) {
                // A simultaneous first registration won the unique install/token index.
                // Retry the lookup under a new transaction instead of returning a 500.
            }
        }
        abort(409, 'Device registration changed concurrently. Please retry.');
    }

    public function unregisterDevice(User $user, string $deviceUuid): void
    {
        Device::query()->where('uuid', $deviceUuid)->where('user_id', $user->id)
            ->update(['disabled_at' => now()]);
    }
}
