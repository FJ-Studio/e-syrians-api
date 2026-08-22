<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use App\Exceptions\UpdateLimitReachedException;

interface ProfileServiceContract
{
    /**
     * Update user's basic info and cancel verifications
     *
     * @throws UpdateLimitReachedException
     */
    public function updateBasicInfo(User $user, array $data): void;

    /**
     * Update user's social media links
     */
    public function updateSocialLinks(User $user, array $data): void;

    /**
     * Upload and update user's avatar
     *
     * @return string The new avatar URL
     */
    public function updateAvatar(User $user, UploadedFile $file): string;

    /**
     * Update user's address with profile tracking
     *
     * @throws UpdateLimitReachedException
     */
    public function updateAddress(User $user, array $data, ?string $ipAddress, ?string $userAgent): void;

    /**
     * Update user's census data
     */
    public function updateCensusData(User $user, array $data): void;

    /**
     * Change user's email and trigger re-verification
     */
    public function changeEmail(User $user, string $email): void;

    /**
     * Update user's notification preferences
     */
    public function updateNotifications(User $user, array $preferences): void;

    /**
     * Update user's preferred language
     */
    public function updateLanguage(User $user, string $language): void;
}
