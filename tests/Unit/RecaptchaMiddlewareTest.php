<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use App\Http\Middleware\Recaptcha;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    // Enterprise needs the four-field config block. The middleware
    // bails with a 500 when any of them is missing.
    config()->set('services.recaptcha.project_id', 'test-project');
    config()->set('services.recaptcha.api_key', 'test-api-key');
    config()->set('services.recaptcha.site_key', 'test-site-key');
    config()->set('services.recaptcha.min_score', 0.7);
});

function runRecaptchaMiddleware(array $input): Response
{
    $request = Request::create('/test', 'POST', $input);

    return (new Recaptcha())->handle($request, fn ($req) => response()->json(['ok' => true]));
}

/**
 * Shape of a successful Enterprise Assessments response. Each test
 * tweaks the bits it cares about and merges with this baseline.
 */
function recaptchaResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'projects/test-project/assessments/abc123',
        'event' => [
            'token' => 'test-token',
            'siteKey' => 'test-site-key',
        ],
        'riskAnalysis' => [
            'score' => 0.9,
            'reasons' => [],
        ],
        'tokenProperties' => [
            'valid' => true,
            'invalidReason' => 'INVALID_REASON_UNSPECIFIED',
            'hostname' => 'localhost',
            'action' => 'forgot_password',
            'createTime' => '2026-06-20T14:14:21Z',
        ],
    ], $overrides);
}

test('rejects requests without a recaptcha_token', function (): void {
    $response = runRecaptchaMiddleware([]);

    expect($response->getStatusCode())->toBe(400);
});

test('returns 500 when the middleware is misconfigured', function (): void {
    config()->set('services.recaptcha.project_id', null);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'whatever']);

    expect($response->getStatusCode())->toBe(500);
});

test('rejects requests when tokenProperties.valid is false', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(
            recaptchaResponse([
                'tokenProperties' => ['valid' => false, 'invalidReason' => 'EXPIRED'],
            ]),
            200,
        ),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'expired-token']);

    expect($response->getStatusCode())->toBe(403);
});

test('rejects requests with a low risk score', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(
            recaptchaResponse(['riskAnalysis' => ['score' => 0.3, 'reasons' => ['AUTOMATION']]]),
            200,
        ),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'low-score-token']);

    expect($response->getStatusCode())->toBe(403);
});

test('rejects when Google returns a non-2xx error envelope', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(
            ['error' => ['code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT']],
            400,
        ),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'any-token']);

    expect($response->getStatusCode())->toBe(403);
});

test('allows requests with a valid high-score token', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(recaptchaResponse(), 200),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'good-token']);

    expect($response->getStatusCode())->toBe(200);
});

test('posts the token + siteKey to the Enterprise Assessments endpoint', function (): void {
    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(recaptchaResponse(), 200),
    ]);

    runRecaptchaMiddleware(['recaptcha_token' => 'my-token']);

    Http::assertSent(function ($request): bool {
        $url = (string) $request->url();

        return str_contains($url, 'recaptchaenterprise.googleapis.com')
            && str_contains($url, 'projects/test-project/assessments')
            && str_contains($url, 'key=test-api-key')
            && ($request['event']['token'] ?? null) === 'my-token'
            && ($request['event']['siteKey'] ?? null) === 'test-site-key';
    });
});

test('respects a custom min_score config value', function (): void {
    config()->set('services.recaptcha.min_score', 0.4);

    Http::fake([
        'recaptchaenterprise.googleapis.com/*' => Http::response(
            recaptchaResponse(['riskAnalysis' => ['score' => 0.5]]),
            200,
        ),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'borderline-token']);

    expect($response->getStatusCode())->toBe(200);
});
