<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\AccountDeletionException;
use App\Contracts\AccountDeletionServiceContract;
use App\Http\Requests\User\CancelAccountDeletionRequest;
use App\Http\Requests\User\RequestAccountDeletionRequest;

class AccountDeletionController extends Controller
{
    public function __construct(
        private readonly AccountDeletionServiceContract $accountDeletionService,
    ) {
    }

    /**
     * GET /users/account/deletion-status
     */
    public function deletionStatus(Request $request): JsonResponse
    {
        return ApiService::success(
            $this->accountDeletionService->getDeletionStatus($request->user()),
        );
    }

    /**
     * POST /users/account/request-deletion
     */
    public function requestDeletion(RequestAccountDeletionRequest $request): JsonResponse
    {
        try {
            $status = $this->accountDeletionService->requestDeletion(
                $request->user(),
                (string) $request->input('password'),
            );

            return ApiService::success($status);
        } catch (AccountDeletionException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }

    /**
     * POST /users/account/cancel-deletion
     */
    public function cancelDeletion(CancelAccountDeletionRequest $request): JsonResponse
    {
        try {
            $status = $this->accountDeletionService->cancelDeletion(
                $request->user(),
                (string) $request->input('password'),
            );

            return ApiService::success($status);
        } catch (AccountDeletionException $e) {
            return ApiService::error($e->getCode(), $e->getMessage(), $e->getDetails());
        }
    }
}
