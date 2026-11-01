<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\NotificationAnnouncement;
use App\Services\PlatformNotificationService;

class AnnouncementController extends Controller
{
    public function show(Request $request, string $announcement): JsonResponse
    {
        // Permit push-only recipients without creating an unwanted inbox entry.
        $id = PlatformNotificationService::deliveryId($request->user(), 'announcement', $announcement);
        $received = DB::table('notification_deliveries')->where('id', $id)->where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->where('in_app', true)->orWhere('push', true))->exists();
        abort_unless($received, 404);
        $record = NotificationAnnouncement::findOrFail($announcement);
        return ApiService::success(['id' => $record->uuid, 'title' => $record->title, 'body' => $record->body,
            'created_at' => $record->created_at->toIso8601String()])->header('Cache-Control', 'no-store');
    }
}
