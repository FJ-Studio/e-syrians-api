<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Poll;
use App\Models\User;
use Laravel\Pennant\Feature;
use App\Models\SecurityEvent;
use Illuminate\Bus\Queueable;
use App\Models\FeatureRequest;
use App\Models\UserVerification;
use App\Models\NotificationAnnouncement;
use Illuminate\Database\Eloquent\Builder;
use App\Notifications\PlatformNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\PlatformNotificationService;

/** ID-only jobs; source records, eligibility and consent are rechecked at execution. */
class FanOutPlatformNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 50;

    public function __construct(public string $event, public int $subjectId, public int $afterId = 0, public ?string $sourceKey = null)
    {
    }

    public function handle(PlatformNotificationService $notifications): void
    {
        // Failed jobs do not emit JobProcessed, so a worker may still hold old Pennant values.
        Feature::flushCache();
        $users = User::query()->whereNull('deletion_scheduled_for')->whereNull('marked_as_fake_at');
        $poll = null;
        $announcement = null;
        if (in_array($this->event, ['verification_received', 'verification_revoked'], true)) {
            $verification = UserVerification::withTrashed()->find($this->subjectId);
            if (! $verification || ($this->event === 'verification_received' && ($verification->cancelled_at || $verification->trashed()))
                || ($this->event === 'verification_revoked' && ! $verification->cancelled_at && ! $verification->trashed())) {
                return;
            }
            $users->whereKey($verification->user_id);
        } elseif (in_array($this->event, ['account_verified', 'account_unverified'], true)) {
            $users->whereKey($this->subjectId);
            $this->event === 'account_verified' ? $users->whereNotNull('verified_at') : $users->whereNull('verified_at');
        } elseif ($this->event === 'poll_opened') {
            // Private polls are not accessible by invitees in the current API.
            $poll = Poll::with('audienceRules')->find($this->subjectId);
            if (! $poll || $poll->invitations_dispatched_at || $poll->start_date > now() || $poll->end_date <= now()) {
                return;
            }
            $users->whereNotNull('verified_at')->where('id', '!=', $poll->created_by)
                ->whereDoesntHave('votes', fn (Builder $votes) => $votes->where('poll_id', $poll->id));
        } elseif ($this->event === 'poll_closed') {
            $poll = Poll::withoutGlobalScope('public_polls')->with('audienceRules')->find($this->subjectId);
            if (! $poll || $poll->closure_notified_at || $poll->end_date > now()) {
                return;
            }
            $users->where(fn (Builder $q) => $q->whereKey($poll->created_by)
                ->orWhereHas('votes', fn (Builder $votes) => $votes->where('poll_id', $poll->id)));
            if ($poll->is_private) {
                $users->whereKey($poll->created_by);
            }
        } elseif ($this->event === 'announcement') {
            $announcement = NotificationAnnouncement::find($this->subjectId);
            if (! $announcement || $announcement->processed_at) {
                return;
            }
            $users->where('marketing_notifications_opt_in', true)
                ->where('created_at', '<=', $announcement->created_at);
        } elseif (str_starts_with($this->event, 'security_')) {
            $audit = SecurityEvent::find($this->subjectId);
            if (! $audit || $audit->event !== $this->event) {
                return;
            }
            $users->whereKey($audit->user_id);
        } elseif (str_starts_with($this->event, 'feature_')) {
            $feature = FeatureRequest::find($this->subjectId);
            if (! $feature || 'feature_'.$feature->status !== $this->event) {
                return;
            }
            $users->where(fn (Builder $q) => $q->whereKey($feature->created_by)
                ->orWhereIn('id', $feature->votes()->select('user_id')));
        } else {
            return;
        }

        $batch = $users->where('id', '>', $this->afterId)->orderBy('id')->limit(200)->get();
        foreach ($batch as $user) {
            if ($poll && (($this->event === 'poll_opened' && ! $user->isInAudience($poll)[0])
                || ($this->event === 'poll_closed' && ! $poll->isVisibleTo($user)))) {
                continue;
            }
            $notifications->send($user, new PlatformNotification(
                $this->event,
                (string) $this->subjectId,
                $announcement?->title,
                $announcement?->body
            ), $this->sourceKey ?? (string) $this->subjectId);
        }
        if ($batch->count() === 200) {
            dispatch(new self($this->event, $this->subjectId, $batch->last()->id, $this->sourceKey))->afterCommit();
            return;
        }
        $poll?->forceFill([$this->event === 'poll_closed' ? 'closure_notified_at' : 'invitations_dispatched_at' => now()])->saveQuietly();
        $announcement?->forceFill(['processed_at' => now()])->save();
    }
}
