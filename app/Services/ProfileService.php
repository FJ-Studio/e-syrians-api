<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileUploadServiceContract;
use App\Contracts\ProfileServiceContract;
use App\Enums\ProfileChangeTypeEnum;
use App\Exceptions\UpdateLimitReachedException;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ProfileService implements ProfileServiceContract
{
    public function __construct(
        private readonly FileUploadServiceContract $fileUploadService,
    ) {}

    public function updateBasicInfo(User $user, array $data): void
    {
        $limit = config('e-syrians.verification.basic_info_updates_limit');

        if ($user->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value) >= $limit) {
            throw new UpdateLimitReachedException('basic_info_updates_limit_reached');
        }

        $user->update($data);

        $user->profileUpdates()->create([
            'change_type' => ProfileChangeTypeEnum::BasicData->value,
            'meta_data' => [],
        ]);

        // Cancel all active verifications when basic info changes
        $user->verifications()
            ->whereNull('cancelled_at')
            ->update([
                'cancelled_at' => now(),
                'cancelation_payload' => [
                    'reason' => 'user_updated_basic_info',
                ],
            ]);

        $user->markAsUnverified();
    }

    public function updateSocialLinks(User $user, array $data): void
    {
        $user->update($data);
    }

    public function updateAvatar(User $user, UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension();
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (! in_array(strtolower($ext), $allowedExtensions)) {
            throw new \InvalidArgumentException('invalid_file_type');
        }

        $fileName = $user->uuid . '.' . $ext;

        // Delete old avatar if it exists
        if (! empty($user->avatar)) {
            $this->fileUploadService->delete($user->avatar);
        }

        // Upload new avatar
        $path = $this->fileUploadService->upload($file, 'avatars', $fileName);

        // Update user avatar path
        $user->avatar = $path;
        $user->save();

        return $this->fileUploadService->url($path);
    }

    public function updateAddress(User $user, array $data, ?string $ipAddress, ?string $userAgent): void
    {
        $limit = config('e-syrians.verification.country_updates_limit');

        if ($user->getAddressUpdatesCount() >= $limit) {
            throw new UpdateLimitReachedException('country_updates_limit_reached');
        }

        $user->update($data);

        $profileUpdate = $user->profileUpdates()->make([
            'change_type' => ProfileChangeTypeEnum::Address->value,
            'meta_data' => [
                'country' => $data['country'],
                'city_inside_syria' => $data['city_inside_syria'],
            ],
        ]);
        $profileUpdate->ip_address = $ipAddress;
        $profileUpdate->user_agent = $userAgent;
        $profileUpdate->save();
    }

    public function updateCensusData(User $user, array $data): void
    {
        $data['languages'] = implode(',', $data['languages'] ?? []);
        $data['other_nationalities'] = implode(',', $data['other_nationalities'] ?? []);

        $user->update($data);
    }

    public function changeEmail(User $user, string $email): void
    {
        $user->email = $email;
        $user->email_verified_at = null;
        $user->save();
        $user->sendEmailVerificationNotification();
    }

    public function updateNotifications(User $user, array $preferences): void
    {
        $user->received_verification_email = $preferences['received_verification_email'];
        $user->account_verified_email = $preferences['account_verified_email'];
        $user->save();
    }

    public function updateLanguage(User $user, string $language): void
    {
        $user->language = $language;
        $user->save();
    }
}
