<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocalization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');
        // get supported languages from config
        $supportedLanguages = config('e-syrians.locales');
        // check if the requested language is supported
        if (! in_array($locale, $supportedLanguages)) {
            $locale = config('app.fallback_locale');
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
