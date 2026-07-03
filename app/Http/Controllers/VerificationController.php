<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Exception;
use DomainException;
use App\Services\ApiService;
use Illuminate\Http\Request;
use App\Models\UserVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\User\VerifyUserRequest;
use App\Contracts\VerificationServiceContract;
use App\Http\Resources\UserVerificationResource;

class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationServiceContract $verificationService,
    ) {
    }

    public function verify(VerifyUserRequest $request): JsonResponse
    {
        try {
            $this->verificationService->verifyUser(
                $request->user(),
                $request->input('uuid'),
                $request->ip(),
                $request->userAgent(),
            );

            return ApiService::success([]);
        } catch (DomainException $e) {
            return ApiService::error(403, $e->getMessage());
        } catch (Exception $e) {
            Log::error('Verification failed: '.$e->getMessage(), [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ApiService::error(500, $e->getMessage());
        }
    }

    public function myVerifications(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request);
        $page = $this->verificationService->getVerificationsForUser($request->user(), $perPage);

        // Match the pagination response shape UserPollController
        // uses for /users/my-polls: a flat object with a named
        // items array plus current/last/per/total. Mobile + web
        // both consume this shape.
        return ApiService::success([
            'verifications' => UserVerificationResource::collection($page->items()),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function myVerifiers(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request);
        $page = $this->verificationService->getVerifiersForUser($request->user(), $perPage);

        return ApiService::success([
            'verifiers' => UserVerificationResource::collection($page->items()),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    /**
     * Clamp client-supplied `per_page` into a sane range.
     *
     * `(int) $request->query('per_page', 25)` blindly cast even
     * 0 and absurdly large values through to Laravel's paginator,
     * where `per_page=0` triggers a divide-by-zero in the
     * `ceil($total / $perPage)` last-page calculation, and large
     * values bypass any practical limit on response size. Page
     * is similarly clamped (>=1) inside Laravel itself, so we
     * don't need to mirror that here.
     */
    private function normalizePerPage(Request $request, int $default = 25, int $max = 100): int
    {
        $raw = (int) $request->query('per_page', (string) $default);
        if ($raw < 1) {
            return $default;
        }
        return min($raw, $max);
    }

    /**
     * Cancel a verification the auth user previously sent.
     *
     * Soft-cancels: sets `cancelled_at` + records the reason in
     * `cancelation_payload`. The row stays in the DB so the
     * audit trail (verifier history, fraud-detection signals)
     * survives. Active-only list endpoints already filter
     * `whereNull('cancelled_at')`.
     *
     * 403 if the auth user isn't the original verifier or if the
     * row is already cancelled — both surface as DomainException
     * with a translatable message key.
     */
    public function cancel(Request $request, UserVerification $verification): JsonResponse
    {
        try {
            $updated = $this->verificationService->cancelVerificationByVerifier(
                $request->user(),
                $verification,
            );

            // Eager-load the target user for the response so the
            // mobile/web list can update the row inline without
            // refetching the whole list.
            $updated->load(['user' => fn ($q) => $q->select('id', 'uuid', 'name', 'middle_name', 'surname', 'avatar')]);

            return ApiService::success(new UserVerificationResource($updated));
        } catch (DomainException $e) {
            return ApiService::error(403, $e->getMessage());
        }
    }
}
