<?php

declare(strict_types=1);

use App\Http\Middleware\Recaptcha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.recaptcha.secret', 'test-secret');
});

function runRecaptchaMiddleware(array $input): \Symfony\Component\HttpFoundation\Response
{
    $request = Request::create('/test', 'POST', $input);

    return (new Recaptcha)->handle($request, fn ($req) => response()->json(['ok' => true]));
}

test('rejects requests without a recaptcha_token', function (): void {
    $response = runRecaptchaMiddleware([]);

    expect($response->getStatusCode())->toBe(400);
});

test('rejects requests when Google reports failure', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => false, 'score' => 0.9], 200),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'bad-token']);

    expect($response->getStatusCode())->toBe(403);
});

test('rejects requests with a low score', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.3], 200),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'low-score-token']);

    expect($response->getStatusCode())->toBe(403);
});

test('allows requests with a valid high-score token', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.9], 200),
    ]);

    $response = runRecaptchaMiddleware(['recaptcha_token' => 'good-token']);

    expect($response->getStatusCode())->toBe(200);
});

test('forwards secret, token and remote IP to siteverify', function (): void {
    Http::fake([
        'www.google.com/*' => Http::response(['success' => true, 'score' => 0.9], 200),
    ]);

    runRecaptchaMiddleware(['recaptcha_token' => 'my-token']);

    Http::assertSent(function ($request): bool {
        return str_contains((string) $request->url(), 'siteverify')
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'my-token'
            && array_key_exists('remoteip', $request->data());
    });
});
