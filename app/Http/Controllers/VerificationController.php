<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Exception;
use DomainException;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\User\VerifyUserRequest;
use App\Contracts\VerificationServiceContract;

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
            Log::error('Verification failed: ' . $e->getMessage(), [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ApiService::error(500, $e->getMessage());
        }
    }

    public function myVerifications(Request $request): JsonResponse
    {
        $verifications = $this->verificationService->getVerificationsForUser($request->user());

        return ApiService::success($verifications);
    }

    public function myVerifiers(Request $request): JsonResponse
    {
        $verifiers = $this->verificationService->getVerifiersForUser($request->user());

        return ApiService::success($verifiers);
    }
}
