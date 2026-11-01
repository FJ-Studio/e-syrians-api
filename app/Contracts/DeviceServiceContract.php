<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use App\Models\Device;

interface DeviceServiceContract
{
    /** @param array{installation_id:string, expo_push_token:string, platform:string, model?:?string, locale?:string} $payload */
    public function registerDevice(User $user, array $payload): Device;

    public function unregisterDevice(User $user, string $deviceUuid): void;
}
