<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expected = config('services.internal_api_key');

        if (! $token || ! $expected || ! hash_equals($expected, $token)) {
            return ApiService::error(401, 'Unauthorized');
        }

        return $next($request);
    }
}
