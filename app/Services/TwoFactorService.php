<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;

final class TwoFactorService
{
    private Google2FA $google2fa;

    public function __construct(Google2FA $google2fa)
    {
        $this->google2fa = $google2fa;
    }

    /**
     * Generate a new secret key for TOTP.
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Generate the provisioning URI for the authenticator app.
     */
    public function getProvisioningUri(User $user, string $secret): string
    {
        $appName = config('app.name', 'E-Syrians');

        return $this->google2fa->getQRCodeUrl(
            $appName,
            $user->email,
            $secret
        );
    }

    /**
     * Generate a QR code as SVG for the authenticator app.
     */
    public function getQrCodeSvg(string $provisioningUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($provisioningUri);
    }

    /**
     * Get QR code as a data URI (base64 encoded SVG).
     */
    public function getQrCodeDataUri(string $provisioningUri): string
    {
        $svg = $this->getQrCodeSvg($provisioningUri);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Verify the OTP code provided by the user.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Verify recovery code and consume it if valid.
     *
     * @return bool Whether the recovery code was valid
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->recovery_codes ?? [];

        if (!is_array($recoveryCodes)) {
            return false;
        }

        $normalizedCode = mb_strtoupper(trim($code));
        $index = array_search($normalizedCode, $recoveryCodes, true);

        if ($index === false) {
            return false;
        }

        // Remove the used recovery code
        unset($recoveryCodes[$index]);
        $user->update([
            'recovery_codes' => array_values($recoveryCodes),
        ]);

        return true;
    }

    /**
     * Enable 2FA for a user (setup phase - not yet confirmed).
     */
    public function setupTwoFactor(User $user): array
    {
        $secret = $this->generateSecretKey();

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ]);

        $provisioningUri = $this->getProvisioningUri($user, $secret);

        return [
            'secret' => $secret,
            'qr_code' => $this->getQrCodeDataUri($provisioningUri),
            'provisioning_uri' => $provisioningUri,
        ];
    }

    /**
     * Confirm and enable 2FA for a user.
     */
    public function confirmTwoFactor(User $user, string $code): bool
    {
        if (empty($user->two_factor_secret)) {
            return false;
        }

        if (!$this->verifyCode($user->two_factor_secret, $code)) {
            return false;
        }

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'recovery_codes' => RecoveryCodeService::generateCodes(),
        ]);

        return true;
    }

    /**
     * Disable 2FA for a user.
     */
    public function disableTwoFactor(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * Verify 2FA code during login (either TOTP or recovery code).
     */
    public function verifyTwoFactorCode(User $user, string $code, bool $isRecoveryCode = false): bool
    {
        if (!$user->hasTwoFactorEnabled()) {
            return true;
        }

        if ($isRecoveryCode) {
            return $this->verifyAndConsumeRecoveryCode($user, $code);
        }

        return $this->verifyCode($user->two_factor_secret, $code);
    }
}
