<?php

use App\Mail\WeeklyNewsletter;
use App\Models\FeatureRequest;
use App\Models\Poll;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): void {
    abort(404);
});

/**
 * Preview the weekly newsletter email.
 *
 * GET /newsletter/preview?locale=en
 *
 * Only available in non-production environments.
 */
Route::get('/newsletter/preview', function () {
    if (app()->environment('production')) {
        abort(404);
    }

    $locale = request()->query('locale', 'ar');
    $since = Carbon::now()->subDays(7);

    $polls = Poll::withoutGlobalScopes()
        ->where('is_private', false)
        ->where('audience_only', false)
        ->where('created_at', '>=', $since)
        ->whereNull('deleted_at')
        ->with('options')
        ->orderBy('created_at', 'desc')
        ->get();

    $featureRequests = FeatureRequest::where('created_at', '>=', $since)
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'desc')
        ->get();

    return new WeeklyNewsletter(
        polls: $polls,
        featureRequests: $featureRequests,
        userLocale: $locale,
    );
});
