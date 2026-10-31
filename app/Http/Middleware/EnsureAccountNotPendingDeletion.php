<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to block authenticated actions while a user has a pending
 * account-deletion request in flight.
 *
 * During the 15-day grace period the user is still able to
 *   - view their deletion status
 *   - cancel the deletion
 *   - logout
 *
 * Every other authenticated route is locked. The 403 response includes
 * both timestamps in the `data` block — the mobile app's `use-request`
 * hook reads that shape to update the local user state and render the
 * pending-deletion banner without an extra `/users/me` round-trip.
 */
class EnsureAccountNotPendingDeletion
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->hasPendingDeletion()) {
            return ApiService::error(
                403,
                'you_are_pending_deletion',
                [
                    'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
                    'deletion_scheduled_for' => $user->deletion_scheduled_for?->toIso8601String(),
                ],
            );
        }

        return $next($request);
    }
}
