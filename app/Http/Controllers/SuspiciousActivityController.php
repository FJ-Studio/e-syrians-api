<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SuspiciousActivity;
use Illuminate\Support\Facades\Mail;
use App\Mail\SuspiciousActivityAlert;

class SuspiciousActivityController extends Controller
{
    /**
     * Store a suspicious activity report from the Cloud Function.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'severity' => ['required', 'in:low,medium,high'],
            'score' => ['required', 'integer', 'min:0'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string'],
            'evidence' => ['nullable', 'array'],
            'detected_at' => ['required', 'date'],
        ]);

        $activity = SuspiciousActivity::create([
            'user_id' => $validated['user_id'],
            'severity' => $validated['severity'],
            'score' => $validated['score'],
            'rules_triggered' => $validated['rules'],
            'evidence' => $validated['evidence'] ?? [],
            'detected_at' => $validated['detected_at'],
        ]);

        // Send admin email for medium and high severity
        if (in_array($validated['severity'], ['medium', 'high'])) {
            $adminEmail = config('e-syrians.admin_notification_email');

            if ($adminEmail) {
                $user = User::find($validated['user_id']);
                Mail::to($adminEmail)->queue(
                    new SuspiciousActivityAlert($activity, $user)
                );
            }
        }

        return ApiService::success(['id' => $activity->id]);
    }
}
