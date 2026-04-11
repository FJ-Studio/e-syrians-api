<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\UserResource;
use App\Contracts\AuthServiceContract;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\SocialLoginRequest;
use App\Http\Requests\User\UserEmailVerification;
use App\Http\Requests\User\CredentialsLoginRequest;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthServiceContract $authService,
    ) {
    }

    public function register(UserStoreRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return ApiService::success(new UserResource($user), '', 201);
    }

    public function login(CredentialsLoginRequest $request): JsonResponse
    {
        $result = $this->authService->authenticateViaCredentials(
            $request->input('identifier'),
            $request->input('password'),
        );

        if (! $result) {
            return ApiService::error(401);
        }

        // If 2FA is required, return the challenge instead of the full login response
        if (! empty($result['requires_2fa'])) {
            return ApiService::success([
                'requires_2fa' => true,
                'challenge_token' => $result['challenge_token'],
                'expires_at' => $result['expires_at'],
            ]);
        }

        return ApiService::success([
            'user' => (new UserResource($result['user']))->additional(['isOwner' => true]),
            'token' => $result['token'],
        ]);
    }

    public function socialLogin(SocialLoginRequest $request): JsonResponse
    {
        $result = $this->authService->authenticateViaSocialProvider(
            $request->provider,
            $request->token,
        );

        if (! $result) {
            return ApiService::error(401);
        }

        return ApiService::success([
            'user' => (new UserResource($result['user']))->additional(['isOwner' => true]),
            'token' => $result['token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiService::success([], 'logged_out');
    }

    public function verifyEmail(UserEmailVerification $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->authService->verifyEmail(
            (int) $data['id'],
            $data['hash'],
            $request->fullUrl(),
        );

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }

    public function getEmailVerificationLink(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiService::error(403, 'user_already_verified');
        }

        $user->sendEmailVerificationNotification();

        return ApiService::success([], 'verification_email_sent');
    }
}
