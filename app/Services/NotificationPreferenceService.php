<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Enums\NotificationCategory;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceService
{
    private const array EMAIL_COLUMNS = ['verifications' => 'received_verification_email', 'account_status' => 'account_verified_email'];

    /** @return array<string, array{in_app:bool, push:bool, email?:bool}> */
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
            if (isset(self::EMAIL_COLUMNS[$category->value])) {
                $result[$category->value]['email'] = (bool) $user->{self::EMAIL_COLUMNS[$category->value]};
            }
        }
        return $result;
    }

    public function allows(User $user, NotificationCategory $category, string $channel): bool
    {
        return $this->categories($user)[$category->value][$channel] ?? false;
    }

    /** @return array<string, array{in_app:bool, push:bool, email?:bool}> */
    public function update(User $user, NotificationCategory $category, string $channel, bool $enabled): array
    {
        if (! in_array($channel, ['in_app', 'push'], true)
            && ! ($channel === 'email' && isset(self::EMAIL_COLUMNS[$category->value]))) {
            throw ValidationException::withMessages(['channel' => ['notification_channel_unavailable']]);
        }
        return DB::transaction(function () use ($user, $category, $channel, $enabled): array {
            $owner = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $categories = $this->categories($owner);
            $categories[$category->value][$channel] = $enabled;
            // Keep the legacy email columns authoritative so older clients remain compatible.
            foreach (array_keys($categories) as $name) {
                unset($categories[$name]['email']);
            }
            $changes = ['notification_preferences' => $categories];
            if ($channel === 'email') {
                $changes[self::EMAIL_COLUMNS[$category->value]] = $enabled;
            }
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
