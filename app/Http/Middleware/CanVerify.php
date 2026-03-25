<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\VerificationServiceContract;
use App\Services\ApiService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanVerify
{
    public function __construct(
        private readonly VerificationServiceContract $verificationService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $result = $this->verificationService->canUserVerify($user, $request->input('uuid'));

        if ($result[0] === false) {
            return ApiService::error(403, __('api.' . $result[1]));
        }

        return $next($request);
    }
}
