<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VerificationServiceContract;
use App\Events\VerificationReceived;
use App\Http\Resources\UserVerificationResource;
use App\Models\User;

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
            throw new \DomainException('target_user_data_not_filled');
        }

        $targetUser->verifiers()->create([
            'verifier_id' => $verifier->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        event(new VerificationReceived($verifier, $targetUser));
    }

    public function getVerificationsForUser(User $user): mixed
    {
        return $user->verifications()->with('user')->get();
    }

    public function getVerifiersForUser(User $user): mixed
    {
        return UserVerificationResource::collection(
            $user->verifiers()->with('verifier')->get()
        );
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
