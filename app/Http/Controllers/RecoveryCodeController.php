<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use App\Services\RecoveryCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecoveryCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->recovery_codes)) {
            $user->update([
                'recovery_codes' => RecoveryCodeService::generateCodes(),
            ]);
        }

        return ApiService::success([
            'recovery_codes' => $user->recovery_codes,
        ]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        $recoveryCodes = RecoveryCodeService::generateCodes();

        $user->update([
            'recovery_codes' => $recoveryCodes,
        ]);

        return ApiService::success([
            'recovery_codes' => $recoveryCodes,
        ]);
    }
}
