<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Mail\PasswordSetupOtp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use App\Contracts\PasswordServiceContract;

class PasswordService implements PasswordServiceContract
{
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return ['success' => false, 'message' => 'current_password_incorrect', 'code' => 401];
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        return ['success' => true, 'message' => 'password_updated', 'code' => 200];
    }

    public function sendSetupOtp(User $user): array
    {
        if (! is_null($user->password)) {
            return ['success' => false, 'message' => 'user_already_has_password', 'code' => 422];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("password_setup_otp:{$user->id}", $code, now()->addMinutes(10));

        Mail::to($user->email)->send(new PasswordSetupOtp($user, $code));

        return ['success' => true, 'message' => 'otp_sent', 'code' => 200];
    }

    public function setPasswordWithOtp(User $user, string $otp, string $newPassword): array
    {
        if (! is_null($user->password)) {
            return ['success' => false, 'message' => 'user_already_has_password', 'code' => 422];
        }

        $cachedOtp = Cache::get("password_setup_otp:{$user->id}");

        if (! $cachedOtp || $cachedOtp !== $otp) {
            return ['success' => false, 'message' => 'invalid_or_expired_otp', 'code' => 422];
        }

        $user->password = Hash::make($newPassword);
        $user->save();

        Cache::forget("password_setup_otp:{$user->id}");

        return ['success' => true, 'message' => 'password_set_successfully', 'code' => 200];
    }

    public function sendResetLink(string $email): array
    {
        $hashedEmail = StrService::hash($email);
        $user = User::where('email_hashed', $hashedEmail)->first();

        if (! $user) {
            // Don't reveal whether the email exists for privacy
            return ['success' => true, 'message' => 'reset_link_sent', 'code' => 200];
        }

        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return ['success' => false, 'message' => 'failed_to_send_password_reset_email', 'code' => 500];
        }

        return ['success' => true, 'message' => 'reset_link_sent', 'code' => 200];
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
            function ($user, $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ['success' => false, 'message' => 'failed_to_reset_password', 'code' => 500];
        }

        return ['success' => true, 'message' => 'password_reset_successfully', 'code' => 200];
    }
}
