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
     * Verifies the `recaptcha_token` from the request body against Google
     * Cloud's reCAPTCHA Enterprise Assessments API. The frontend uses
     * `grecaptcha.enterprise.execute(...)` (see mobile + web recaptcha
     * modules) — tokens from that path FAIL with `browser-error` when
     * sent to the legacy `siteverify` endpoint, so we use the proper
     * Assessments endpoint here.
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

        $projectId = config('services.recaptcha.project_id');
        $apiKey = config('services.recaptcha.api_key');
        $siteKey = config('services.recaptcha.site_key');
        $minScore = (float) config('services.recaptcha.min_score', 0.7);

        // Misconfiguration check — fail loudly in dev, not silently
        // letting Google return some ambiguous error. Production should
        // have all four set in `.env` (see config/services.php).
        if (! $projectId || ! $apiKey || ! $siteKey) {
            Log::error('[recaptcha] middleware misconfigured', [
                'route' => $route,
                'project_id_set' => (bool) $projectId,
                'api_key_set' => (bool) $apiKey,
                'site_key_set' => (bool) $siteKey,
            ]);

            return ApiService::error(500, 'recaptcha_misconfigured');
        }

        // Always-on entry log. Matches the frontend's `[recaptcha] ✓ token`
        // line head/tail fingerprint so you can correlate a JS console
        // line with a backend log line for the same submission.
        $tokenLen = strlen($recaptchaToken);
        $tokenHead = substr($recaptchaToken, 0, 8);
        $tokenTail = substr($recaptchaToken, -6);
        Log::info('[recaptcha] verifying token', [
            'route' => $route,
            'ip' => $request->ip(),
            'token_length' => $tokenLen,
            'token_head' => $tokenHead . '…',
            'token_tail' => '…' . $tokenTail,
            'project_id' => $projectId,
        ]);

        // POST to Enterprise Assessments. The `event` payload requires:
        //   - token:    the value returned by grecaptcha.enterprise.execute
        //   - siteKey:  the site key the token was generated for (must
        //               match the key set in mobile / web env)
        // Optional fields we DON'T set today:
        //   - expectedAction: per-route action gate. We'd need to
        //                     parameterize the middleware (`recaptcha:login`
        //                     etc.) to validate it usefully. Tracked as a
        //                     follow-up; the action is still surfaced by
        //                     Google for risk analytics either way.
        //   - userIpAddress, userAgent, ja3: marginal lift, skip for now.
        $endpoint = "https://recaptchaenterprise.googleapis.com/v1/projects/{$projectId}/assessments?key={$apiKey}";
        $response = Http::asJson()->post($endpoint, [
            'event' => [
                'token' => $recaptchaToken,
                'siteKey' => $siteKey,
            ],
        ]);

        $result = $response->json();
        $tokenProps = is_array($result) ? ($result['tokenProperties'] ?? []) : [];
        $riskAnalysis = is_array($result) ? ($result['riskAnalysis'] ?? []) : [];
        $tokenValid = (bool) ($tokenProps['valid'] ?? false);
        $score = $riskAnalysis['score'] ?? null;

        $verified = $response->successful()
            && $tokenValid
            && is_numeric($score)
            && $score >= $minScore;

        if (! $verified) {
            // Surface the verdict from Google. Common failure shapes:
            //   - tokenProperties.valid=false +
            //     invalidReason=MALFORMED|EXPIRED|DUPE|MISSING|BROWSER_ERROR
            //   - tokenProperties.valid=true, riskAnalysis.score < min_score
            //     → bot-ish; check riskAnalysis.reasons for the verdict
            //     drivers (AUTOMATION / UNEXPECTED_USAGE_PATTERNS / etc.)
            //   - HTTP 4xx with `error.message` → API key / project_id /
            //     site_key mismatch. Read the message to find which one.
            Log::warning('[recaptcha] verification failed', [
                'route' => $route,
                'ip' => $request->ip(),
                'http_status' => $response->status(),
                'token_valid' => $tokenValid,
                'invalid_reason' => $tokenProps['invalidReason'] ?? null,
                'token_action' => $tokenProps['action'] ?? null,
                'token_hostname' => $tokenProps['hostname'] ?? null,
                'score' => $score,
                'min_score' => $minScore,
                'risk_reasons' => $riskAnalysis['reasons'] ?? [],
                'api_error' => is_array($result) ? ($result['error'] ?? null) : null,
                'result' => $result,
            ]);

            return ApiService::error(403, 'recaptcha_verification_failed');
        }

        Log::info('[recaptcha] ✓ verified', [
            'route' => $route,
            'score' => $score,
            'action' => $tokenProps['action'] ?? null,
            'hostname' => $tokenProps['hostname'] ?? null,
        ]);

        return $next($request);
    }
}
