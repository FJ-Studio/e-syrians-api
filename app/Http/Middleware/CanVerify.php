<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiService;
use App\Services\UserService;
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
        $user = $request->user();
        $canAverifyB = UserService::canUserAVerifyUserB($user, $request->input('uuid'));
        if ($canAverifyB[0] === false) {
            return ApiService::error(403, $canAverifyB[1]);
        }
        return $next($request);
    }
}
