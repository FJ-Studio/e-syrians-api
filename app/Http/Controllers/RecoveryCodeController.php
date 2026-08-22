<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\RecoveryCodeService;

class RecoveryCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->recovery_codes)) {
            RecoveryCodeService::issueFor($user);
        }

        return ApiService::success([
            'recovery_codes' => $user->recovery_codes,
            'total' => $user->recovery_codes_total,
        ]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        $recoveryCodes = RecoveryCodeService::issueFor($user);

        return ApiService::success([
            'recovery_codes' => $recoveryCodes,
            'total' => count($recoveryCodes),
        ]);
    }
}
