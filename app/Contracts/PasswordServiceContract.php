<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface PasswordServiceContract
{
    /**
     * Change the user's password
     *
     * @return array{success: bool, message: string}
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): array;

    /**
     * Send a one-time password to the user's email for initial password setup
     *
     * @return array{success: bool, message: string, code: int}
     */
    public function sendSetupOtp(User $user): array;

    /**
     * Set a password for a passwordless user using an OTP
     *
     * @return array{success: bool, message: string, code: int}
     */
    public function setPasswordWithOtp(User $user, string $otp, string $newPassword): array;

    /**
     * Send a password reset link to the user's email
     *
     * @return array{success: bool, message: string}
     */
    public function sendResetLink(string $email): array;

    /**
     * Reset the user's password using a token
     *
     * @return array{success: bool, message: string}
     */
    public function resetPassword(string $email, string $token, string $password): array;
}
