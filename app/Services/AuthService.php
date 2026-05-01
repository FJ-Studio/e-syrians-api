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
    public function authenticateViaSocialProvider(string $provider, string $token, ?string $clientName = null): ?array
    {
        $userData = $this->resolveSocialUserData($provider, $token);

        if (! $userData) {
            return null;
        }

        // Apple only returns the user's name on the very first sign-in (and
        // not in the JWT — it comes from the client SDK separately). When the
        // client passes a name through, prefer it over whatever the provider
        // gave us (Apple gives nothing; for other providers it's a backstop).
        $clientName = trim((string) $clientName);
        if ($clientName !== '') {
            $userData['name'] = $clientName;
        }

        // Look up by stable provider id first (Apple doesn't always return an
        // email on subsequent sign-ins; only the first one), then fall back
        // to email lookup. This keeps the same user across multiple logins
        // even if Apple's email-relay address changes.
        $providerColumn = $provider.'_id';
        $user = User::where($providerColumn, $userData['id'])->first();

        if (! $user && ! empty($userData['email'])) {
            $user = User::where('email', $userData['email'])->first();
        }

        // resolveSocialUserData() guarantees `name` is a string (possibly empty).
        $names = explode(' ', trim($userData['name']), 2);

        if (! $user) {
            $user = User::create([
                $providerColumn => $userData['id'],
                'name' => $names[0] ?: ($userData['email'] ?? 'User'),
                'surname' => $names[1] ?? '',
                'email' => $userData['email'] ?? null,
            ]);
            $user->assignRole('citizen');
            $user->markEmailAsVerified();
        } else {
            $needsSave = false;

            // Existing user signing in via this provider for the first time —
            // link the provider id without overwriting their profile.
            if (empty($user->{$providerColumn})) {
                $user->{$providerColumn} = $userData['id'];
                $needsSave = true;
            }

            // If we received a name from the client and the user doesn't have
            // one yet (e.g. account was provisioned by another flow with only
            // an email), backfill it now. We never overwrite an existing name.
            if ($clientName !== '' && empty($user->name)) {
                $user->name = $names[0];
                if (empty($user->surname) && ! empty($names[1])) {
                    $user->surname = $names[1];
                }
                $needsSave = true;
            }

            if ($needsSave) {
                $user->save();
            }
        }

        $plainToken = $user->createToken($provider)->plainTextToken;

        return [
            'user' => $user,
            'token' => explode('|', $plainToken)[1],
        ];
    }

    /**
     * Resolve user data from a social provider token. Apple uses a JWT
     * identity token that we verify locally; other providers go through
     * Laravel Socialite's userFromToken().
     *
     * @return array{id: string, name: string, email: ?string, avatar: ?string}|null
     */
    private function resolveSocialUserData(string $provider, string $token): ?array
    {
        if ($provider === 'apple') {
            $data = AppleAuthService::getUserDataFromIdentityToken($token);

            return empty($data) ? null : $data;
        }

        $socialUser = Socialite::driver($provider)->userFromToken($token);

        if (! $socialUser) {
            return null;
        }

        return [
            'id' => (string) $socialUser->getId(),
            'name' => (string) ($socialUser->getName() ?? ''),
            'email' => $socialUser->getEmail(),
            'avatar' => $socialUser->getAvatar(),
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
