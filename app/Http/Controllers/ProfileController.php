<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ProfileServiceContract;
use App\Exceptions\UpdateLimitReachedException;
use App\Http\Requests\User\UpdateSocialLinksRequest;
use App\Http\Requests\User\UpdateUserAddressRequest;
use App\Http\Requests\User\UpdateUserAvatarRequest;
use App\Http\Requests\User\UpdateUserBasicInfoRequest;
use App\Http\Requests\User\UpdateUserCensusDataRequest;
use App\Services\ApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileServiceContract $profileService,
    ) {}

    public function updateBasicInfo(UpdateUserBasicInfoRequest $request): JsonResponse
    {
        try {
            $this->profileService->updateBasicInfo(
                $request->user(),
                $request->validated(),
            );

            return ApiService::success([]);
        } catch (UpdateLimitReachedException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    public function updateSocialLinks(UpdateSocialLinksRequest $request): JsonResponse
    {
        $this->profileService->updateSocialLinks(
            $request->user(),
            $request->validated(),
        );

        return ApiService::success([]);
    }

    public function updateAvatar(UpdateUserAvatarRequest $request): JsonResponse
    {
        try {
            $url = $this->profileService->updateAvatar(
                $request->user(),
                $request->file('avatar'),
            );

            return ApiService::success(['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            return ApiService::error(422, $e->getMessage());
        }
    }

    public function updateAddress(UpdateUserAddressRequest $request): JsonResponse
    {
        try {
            $this->profileService->updateAddress(
                $request->user(),
                $request->validated(),
                $request->ip(),
                $request->userAgent(),
            );

            return ApiService::success([]);
        } catch (UpdateLimitReachedException $e) {
            return ApiService::error($e->getCode(), $e->getMessage());
        }
    }

    public function updateCensus(UpdateUserCensusDataRequest $request): JsonResponse
    {
        $this->profileService->updateCensusData(
            $request->user(),
            $request->validated(),
        );

        return ApiService::success([]);
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
        ]);

        $this->profileService->changeEmail(
            $request->user(),
            $request->input('email'),
        );

        return ApiService::success([], __('api.email_changed'));
    }

    public function changeNotifications(Request $request): JsonResponse
    {
        $request->validate([
            'received_verification_email' => 'required|boolean',
            'account_verified_email' => 'required|boolean',
        ]);

        $this->profileService->updateNotifications(
            $request->user(),
            $request->only('received_verification_email', 'account_verified_email'),
        );

        return ApiService::success([], __('api.notifications_changed'));
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $request->validate([
            'language' => 'required|in:' . implode(',', array_map(
                fn ($lang) => $lang->value,
                \App\Enums\SysLanguageEnum::cases()
            )),
        ]);

        $this->profileService->updateLanguage(
            $request->user(),
            $request->input('language'),
        );

        return ApiService::success([], __('api.language_updated'));
    }
}
