<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class Recaptcha
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $recaptchaToken = $request->input('recaptcha_token'); // Expecting token from frontend

        if (!$recaptchaToken) {
            return response()->json(['message' => 'reCAPTCHA token is required'], 400);
        }
        // Verify reCAPTCHA with Google
        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => config('services.recaptcha.secret'), // reCAPTCHA secret key
            'response' => $recaptchaToken,
            'remoteip' => $request->ip(),
        ]);

        $result = $response->json();

        if (!$result['success'] || $result['score'] < 0.5) { // Adjust score threshold as needed
            return response()->json(['message' => 'reCAPTCHA validation failed'], 403);
        }
        return $next($request);
    }
}
