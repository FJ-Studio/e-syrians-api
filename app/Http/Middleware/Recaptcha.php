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
        $route = $request->path();

        if (! $recaptchaToken) {
            Log::info('[recaptcha] missing token', ['route' => $route, 'ip' => $request->ip()]);

            return ApiService::error(400, 'recaptcha_token_required');
        }

        // Always-on entry log so it's obvious in dev whether the
        // middleware is being hit at all (matches the frontend
        // `[recaptcha] ✓ token …` line head/tail-fingerprint format
        // so you can correlate a JS console line with a backend log
        // line for the same submission).
        $tokenLen = strlen($recaptchaToken);
        $tokenHead = substr($recaptchaToken, 0, 8);
        $tokenTail = substr($recaptchaToken, -6);
        Log::info('[recaptcha] verifying token', [
            'route' => $route,
            'ip' => $request->ip(),
            'token_length' => $tokenLen,
            'token_head' => $tokenHead . '…',
            'token_tail' => '…' . $tokenTail,
            'secret_set' => ! empty(config('services.recaptcha.secret')),
        ]);

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => config('services.recaptcha.secret'),
            'response' => $recaptchaToken,
            'remoteip' => $request->ip(),
        ]);

        $result = $response->json();

        if (! is_array($result) || empty($result['success']) || ($result['score'] ?? 0) < 0.7) {
            // Surface the verdict from Google. The two most common
            // failure shapes:
            //   1. success=false + error-codes=['invalid-input-secret']
            //      → backend's RECAPTCHA_SECRET doesn't match the site
            //        key the frontend used. Check .env vs the admin
            //        console for the active key/secret pair.
            //   2. success=false + error-codes=['invalid-input-response']
            //      → token is malformed/expired/already-consumed. Check
            //        the frontend WebView is fetching fresh tokens per
            //        submit (no caching).
            //   3. success=true but score < 0.7 → Google thinks the
            //      request looks bot-ish. Lower the threshold or audit
            //      the user-agent / IP if this fires on a real user.
            Log::warning('[recaptcha] verification failed', [
                'route' => $route,
                'ip' => $request->ip(),
                'http_status' => $response->status(),
                'google_success' => $result['success'] ?? null,
                'google_score' => $result['score'] ?? null,
                'google_error_codes' => $result['error-codes'] ?? [],
                'google_hostname' => $result['hostname'] ?? null,
                'google_action' => $result['action'] ?? null,
                'result' => $result,
            ]);

            return ApiService::error(403, 'recaptcha_verification_failed');
        }

        Log::info('[recaptcha] ✓ verified', [
            'route' => $route,
            'score' => $result['score'] ?? null,
            'action' => $result['action'] ?? null,
        ]);

        return $next($request);
    }
}
