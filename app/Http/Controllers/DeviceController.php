<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Contracts\DeviceServiceContract;
use App\Http\Requests\Devices\StoreDeviceRequest;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceServiceContract $devices)
    {
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $device = $this->devices->registerDevice($request->user(), $request->validated());

        return ApiService::success(
            ['device_id' => $device->uuid, 'platform' => $device->platform],
            'device_registered',
            $device->wasRecentlyCreated ? 201 : 200
        );
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        $this->devices->unregisterDevice($request->user(), $device);

        return response()->json(null, 204);
    }
}
