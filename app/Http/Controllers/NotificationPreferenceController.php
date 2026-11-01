<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use App\Enums\NotificationCategory;
use App\Services\NotificationPreferenceService;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        $owner = $request->user()->refresh();
        return ApiService::success(['categories' => $preferences->categories($owner),
            'marketing_opt_in' => (bool) $owner->marketing_notifications_opt_in])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, NotificationPreferenceService $preferences): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(NotificationCategory::class)],
            'channel' => ['required', Rule::in(['in_app', 'push'])], 'enabled' => ['required', 'boolean'],
            'user_uuid' => ['required', 'uuid', Rule::in([$request->user()->uuid])]]);
        $categories = $preferences->update(
            $request->user(),
            NotificationCategory::from($validated['category']),
            $validated['channel'],
            (bool) $validated['enabled']
        );

        return ApiService::success(['categories' => $categories])->header('Cache-Control', 'no-store');
    }
}
