<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use App\Services\DonationSettingsService;

class DonationSettingsController extends Controller
{
    public function __invoke(DonationSettingsService $settings): JsonResponse
    {
        $enabled = $settings->cryptoEnabled();

        return ApiService::success([
            'crypto_enabled' => $enabled,
            'crypto_wallets' => $enabled ? $settings->wallets() : [],
        ])->header('Cache-Control', 'no-store');
    }
}
