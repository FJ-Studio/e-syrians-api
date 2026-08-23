<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Models\Poll;
use App\Models\User;
use App\Models\FeatureRequest;
use App\Models\UserVerification;
use App\Models\FeatureRequestVote;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Contracts\FileUploadServiceContract;
use App\Exceptions\AccountDeletionException;
use App\Contracts\AccountDeletionServiceContract;

/**
 * Two-stage account-deletion workflow:
 *
 *  1) requestDeletion() — password-verified. Sets both timestamps
 *     (requested_at = now, scheduled_for = now + 15 days).
 *     Sessions/tokens are intentionally NOT revoked here — the
 *     user's session must survive so they can cancel or check
 *     status during the 15-day grace window. Every other
 *     authenticated route is blocked by
 *     `EnsureAccountNotPendingDeletion`; only cancel-deletion,
 *     deletion-status and logout remain reachable.
 *
 *  2) cancelDeletion() — password-verified. Clears both timestamps.
 *
 *  3) hardDeleteAccount() — invoked by the daily scheduled job
 *     once the scheduled_for timestamp has passed. Cascades
 *     cleanup of the user's rows (polls, votes, reactions,
 *     verifications, audiences, notifications, devices, avatar)
 *     and forceDeletes the User row (which finally cascades any
 *     lingering Sanctum tokens via HasApiTokens).
 */
final class AccountDeletionService implements AccountDeletionServiceContract
{
    /**
     * Grace period between request and hard delete. Kept as a class
     * constant so tests can reference it without hard-coding "15".
     */
    public const GRACE_DAYS = 15;

    public function __construct(
        private readonly FileUploadServiceContract $fileUploadService,
    ) {
    }

    public function requestDeletion(User $user, string $password): array
    {
        if (! is_string($user->password) || ! Hash::check($password, $user->password)) {
            throw new AccountDeletionException('invalid_password', 422);
        }

        if ($user->hasPendingDeletion()) {
            // Idempotency: rather than double-set the timestamps (and
            // reset the grace-period clock), surface the already-
            // pending state to the caller so they can render the
            // banner instead of a success toast.
            throw new AccountDeletionException(
                'account_deletion_already_pending',
                422,
                $this->getDeletionStatus($user),
            );
        }

        $user->forceFill([
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => now()->addDays(self::GRACE_DAYS),
        ])->save();

        // NOTE: we intentionally do NOT revoke Sanctum tokens here.
        // The whole purpose of the `EnsureAccountNotPendingDeletion`
        // middleware is to keep the user's session alive during the
        // 15-day grace window so they can cancel the deletion or
        // check status. Revoking tokens would sign the user out and
        // strand them at the login screen — killing the ability to
        // cancel. Tokens are cleaned up only when the user row is
        // forceDeleted by `HardDeleteExpiredAccountsJob` (cascades
        // via `HasApiTokens`).

        // TODO: hook up a Resend-backed AccountDeletionRequested
        // notification when the app grows a generic mail wiring.
        // Deferred: e-syrians currently only sends transactional
        // mail through `Mail::to($user->email)->queue(...)` from
        // PasswordService — no Notification-channel plumbing yet.
        // $user->notify(new AccountDeletionRequested($user->deletion_scheduled_for));

        return $this->getDeletionStatus($user->refresh());
    }

    public function cancelDeletion(User $user, string $password): array
    {
        if (! is_string($user->password) || ! Hash::check($password, $user->password)) {
            throw new AccountDeletionException('invalid_password', 422);
        }

        if (! $user->hasPendingDeletion()) {
            throw new AccountDeletionException(
                'account_deletion_not_pending',
                422,
                $this->getDeletionStatus($user),
            );
        }

        $user->forceFill([
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
        ])->save();

        // TODO: hook up a Resend-backed AccountDeletionCancelled
        // notification (see requestDeletion() for why this is deferred).
        // $user->notify(new AccountDeletionCancelled());

        return $this->getDeletionStatus($user->refresh());
    }

    public function getDeletionStatus(User $user): array
    {
        return [
            'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
            'deletion_scheduled_for' => $user->deletion_scheduled_for?->toIso8601String(),
            'is_pending' => $user->hasPendingDeletion(),
            // Surfaced so mobile + web can gate the destructive
            // form on whether the user actually has a password to
            // re-enter. Social-only signups (Google / Apple with
            // no password ever set) get a "set a password first"
            // CTA instead of a 422 dead-end from Hash::check().
            // The backend still enforces `Hash::check` on write;
            // this flag is purely for UX correctness.
            'requires_password' => is_string($user->password) && $user->password !== '',
        ];
    }

