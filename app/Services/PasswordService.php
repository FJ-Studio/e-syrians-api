<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PasswordServiceContract;
use App\Models\User;
use App\Services\StrService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordService implements PasswordServiceContract
{
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return ['success' => false, 'message' => __('api.current_password_incorrect'), 'code' => 401];
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        return ['success' => true, 'message' => __('api.password_updated'), 'code' => 200];
    }

    public function sendResetLink(string $email): array
    {
        $hashedEmail = StrService::hash($email);
        $user = User::where('email_hashed', $hashedEmail)->first();

        if (! $user) {
            // Don't reveal whether the email exists for privacy
            return ['success' => true, 'message' => __('api.reset_link_sent'), 'code' => 200];
        }

        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return ['success' => false, 'message' => __('api.failed_to_send_password_reset_email'), 'code' => 500];
        }

        return ['success' => true, 'message' => __('api.reset_link_sent'), 'code' => 200];
    }

    public function resetPassword(string $email, string $token, string $password): array
    {
        $status = Password::reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'token' => $token,
            ],
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ['success' => false, 'message' => __('api.failed_to_reset_password'), 'code' => 500];
        }

        return ['success' => true, 'message' => __('api.password_reset_successfully'), 'code' => 200];
    }
}
