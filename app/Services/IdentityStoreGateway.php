<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use JsonException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;

/** Look up purchases directly with the stores; no client-supplied receipt fields are trusted. */
class IdentityStoreGateway
{
    private const GOOGLE_ORIGIN = 'https://androidpublisher.googleapis.com';

    private const GOOGLE_OAUTH = 'https://oauth2.googleapis.com/token';

    public function environment(): string
    {
        // Production never accepts App Store sandbox or Play license-test purchases.
        return app()->environment('production') ? 'production' : 'sandbox';
    }

    public function product(string $platform): ?string
    {
        $product = config("identity-verification.store.{$platform}.product");

        return is_string($product) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $product) === 1 ? $product : null;
    }

    public function configured(string $platform): bool
    {
        return match ($platform) {
            'ios' => is_string(config('identity-verification.store.ios.issuer'))
                && filled(config('identity-verification.store.ios.issuer'))
                && filled(config('identity-verification.store.ios.key_id'))
                && $this->readable('ios'),
            'android' => $this->readable('android'),
            default => false,
        };
    }

    /** @return array{state: 'paid'|'pending'|'reversed', owner: string, consumed: bool} */
    public function lookup(string $platform, string $product, string $proof): array
    {
        abort_unless($this->configured($platform), 503, 'identity_store_unavailable');

        try {
            return $platform === 'ios' ? $this->apple($product, $proof) : $this->google($product, $proof);
        } catch (ConnectionException|JsonException) {
            abort(503, 'identity_store_unavailable');
        }
    }

    /** Used only after the corresponding credit has committed to the wallet. */
    public function consumeGoogle(string $product, string $proof): void
    {
        try {
            $path = '/purchases/products/'.rawurlencode($product).'/tokens/'.rawurlencode($proof).':consume';
            $response = $this->googleClient()->post($path);
            if (! $response->successful()) {
                // Handles a simultaneous consume or a successful request whose response was lost.
                abort_unless($this->google($product, $proof)['consumed'], 503, 'identity_store_finalize_pending');
            }
        } catch (ConnectionException|JsonException) {
            abort(503, 'identity_store_finalize_pending');
        }
    }

    /** @return array{state: 'paid'|'reversed', owner: string, consumed: false} */
    private function apple(string $product, string $proof): array
    {
        abort_unless(preg_match('/^[0-9]{1,32}$/D', $proof) === 1, 422, 'identity_store_invalid_purchase');
        $sandbox = $this->environment() === 'sandbox';
        $bundle = $this->bundle('ios');
        $host = $sandbox ? 'https://api.storekit-sandbox.apple.com' : 'https://api.storekit.apple.com';
        $now = now()->getTimestamp();
        $token = $this->sign([
            'iss' => config('identity-verification.store.ios.issuer'),
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'appstoreconnect-v1',
            'bid' => $bundle,
        ], $this->keyFile('ios'), 'ES256', (string) config('identity-verification.store.ios.key_id'));
        $response = $this->http()->withToken($token)->get($host.'/inApps/v1/transactions/'.$proof);
        abort_if($response->notFound(), 422, 'identity_store_invalid_purchase');
        abort_unless($response->successful(), 503, 'identity_store_unavailable');

        // The signed envelope comes only from Apple's directly authenticated HTTPS API,
        // never from the device. Redirects are disabled. Check each binding before use.
        $jws = $response->json('signedTransactionInfo');
        $parts = is_string($jws) ? explode('.', $jws) : [];
        abort_unless(count($parts) === 3 && $parts[2] !== '', 503, 'identity_store_unavailable');
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $raw === false ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($data)
            && ($data['transactionId'] ?? null) === $proof
            && ($data['bundleId'] ?? null) === $bundle
            && ($data['environment'] ?? null) === ($sandbox ? 'Sandbox' : 'Production')
            && ($data['productId'] ?? null) === $product
            && ($data['type'] ?? null) === 'Consumable'
            && ($data['quantity'] ?? null) === 1
            && ($data['inAppOwnershipType'] ?? null) === 'PURCHASED'
            && is_string($data['appAccountToken'] ?? null), 422, 'identity_store_invalid_purchase');

        return [
            'state' => isset($data['revocationDate']) ? 'reversed' : 'paid',
            'owner' => strtolower($data['appAccountToken']),
            'consumed' => false,
        ];
    }

    /** @return array{state: 'paid'|'pending'|'reversed', owner: string, consumed: bool} */
    private function google(string $product, string $proof): array
    {
        $response = $this->googleClient()->get('/purchases/productsv2/tokens/'.rawurlencode($proof));
        abort_if($response->notFound(), 422, 'identity_store_invalid_purchase');
        abort_unless($response->successful(), 503, 'identity_store_unavailable');
        $lines = $response->json('productLineItem');
        $owner = $response->json('obfuscatedExternalAccountId');
        $item = is_array($lines) && count($lines) === 1 ? ($lines[0] ?? null) : null;
        abort_unless(is_array($item) && ($item['productId'] ?? null) === $product
            && data_get($item, 'productOfferDetails.quantity') === 1
            && is_string($owner)
            && (is_array($response->json('testPurchaseContext')) === ($this->environment() === 'sandbox')), 422, 'identity_store_invalid_purchase');
        $state = match ($response->json('purchaseStateContext.purchaseState')) {
            'PURCHASED' => data_get($item, 'productOfferDetails.refundableQuantity') === 0 ? 'reversed' : 'paid',
            'PENDING' => 'pending',
            'CANCELLED' => 'reversed',
            default => abort(503, 'identity_store_unavailable'),
        };

        return [
            'state' => $state,
            'owner' => $owner,
            'consumed' => data_get($item, 'productOfferDetails.consumptionState') === 'CONSUMPTION_STATE_CONSUMED',
        ];
    }

    private function googleClient(): PendingRequest
    {
        $credentials = json_decode($this->keyFile('android'), true, flags: JSON_THROW_ON_ERROR);
        $email = is_array($credentials) ? ($credentials['client_email'] ?? null) : null;
        $private = is_array($credentials) ? ($credentials['private_key'] ?? null) : null;
        abort_unless(is_string($email) && is_string($private), 503, 'identity_store_unavailable');
        $token = Cache::remember('identity-play-token:'.hash('sha256', $email), 240, function () use ($email, $private): string {
            $now = now()->getTimestamp();
            $assertion = $this->sign([
                'iss' => $email,
                'scope' => 'https://www.googleapis.com/auth/androidpublisher',
                'aud' => self::GOOGLE_OAUTH,
                'iat' => $now,
                'exp' => $now + 300,
            ], $private, 'RS256');
            $response = $this->http()->asForm()->post(self::GOOGLE_OAUTH, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            $value = $response->json('access_token');
            abort_unless($response->successful() && is_string($value) && $value !== '', 503, 'identity_store_unavailable');

            return $value;
        });
        $bundle = rawurlencode($this->bundle('android'));

        return $this->http()->withToken($token)->baseUrl(self::GOOGLE_ORIGIN.'/androidpublisher/v3/applications/'.$bundle);
    }

    private function bundle(string $platform): string
    {
        return (string) config("identity-verification.store.{$platform}.bundle");
    }

    private function readable(string $platform): bool
    {
        $path = config("identity-verification.store.{$platform}.key_path");

        return is_string($path) && is_file($path) && is_readable($path);
    }

    private function keyFile(string $platform): string
    {
        abort_unless($this->readable($platform), 503, 'identity_store_unavailable');
        $contents = file_get_contents((string) config("identity-verification.store.{$platform}.key_path"));
        abort_unless(is_string($contents) && $contents !== '', 503, 'identity_store_unavailable');

        return $contents;
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims, string $key, string $algorithm, ?string $kid = null): string
    {
        try {
            return JWT::encode($claims, $key, $algorithm, $kid);
        } catch (Throwable) {
            abort(503, 'identity_store_unavailable');
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
    }
}
