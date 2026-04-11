<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\Request;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use App\Services\TwoFactorService;
use App\Http\Resources\UserResource;
use App\Services\TwoFactorChallengeService;
use App\Http\Requests\TwoFactor\VerifyTwoFactorRequest;
use App\Http\Requests\TwoFactor\ConfirmTwoFactorRequest;
use App\Http\Requests\TwoFactor\DisableTwoFactorRequest;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly AuthService $authService,
    ) {
    }

    /**
     * Get the current 2FA status for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiService::success([
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
        ]);
    }

    /**
     * Initialize 2FA setup - generates secret and QR code.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return ApiService::error(400, __('2fa.setup.error.already_enabled'));
        }

        $setupData = $this->twoFactorService->setupTwoFactor($user);

        return ApiService::success([
            'secret' => $setupData['secret'],
            'qr_code' => $setupData['qr_code'],
        ]);
    }

    /**
     * Confirm 2FA setup by verifying the code from the authenticator app.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return ApiService::error(400, __('2fa.confirm.error.already_enabled'));
        }

        if (empty($user->two_factor_secret)) {
            return ApiService::error(400, __('2fa.confirm.error.not_setup'));
        }

        $code = $request->validated('code');

        if (! $this->twoFactorService->confirmTwoFactor($user, $code)) {
            return ApiService::error(400, __('2fa.confirm.error.invalid_code'));
        }

        return ApiService::success([
            'enabled' => true,
            'recovery_codes' => $user->recovery_codes,
        ]);
    }

    /**
     * Disable 2FA for the authenticated user.
     */
    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return ApiService::error(400, __('2fa.disable.error.not_enabled'));
        }

        $code = $request->validated('code');
        $isRecoveryCode = (bool) $request->validated('is_recovery_code', false);

        if (! $this->twoFactorService->verifyTwoFactorCode($user, $code, $isRecoveryCode)) {
            return ApiService::error(400, __('2fa.confirm.error.invalid_code'));
        }

        $this->twoFactorService->disableTwoFactor($user);

        return ApiService::success([
            'enabled' => false,
        ]);
    }

    /**
     * Verify 2FA code during login and complete authentication.
     */
    public function verify(VerifyTwoFactorRequest $request): JsonResponse
    {
        $challengeToken = $request->validated('challenge_token');
        $code = $request->validated('code');
        $isRecoveryCode = (bool) $request->validated('is_recovery_code', false);

        // Verify and consume the challenge
        $challengeData = TwoFactorChallengeService::verifyChallenge($challengeToken);

        if ($challengeData === null) {
            return ApiService::error(400, __('2fa.verify.error.invalid_challenge'));
        }

        $user = User::find($challengeData['user_id']);

        if ($user === null) {
            return ApiService::error(400, __('2fa.verify.error.user_not_found'));
        }

        // Verify the 2FA code
        if (! $this->twoFactorService->verifyTwoFactorCode($user, $code, $isRecoveryCode)) {
            return ApiService::error(400, __('2fa.verify.error.invalid_code'));
        }

        // Complete the login — create a Sanctum token
        $plainToken = $user->createToken(date('Y-m-d-H:i:s'))->plainTextToken;

        return ApiService::success([
            'user' => (new UserResource($user))->additional(['isOwner' => true]),
            'token' => explode('|', $plainToken)[1],
        ]);
    }
}
