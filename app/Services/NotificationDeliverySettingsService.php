<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Pennant\Feature;
use App\Enums\NotificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NotificationDeliverySettingsService
{
    public const string FEATURE_SCOPE = 'global';

    public const array CHANNELS = ['in_app', 'push', 'email'];

    /** @return list<string> */
    public static function channelsFor(NotificationEvent $event): array
    {
        return match ($event) {
            NotificationEvent::VerificationReceived, NotificationEvent::AccountVerified => self::CHANNELS,
            NotificationEvent::IdentityCreditGranted, NotificationEvent::IdentityCreditGifted => ['in_app'],
            default => ['in_app', 'push'],
        };
    }

    public static function featureName(NotificationEvent $event, string $channel): string
    {
        return 'notifications.'.$event->value.'.'.$channel;
    }

    public static function defineFeatures(): void
    {
        foreach (NotificationEvent::cases() as $event) {
            foreach (self::channelsFor($event) as $channel) {
                // Preserve existing routing; user consent and the Expo transport switch still apply.
                Feature::define(self::featureName($event, $channel), true);
            }
        }
    }

    public function allows(?string $event, string $channel): bool
    {
        $notificationEvent = NotificationEvent::tryFrom($event ?? '');
        if (! $notificationEvent || ! in_array($channel, self::channelsFor($notificationEvent), true)) {
            return false;
        }

        return Feature::for(self::FEATURE_SCOPE)->value(self::featureName($notificationEvent, $channel)) === true;
    }

    /** @return array<string, array{in_app: bool, push?: bool, email?: bool}> */
    public function matrix(): array
    {
        $features = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (self::channelsFor($event) as $channel) {
                $features[] = self::featureName($event, $channel);
            }
        }
        Feature::for(self::FEATURE_SCOPE)->loadMissing($features);

        $matrix = [];
        foreach (NotificationEvent::cases() as $event) {
            $channels = ['in_app' => $this->allows($event->value, 'in_app')];
            foreach (['push', 'email'] as $channel) {
                if (in_array($channel, self::channelsFor($event), true)) {
                    $channels[$channel] = $this->allows($event->value, $channel);
                }
            }
            $matrix[$event->value] = $channels;
        }

        return $matrix;
    }

    /** @param array<string, array{in_app: bool, push?: bool, email?: bool}> $events */
    public function save(array $events): void
    {
        $rules = ['events' => ['required', 'array:'.implode(',', array_column(NotificationEvent::cases(), 'value'))]];
        foreach (NotificationEvent::cases() as $event) {
            $rules['events.'.$event->value] = ['required', 'array:'.implode(',', self::channelsFor($event))];
            foreach (self::channelsFor($event) as $channel) {
                $rules['events.'.$event->value.'.'.$channel] = ['required', 'boolean'];
            }
        }
        Validator::make(['events' => $events], $rules)->validate();

        DB::transaction(function () use ($events): void {
            foreach (NotificationEvent::cases() as $event) {
                foreach (self::channelsFor($event) as $channel) {
                    Feature::for(self::FEATURE_SCOPE)->activate(
                        self::featureName($event, $channel),
                        (bool) $events[$event->value][$channel],
                    );
                }
            }
        });
    }
}
