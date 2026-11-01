<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;

class NotificationPreferenceService
{
    /** @return array<string, array{in_app:bool, push:bool}> */
    public function categories(User $user): array
    {
        $saved = $user->notification_preferences ?? [];
        $result = [];
        foreach (NotificationCategory::cases() as $category) {
            $default = $category !== NotificationCategory::Announcements || $user->marketing_notifications_opt_in;
            foreach (['in_app', 'push'] as $channel) {
                $value = $saved[$category->value][$channel] ?? $default;
                $result[$category->value][$channel] = $value === true
                    && ($category !== NotificationCategory::Announcements || $user->marketing_notifications_opt_in);
            }
        }
        return $result;
    }

    public function allows(User $user, NotificationCategory $category, string $channel): bool
    {
        return $this->categories($user)[$category->value][$channel];
    }

    /** @return array<string, array{in_app:bool, push:bool}> */
    public function update(User $user, NotificationCategory $category, string $channel, bool $enabled): array
    {
        return DB::transaction(function () use ($user, $category, $channel, $enabled): array {
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $categories = $this->categories($owner);
            $categories[$category->value][$channel] = $enabled;
            $changes = ['notification_preferences' => $categories];
            if ($category === NotificationCategory::Announcements) {
                $optIn = $categories['announcements']['in_app'] || $categories['announcements']['push'];
                $changes['marketing_notifications_opt_in'] = $optIn;
                $changes['marketing_notifications_consented_at'] = $optIn
                    ? ($owner->marketing_notifications_consented_at ?? now()) : null;
            }
            $owner->forceFill($changes)->save();
            return $this->categories($owner);
        });
    }
}
