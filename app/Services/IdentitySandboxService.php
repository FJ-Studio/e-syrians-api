<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Str;
use App\Enums\PlatformFeature;
use Illuminate\Support\Carbon;
use App\Enums\NotificationEvent;
use Illuminate\Support\Facades\DB;
use App\Models\IdentitySandboxCredit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use App\Jobs\NotifyIdentityCreditRecipient;
use App\Contracts\IdentitySandboxServiceContract;

class IdentitySandboxService implements IdentitySandboxServiceContract
{
    private const ACTIVE = ['creating', 'pending', 'in_review'];

    public function __construct(protected readonly DiditSandbox $didit)
    {
    }

    public function summary(User $user): array
    {
        return [
            'price_minor' => 300,
            'currency' => 'USD',
            'provider_ready' => $this->didit->configured(),
            'can_claim' => $this->canClaim($user),
            'credits' => $this->credits($user)->latest('id')->get()->map(fn (IdentitySandboxCredit $credit): array => [
                'uuid' => $credit->uuid,
                'status' => $credit->status,
                'source' => $credit->source,
                'can_gift' => $credit->canGift(),
            ])->values()->all(),
        ];
    }

    public function history(User $user, int $page): array
    {
        $history = DB::table($this->ledgerTable())->where('user_id', $user->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(10, ['id', 'event', 'occurred_at'], 'page', $page);

        return [
            'items' => $history->getCollection()->map(static fn (object $entry): array => [
                'id' => (int) $entry->id,
                'event' => (string) $entry->event,
                'occurred_at' => Carbon::parse($entry->occurred_at)->toIso8601String(),
            ])->values()->all(),
            'page' => $history->currentPage(),
            'last_page' => $history->lastPage(),
        ];
    }

    public function canGrant(User $admin, User $recipient): bool
    {
        return $admin->hasRole('admin') && ! $admin->trashed() && ! $admin->hasPendingDeletion()
            && $this->allowed($recipient) && ! $recipient->trashed() && ! $recipient->hasPendingDeletion();
    }

    public function grant(User $admin, User $recipient, string $reason): void
    {
        abort_unless($this->canGrant($admin, $recipient), 403);
        $reason = Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:240']])->validate()['reason'];

        DB::transaction(function () use ($admin, $recipient, $reason): void {
            $lockedRecipient = User::query()->lockForUpdate()->findOrFail($recipient->id);
            abort_unless($this->canGrant($admin, $lockedRecipient), 403);

            $credit = $this->newCredit();
            $credit->forceFill([
                'uuid' => (string) Str::uuid(),
                'owner_id' => $lockedRecipient->id,
                'source' => IdentitySandboxCredit::SOURCE_ADMIN_GRANT,
                'granted_by_user_id' => $admin->id,
                'grant_reason' => $reason,
            ])->save();
            $this->recordCreditEvent($lockedRecipient, $credit, 'admin_granted', now());
            $this->notifyRecipient(NotificationEvent::IdentityCreditGranted, $credit);
        });
    }

    public function claim(User $user): void
    {
        abort_unless($this->mode() === 'sandbox', 404);
        DB::transaction(function () use ($user): void {
            $this->lockUser($user);
            $credit = IdentitySandboxCredit::query()->firstOrCreate(['issued_to_user_id' => $user->id], [
                'uuid' => (string) Str::uuid(),
                'owner_id' => $user->id,
            ]);
            if ($credit->wasRecentlyCreated) {
                $this->recordCreditEvent($user, $credit, 'claimed', now());
            }
        });
    }

    public function giftCode(User $user): array
    {
        $code = Str::random(32);
        $expiresAt = now()->addMinutes(30);
        DB::transaction(function () use ($user, $code, $expiresAt): void {
            $this->lockUser($user);
            DB::table($this->giftTable())->updateOrInsert(['user_id' => $user->id], [
                'code_hash' => hash('sha256', $code),
                'expires_at' => $expiresAt,
            ]);
        });

        return ['code' => $code, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function gift(User $user, string $credit, string $code): void
    {
        DB::transaction(function () use ($user, $credit, $code): void {
            $entry = $this->credits($user)->where('uuid', $credit)->lockForUpdate()->firstOrFail();
            abort_unless($entry->canGift(), 409, 'identity_sandbox_credit_not_giftable');

            $gift = DB::table($this->giftTable())->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            $recipient = $gift && Carbon::parse($gift->expires_at)->isFuture() ? User::query()->find($gift->user_id) : null;
            abort_unless($recipient instanceof User && $recipient->id !== $user->id
                && $this->allowed($recipient) && ! $recipient->hasPendingDeletion()
                && ! UserBlock::between($user->id, $recipient->id), 422, 'identity_sandbox_gift_code_invalid');

            $giftedAt = now();
            $entry->forceFill(['owner_id' => $recipient->id, 'gifted_at' => $giftedAt])->save();
            DB::table($this->giftTable())->where('user_id', $recipient->id)->delete();
            $this->recordCreditEvent($user, $entry, 'gift_sent', $giftedAt);
            $this->recordCreditEvent($recipient, $entry, 'gift_received', $giftedAt);
            $this->notifyRecipient(NotificationEvent::IdentityCreditGifted, $entry);
        });
    }

    public function start(User $user, string $credit, string $locale): string
    {
        abort_unless($this->didit->configured(), 503, 'identity_sandbox_not_configured');

        // Persist the reservation before contacting Didit. If a request times out,
        // retry with the same vendor_data to recover its unfinished session.
        DB::transaction(function () use ($user, $credit): void {
            $this->lockUser($user);
            $entry = $this->credits($user)->where('uuid', $credit)->lockForUpdate()->firstOrFail();
            if ($entry->status === 'available') {
                abort_if($this->credits($user)->whereIn('status', self::ACTIVE)->exists(), 409, 'identity_sandbox_attempt_already_active');
                $entry->forceFill([
                    'status' => 'creating',
                    'attempt_uuid' => (string) Str::uuid(),
                    ...($this->mode() === 'live' ? ['consented_at' => now()] : []),
                ])->save();
            }
        });

        return DB::transaction(function () use ($user, $credit, $locale): string {
            $entry = $this->credits($user)->where('uuid', $credit)->lockForUpdate()->firstOrFail();
            if ($entry->status === 'pending' && $entry->provider_url !== null) {
                return $entry->provider_url;
            }
            abort_unless($entry->status === 'creating' && $entry->attempt_uuid !== null, 409, 'identity_sandbox_credit_unavailable');

            $session = $this->didit->create($entry->attempt_uuid, $locale);
            $entry->forceFill(['status' => 'pending', 'provider_session_id' => $session['id'], 'provider_url' => $session['url']])->save();

            return $session['url'];
        });
    }

    public function refresh(User $user, string $credit): void
    {
        DB::transaction(function () use ($user, $credit): void {
            $entry = $this->credits($user)->where('uuid', $credit)->lockForUpdate()->firstOrFail();
            if (! in_array($entry->status, ['pending', 'in_review'], true) || $entry->provider_session_id === null || $entry->attempt_uuid === null) {
                return;
            }

            $status = $this->didit->decision($entry->provider_session_id, $entry->attempt_uuid);
            $entry->status = $this->mode() === 'live' && $status === 'available' ? 'expired' : $status;
            if ($entry->status === 'available') {
                $entry->attempt_uuid = null;
                $entry->provider_session_id = null;
            }
            if (! in_array($status, ['pending', 'in_review'], true)) {
                $entry->provider_url = null;
            }
            $entry->save();
        });
    }

    public function allowed(User $user): bool
    {
        return app()->environment('local', 'staging', 'testing') && config('identity-sandbox.enabled') === true
            && in_array((string) $user->uuid, (array) config('identity-sandbox.test_users'), true);
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    protected function canClaim(User $user): bool
    {
        return ! IdentitySandboxCredit::query()->where('issued_to_user_id', $user->id)->exists();
    }

    protected function newCredit(): IdentitySandboxCredit
    {
        return new IdentitySandboxCredit();
    }

    protected function ledgerTable(): string
    {
        return 'identity_sandbox_credit_events';
    }

    protected function giftTable(): string
    {
        return 'identity_sandbox_gift_requests';
    }

    protected function lockUser(User $user): void
    {
        User::query()->lockForUpdate()->findOrFail($user->id);
    }

    private function notifyRecipient(NotificationEvent $event, IdentitySandboxCredit $credit): void
    {
        if (resolve(FeatureFlagService::class)->enabled(PlatformFeature::NotificationEvents)) {
            NotifyIdentityCreditRecipient::dispatch($event, $credit->id, $this->mode())->afterCommit();
        }
    }

    protected function recordCreditEvent(User $user, IdentitySandboxCredit $credit, string $event, Carbon $occurredAt): void
    {
        DB::table($this->ledgerTable())->insert([
            'user_id' => $user->id,
            'credit_id' => $credit->id,
            'event' => $event,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** @return Builder<IdentitySandboxCredit> */
    private function credits(User $user): Builder
    {
        return $this->newCredit()->newQuery()->where('owner_id', $user->id);
    }
}
