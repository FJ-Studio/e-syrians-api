<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Str;
use App\Enums\PlatformFeature;
use App\Models\FeatureRequest;
use App\Models\UserVerification;
use App\Services\FeatureFlagService;
use App\Services\SecurityEventService;
use App\Jobs\FanOutPlatformNotificationJob;

class PlatformNotificationObserver
{
    public function created(User|Poll|FeatureRequest|UserVerification $model): void
    {
        if ($model instanceof UserVerification) {
            $this->dispatch('verification_received', $model->id);
        } elseif ($model instanceof Poll && ! $model->is_private && $model->start_date <= now()) {
            $this->dispatch('poll_opened', $model->id);
        }
    }

    public function updated(User|Poll|FeatureRequest|UserVerification $model): void
    {
        if ($model instanceof User) {
            if ($model->wasChanged('verified_at')) {
                $this->dispatch($model->verified_at ? 'account_verified' : 'account_unverified', $model->id, (string) Str::uuid());
            }
            if ($model->wasChanged('password')) {
                resolve(SecurityEventService::class)->record(
                    $model,
                    $model->getRawOriginal('password') ? 'security_password_changed' : 'security_password_set'
                );
            }
            if ($model->wasChanged('two_factor_enabled')
                && (bool) $model->getRawOriginal('two_factor_enabled') !== $model->two_factor_enabled) {
                resolve(SecurityEventService::class)->record(
                    $model,
                    $model->two_factor_enabled ? 'security_two_factor_enabled' : 'security_two_factor_disabled'
                );
            }
        } elseif ($model instanceof UserVerification && $model->wasChanged('cancelled_at') && $model->cancelled_at !== null) {
            $this->dispatch('verification_revoked', $model->id);
        } elseif ($model instanceof FeatureRequest && $model->wasChanged(['coded_at', 'tested_at', 'deployed_at'])) {
            $before = new FeatureRequest();
            $before->setRawAttributes($model->getRawOriginal());
            if ($before->status !== $model->status) {
                $this->dispatch('feature_'.$model->status, $model->id);
            }
        }
    }

    public function deleted(User|Poll|FeatureRequest|UserVerification $model): void
    {
        if ($model instanceof UserVerification) {
            $this->dispatch('verification_revoked', $model->id);
        }
    }

    private function dispatch(string $event, int $id, ?string $sourceKey = null): void
    {
        if (resolve(FeatureFlagService::class)->enabled(PlatformFeature::NotificationEvents)) {
            dispatch(new FanOutPlatformNotificationJob($event, $id, sourceKey: $sourceKey))->afterCommit();
        }
    }
}
