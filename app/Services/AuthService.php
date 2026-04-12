<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Hash;
use App\Contracts\AuthServiceContract;
use Illuminate\Auth\Events\Registered;
use Laravel\Socialite\Facades\Socialite;

class AuthService implements AuthServiceContract
{
    public function authenticateViaSocialProvider(string $provider, string $token): ?array
    {
        $socialUser = Socialite::driver($provider)->userFromToken($token);

        if (! $socialUser) {
            return null;
        }

        $name = explode(' ', $socialUser->getName());

        $user = User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $user = User::create([
                $provider.'_id' => $socialUser->getId(),
                'name' => $name[0],
                'surname' => $name[1] ?? '',
                'email' => $socialUser->getEmail(),
            ]);
            $user->assignRole('citizen');
            $user->markEmailAsVerified();
        }

        if (! $user) {
            return null;
        }

        $plainToken = $user->createToken($provider)->plainTextToken;

        return [
            'user' => $user,
            'token' => explode('|', $plainToken)[1],
        ];
    }

    public function authenticateViaCredentials(string $identifier, string $password): ?array
    {
        $hashedIdentifier = StrService::hash($identifier);

        $user = User::whereNotNull('email_hashed')->where('email_hashed', $hashedIdentifier)
            ->orWhere(function ($query) use ($hashedIdentifier): void {
                $query->whereNotNull('phone_hashed')->where('phone_hashed', $hashedIdentifier);
            })
            ->orWhere(function ($query) use ($hashedIdentifier): void {
                $query->whereNotNull('national_id_hashed')->where('national_id_hashed', $hashedIdentifier);
            })
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        // Check if 2FA is enabled — return a challenge instead of a token
        if ($user->hasTwoFactorEnabled()) {
            $challenge = TwoFactorChallengeService::createChallenge($user->id);

            return [
                'user' => $user,
                'requires_2fa' => true,
                'challenge_token' => $challenge['challenge_token'],
                'expires_at' => $challenge['expires_at'],
            ];
        }

        $plainToken = $user->createToken(date('Y-m-d-H:i:s'))->plainTextToken;

        return [
            'user' => $user,
            'token' => explode('|', $plainToken)[1],
        ];
    }

    public function register(array $data): User
    {
        if (isset($data['languages'])) {
            $data['languages'] = implode(',', $data['languages']);
        }
        if (isset($data['other_nationalities'])) {
            $data['other_nationalities'] = implode(',', $data['other_nationalities']);
        }

        // Extract password before mass-assignment (password is guarded on User model)
        $password = $data['password'] ?? null;
        unset($data['password'], $data['password_confirmation']);

        $user = new User($data);
        if ($password) {
            $user->password = $password; // 'hashed' cast handles hashing
        }
        $user->save();

        $user->assignRole('citizen');
        event(new Registered($user));

        return $user;
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    public function verifyEmail(int $userId, string $hash, string $signature): array
    {
        $user = User::findOrFail($userId);

        if ($user->hasVerifiedEmail()) {
            return ['success' => false, 'message' => 'user_already_verified', 'code' => 403];
        }

        if (! hash_equals($hash, sha1($user->email))) {
            return ['success' => false, 'message' => 'invalid_verification_link', 'code' => 403];
        }

        if (! URL::hasValidSignature(request(), false)) {
            return ['success' => false, 'message' => 'invalid_verification_signature', 'code' => 400];
        }

        $user->markEmailAsVerified();

        return ['success' => true, 'message' => 'email_verified', 'code' => 200];
    }
}
