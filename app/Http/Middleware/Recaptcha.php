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

        if (! $recaptchaToken) {
            return ApiService::error(400, 'recaptcha_token_required');
        }

        $projectId = config('services.recaptcha.project_id');
        $apiKey = config('services.recaptcha.api_key');
        $siteKey = config('services.recaptcha.site_key');
        $minScore = (float) config('services.recaptcha.min_score', 0.7);

        // Guardrail: log loudly if any of the four env vars is missing.
        // This is a deployment bug (someone shipped without setting env),
        // not a per-request diagnostic — kept so production catches it
        // instead of letting Google return some ambiguous error.
        if (! $projectId || ! $apiKey || ! $siteKey) {
            Log::error('[recaptcha] middleware misconfigured', [
                'route' => $request->path(),
                'project_id_set' => (bool) $projectId,
                'api_key_set' => (bool) $apiKey,
                'site_key_set' => (bool) $siteKey,
            ]);

            return ApiService::error(500, 'recaptcha_misconfigured');
        }

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

        // TEMP DIAGNOSTIC (re-added 2026-06-20): on any rejection, log the
        // exact reason Google returned so we can tell apart EXPIRED
        // (slow client / slow backend), SITE_MISMATCH (FE/BE key drift),
        // DUPE (replay), low-score (bot), and HTTP-level failures
        // (quota / auth). Strip this block once production is healthy.
        if (! $verified) {
            Log::warning('[recaptcha] verification failed', [
                'route' => $request->path(),
                'http_status' => $response->status(),
                'http_successful' => $response->successful(),
                'token_valid' => $tokenValid,
                'invalid_reason' => $tokenProps['invalidReason'] ?? null,
                'token_action' => $tokenProps['action'] ?? null,
                'token_hostname' => $tokenProps['hostname'] ?? null,
                'token_create_time' => $tokenProps['createTime'] ?? null,
                'score' => $score,
                'min_score' => $minScore,
                'token_prefix' => substr((string) $recaptchaToken, 0, 10),
                'token_length' => strlen((string) $recaptchaToken),
                'configured_site_key_prefix' => substr((string) $siteKey, 0, 10),
                'configured_project_id' => $projectId,
                'google_body_excerpt' => is_array($result)
                    ? json_encode(array_intersect_key($result, array_flip(['tokenProperties', 'riskAnalysis', 'error'])))
                    : substr((string) $response->body(), 0, 500),
            ]);

            return ApiService::error(403, 'recaptcha_verification_failed');
        }

        return $next($request);
    }
}
