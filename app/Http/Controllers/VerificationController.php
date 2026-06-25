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
        $perPage = (int) $request->query('per_page', 25);
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
        $perPage = (int) $request->query('per_page', 25);
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