    /**
     * Cascade-clean everything the user owns and then hard-delete
     * the user row itself. Some FKs already have `cascadeOnDelete`
     * (devices, audiences, feature_request_votes) so they'd
     * disappear automatically on forceDelete — but we clear them
     * explicitly here anyway. Two reasons:
     *
     *   1) The relationship models emit their own `deleting`
     *      hooks (Audience → audits, Poll → media cleanup) that
     *      DB-level cascades bypass.
     *   2) Explicit cleanup lets the caller wrap the whole thing
     *      in one transaction and log which step failed if a row
     *      is malformed.
     */
    public function hardDeleteAccount(User $user): void
    {
        // Remove the S3 avatar before the row disappears; if this
        // fails we log-and-continue rather than block the delete.
        if ($user->avatar) {
            try {
                $this->fileUploadService->delete($user->avatar);
            } catch (Throwable $e) {
                Log::warning('Failed to delete user avatar during hard delete', [
                    'user_id' => $user->id,
                    'avatar' => $user->avatar,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Reactions, votes — hard-delete (personal engagement data,
        // no cascade on the users FK). Delete BEFORE polls so the
        // poll_votes / poll_reactions scope tightens naturally.
        // Neither model uses SoftDeletes so plain ->delete() is
        // already a hard delete.
        $user->reactions()->delete();
        $user->votes()->delete();

        // Verifications this user issued or received — hard-delete
        // (peer-verification history is personal data). UserVerification
        // uses SoftDeletes so we route through the query builder's
        // forceDelete() to bypass the soft-delete trait and actually
        // remove the rows. Both directions reference `users.id`
        // without cascade so we clear them explicitly.
        UserVerification::query()->where('user_id', $user->id)->forceDelete();
        UserVerification::query()->where('verifier_id', $user->id)->forceDelete();

        // Polls created by this user — hard-delete (the user's
        // authored content and the whole vote/reaction/audience-rule
        // subtree). Uses SoftDeletes; go through the model query
        // builder with withTrashed()->forceDelete() so PHPStan/Larastan
        // resolves the call chain correctly (HasMany::withTrashed()
        // is fine at runtime but not typed on the relation stub) and
        // so we sweep already-soft-deleted rows too. Related
        // poll_options / poll_votes / poll_reactions /
        // poll_audience_rules cascade on the poll FK.
        Poll::withTrashed()->where('created_by', $user->id)->forceDelete();

        // Feature requests authored by this user — hard-delete
        // (personal content). FeatureRequest uses SoftDeletes so
        // ->delete() alone would only set deleted_at and leave a
        // ghost row behind. Go through the query builder to bypass
        // the SoftDeletes trait.
        FeatureRequest::query()->where('created_by', $user->id)->forceDelete();

        // Feature-request votes cast by this user — hard-delete
        // (engagement history tied to the user identity). The
        // feature_request_votes table has NO FK cascade on
        // user_id (only on feature_request_id), so without this
        // explicit cleanup these rows become orphans pointing at
        // a nonexistent user id.
        FeatureRequestVote::query()->where('user_id', $user->id)->delete();

        // Audit trail + profile-update history — hard-delete
        // (bound to the user id, no value once the user row is gone).
        $user->profileUpdates()->delete();

        // Notifications on this user (Laravel's built-in table) —
        // hard-delete (personal inbox).
        $user->notifications()->delete();

        // Devices — hard-delete (personal push-subscription rows).
        // FK cascades, but call explicitly so the OneSignal
        // subscription rows are gone before the user id vanishes
        // (avoids orphaned records if the cascade races with an
        // in-flight push write).
        $user->devices()->delete();

        // Audiences — hard-delete (personal saved lists). FK
        // cascades, but call explicitly so Audience's own
        // `deleting` hooks (audits, entries) run.
        $user->audiences()->each(fn ($audience) => $audience->forceDelete());

        // suspicious_activities: RETAINED for moderation history; the FK
        // is `ON DELETE SET NULL` (see the 2026_08_23 migration) so the
        // row survives the User forceDelete below with `user_id = NULL`.
        // Fraud-review pipeline still has the evidence + rule triggers;
        // we just lose the pointer to the (now-deleted) actor. This is
        // the deliberate product decision so a re-registered account
        // can't wipe its abuse trail by triggering account deletion.

        // Kill any lingering Sanctum tokens (belt-and-braces before
        // the User forceDelete cascades them via HasApiTokens).
        $user->tokens()->delete();

        // Finally, hard-delete the user row (bypasses SoftDeletes).
        $user->forceDelete();
    }
}
