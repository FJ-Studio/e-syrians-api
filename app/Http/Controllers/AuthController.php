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
use App\Http\Requests\User\CheckEmailAvailabilityRequest;

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

    /**
     * Pre-registration email-availability probe.
     *
     * Lets the mobile sign-up wizard tell the user up-front (on step 1)
     * that the email they just typed is already taken, instead of
     * letting them fill the remaining five steps and then bouncing off
     * the unique-email validator inside `register()`.
     *
     * Security shape:
     *   - `guest`-only — signed-in users have no business probing.
     *   - `recaptcha` middleware gates the call so the endpoint can't
     *     be turned into a cheap "is this email registered?" oracle
     *     by an attacker scanning a list.
     *   - `throttle:10,1,email_check` caps to 10 probes per minute
     *     per IP. A genuine user fat-fingering an email a few times
     *     is fine; sustained probing trips the limiter.
     *
     * Lookup goes through `AuthServiceContract::isEmailAvailable()`
     * so the controller stays loosely coupled to the User model
     * (matches the project's controller-uses-contracts arch rule
     * enforced by `tests/Unit/ArchTest.php`).
     *
     * Response: `{ available: bool }`. Always 200 when the format
     * passes validation; format failures come back as standard 422
     * via Laravel's request-validation wiring (no special-casing).
     */
    public function checkEmailAvailability(CheckEmailAvailabilityRequest $request): JsonResponse
    {
        $available = $this->authService->isEmailAvailable(
            (string) $request->input('email'),
        );

        return ApiService::success(['available' => $available]);
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
            $request->input('name'),
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
