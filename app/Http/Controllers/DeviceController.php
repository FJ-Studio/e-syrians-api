<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\ApiService;
// (Device import kept — still used inside `store()` for the pre-flight
// "did this row exist before updateOrCreate" check, which drives the
// 201 vs 200 status distinction.)
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Contracts\DeviceServiceContract;
use App\Http\Requests\Devices\StoreDeviceRequest;

/**
 * Per CLAUDE.md: controllers stay thin. The mutating logic
 * (subscription dedup, cross-user re-link) lives in
 * `DeviceService::registerDevice`. Authentication is enforced
 * by the route's `auth:sanctum` middleware.
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceServiceContract $devices,
    ) {
    }

    /**
     * Register (or re-register) the current user's push device.
     *
     * Returns `200 OK` for a re-register, `201 Created` for a fresh
     * insert. Mobile treats both as success. Body echoes the
     * canonical row so the client can confirm what landed.
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $existed = Device::query()
            ->where('subscription_id', $request->validated('subscription_id'))
            ->exists();

        $device = $this->devices->registerDevice(
            $request->user(),
            $request->validated(),
        );

        return ApiService::success(
            [
                'subscription_id' => $device->subscription_id,
                'platform' => $device->platform,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
            'device_registered',
            $existed ? 200 : 201,
        );
    }

    /**
     * Unregister a device. The route takes a RAW string
     * `{subscription_id}` (NOT `{device:subscription_id}` implicit
     * binding) because we return 204 whether or not the device
     * exists — implicit binding would 404 first, which would leak
     * "yes, this subscription_id is registered to someone" through
     * a status code.
     *
     * Spec contract, all end in 204:
     *   - Device is owned by the authed user → delete, 204.
     *   - Device belongs to a different user → no-op, 204
     *     (leaks nothing; can't be used to log another user out).
     *   - Device doesn't exist at all → no-op, 204
     *     (mobile treats "not registered" as the desired end state).
     */
    public function destroy(Request $request, string $subscription_id): JsonResponse
    {
        // The service is scoped by user_id — it only deletes when the
        // device actually belongs to the authed user. Missing devices
        // and cross-user devices both fall through as no-ops.
        $this->devices->unregisterDevice(
            $request->user(),
            $subscription_id,
        );

        return ApiService::success(null, '', 204);
    }
}
