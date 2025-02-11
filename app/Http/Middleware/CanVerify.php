<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanVerify
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. user should not be banned

        // 2. user should be verified

        // 3. user cannot verify more than the count of verifications he got

        // 4. circular verification is not allowed, a user cannot verify another user who verified him

        // 5. user cannot verify himself

        return $next($request);
    }
}
