<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;

class SecurityEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = SecurityEvent::query()->where('user_id', $request->user()->id)->latest('id')->paginate(15);
        $events->through(fn (SecurityEvent $event): array => [
            'id' => $event->uuid, 'event' => $event->event, 'created_at' => $event->created_at->toIso8601String(),
            'device' => $event->device_info, 'ip' => $event->ip_address, 'new_device' => $event->new_device,
        ]);
        return ApiService::success($events)->header('Cache-Control', 'no-store');
    }
}
