<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Laravel\Pennant\Feature;
use Illuminate\Bus\Queueable;
use App\Enums\NotificationEvent;
use App\Models\IdentitySandboxCredit;
use App\Models\IdentityVerificationCredit;
use App\Notifications\PlatformNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\IdentityVerificationService;
use App\Services\PlatformNotificationService;
use App\Contracts\IdentitySandboxServiceContract;

/** Resolves the recipient from the committed credit, never from a gift code. */
class NotifyIdentityCreditRecipient implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public NotificationEvent $event, public int $creditId, public string $mode = 'sandbox')
    {
    }

    public function handle(IdentitySandboxServiceContract $sandbox, PlatformNotificationService $notifications): void
    {
        Feature::flushCache();
        $live = $this->mode === 'live';
        $credit = ($live ? IdentityVerificationCredit::query() : IdentitySandboxCredit::query())
            ->find($this->creditId, ['owner_id', 'source', 'gifted_at', 'status']);
        if (! $credit) {
            return;
        }

        // Admin credits are account-bound. A claimed credit can be gifted only once.
        $matches = match ($this->event) {
            NotificationEvent::IdentityCreditGranted => $credit->source === IdentitySandboxCredit::SOURCE_ADMIN_GRANT,
            NotificationEvent::IdentityCreditGifted => $credit->isTransferableSource()
                && $credit->gifted_at !== null && $credit->status !== 'revoked',
            default => false,
        };
        if (! $matches) {
            return;
        }

        $recipient = User::query()->find($credit->owner_id);
        $wallet = $live ? resolve(IdentityVerificationService::class) : $sandbox;
        if ($recipient && $wallet->allowed($recipient)) {
            $dedupeKey = $live ? 'live:'.$this->creditId : (string) $this->creditId;
            $notifications->send($recipient, new PlatformNotification($this->event->value, null), $dedupeKey);
        }
    }
}
