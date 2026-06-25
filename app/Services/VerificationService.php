<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use DomainException;
use App\Models\UserVerification;
use App\Events\VerificationReceived;
use App\Contracts\VerificationServiceContract;
use App\Http\Resources\UserVerificationResource;

class VerificationService implements VerificationServiceContract
{
    public function canUserVerify(User|int|string $verifier, User|int|string $target): array
    {
        $verifier = $this->resolveUser($verifier);
        if (! $verifier) {
            return [false, 'user_not_found'];
        }

        // Check verifier eligibility
        $canVerify = $verifier->canVerify();
        if (! $canVerify[0]) {
            return $canVerify;
        }

        $target = $this->resolveUser($target);
        if (! $target) {
            return [false, 'target_user_not_found'];
        }

        if ($target->marked_as_fake_at) {
            return [false, 'user_is_banned'];
        }

        if ($verifier->id === $target->id) {
            return [false, 'you_cannot_verify_yourself'];
        }

        // Circular verification is not allowed
        if ($verifier->verifiers()->where('verifier_id', $target->id)->exists()) {
            return [false, 'circular_verification_not_allowed'];
        }

        // Cannot verify the same user more than once
        if ($verifier->verifications()->where('user_id', $target->id)->whereNull('cancelled_at')->exists()) {
            return [false, 'you_have_already_verified_this_user'];
        }

        return [true, ''];
    }

    public function verifyUser(User $verifier, string $targetUuid, ?string $ipAddress, ?string $userAgent): void
    {
        $targetUser = User::where('uuid', $targetUuid)->firstOrFail();

        // Validate target user has required data
        if (
            empty($targetUser->name) ||
            empty($targetUser->surname) ||
            empty($targetUser->birth_date) ||
            empty($targetUser->gender) ||
            empty($targetUser->hometown) ||
            empty($targetUser->country)
        ) {
            throw new DomainException('target_user_data_not_filled');
        }

        $verification = $targetUser->verifiers()->make([
            'verifier_id' => $verifier->id,
        ]);
        $verification->ip_address = $ipAddress;
        $verification->user_agent = $userAgent;
        $verification->save();

        event(new VerificationReceived($verifier, $targetUser));
    }

    public function getVerificationsForUser(User $user, int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Paginate so the Sent / Received lists scale beyond a
        // single page. Newest first matches the "most recent
        // first" reading order both clients use. The controller
        // wraps `items()` in UserVerificationResource — keeping
        // the resource at the controller layer is the same
        // pattern UserPollController::myPolls follows.
        return $user->verifications()
            ->with(['user' => fn ($q) => $q->select('id', 'uuid', 'name', 'middle_name', 'surname', 'avatar')])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getVerifiersForUser(User $user, int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $user->verifiers()
            ->with(['verifier' => fn ($q) => $q->select('id', 'uuid', 'name', 'middle_name', 'surname', 'avatar')])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function cancelVerificationByVerifier(
        User $verifier,
        UserVerification $verification,
        string $reason = 'cancelled_by_verifier',
    ): UserVerification {
        // Authorize: the auth user MUST be the original verifier of
        // this row. We surface this as DomainException so the
        // controller can translate to a 403 with the message key.
        // Treating it as a user-level error (not auth failure) keeps
        // the auth-middleware semantics clean.
        if ($verification->verifier_id !== $verifier->id) {
            throw new DomainException('not_authorized_to_cancel_this_verification');
        }

        if ($verification->cancelled_at !== null) {
            throw new DomainException('verification_already_cancelled');
        }

        // Soft-cancel: keep the row so the audit trail stays intact.
        // The `cancelation_payload` array gives us room to carry
        // metadata (who cancelled, when, why); list endpoints that
        // want only active verifications filter on
        // `whereNull('cancelled_at')`.
        $verification->forceFill([
            'cancelled_at' => now(),
            'cancelation_payload' => [
                'reason' => $reason,
                'cancelled_by' => 'verifier',
                'cancelled_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $verification->fresh();
    }

    /**
     * Resolve a user from various input types
     */
    private function resolveUser(User|int|string $user): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        if (is_int($user)) {
            return User::find($user);
        }

        return User::where('uuid', $user)->first();
    }
}
