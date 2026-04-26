<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Contracts\PasswordServiceContract;

class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordServiceContract $passwordService,
    ) {
    }

    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required'],
            'new_password' => ['required', 'confirmed', 'min:8', 'max:255', 'different:current_password'],
        ]);

        $result = $this->passwordService->changePassword(
            $request->user(),
            $request->input('current_password'),
            $request->input('new_password'),
        );

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }

    public function sendSetupOtp(Request $request): JsonResponse
    {
        $result = $this->passwordService->sendSetupOtp($request->user());

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }

    public function setPassword(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'new_password' => ['required', 'string', 'confirmed', 'min:8', 'max:255'],
        ]);

        $result = $this->passwordService->setPasswordWithOtp(
            $request->user(),
            $request->input('otp'),
            $request->input('new_password'),
        );

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $result = $this->passwordService->sendResetLink(
            $request->input('email'),
        );

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8', 'max:255'],
        ]);

        $result = $this->passwordService->resetPassword(
            $request->input('email'),
            $request->input('token'),
            $request->input('password'),
        );

        if (! $result['success']) {
            return ApiService::error($result['code'], $result['message']);
        }

        return ApiService::success([], $result['message']);
    }
}
