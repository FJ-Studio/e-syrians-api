<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class Recaptcha
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $recaptchaToken = $request->input('recaptcha_token');

        if (! $recaptchaToken) {
            return ApiService::error(400, 'recaptcha_token_required');
        }
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => config('services.recaptcha.secret'),
            'response' => $recaptchaToken,
            'remoteip' => $request->ip(),
        ]);

        $result = $response->json();

        if (! is_array($result) || empty($result['success']) || ($result['score'] ?? 0) < 0.7) {
            // Log the verdict from Google so we can tell at a glance
            // whether the failure was a site-key mismatch
            // (success=false + 'error-codes': ['invalid-input-secret']
            //  or 'invalid-input-response') vs a low score (success=true
            // but score below the threshold). Production should NEVER
            // hit this branch with a real user, so the volume stays
            // low and we get genuine signal when it does fire.
            Log::warning('recaptcha verification failed', [
                'route' => $request->path(),
                'ip' => $request->ip(),
                'result' => $result,
            ]);

            return ApiService::error(403, 'recaptcha_verification_failed');
        }

        return $next($request);
    }
}
