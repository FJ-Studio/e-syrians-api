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
