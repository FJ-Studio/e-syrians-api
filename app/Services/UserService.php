<?php

namespace App\Services;

use Laravel\Socialite\Facades\Socialite;

class UserService
{
    public static function getUserDataFromSocialProvider(string $provider, string $token): array|bool
    {
        $user = (Socialite::driver($provider))->userFromToken($token);
        if ($user) {
            return [
                'id' => $user->getId(),
                'nickname' => $user->getNickname(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'avatar' => $user->getAvatar(),
            ];
        }
        return false;
    }
}
