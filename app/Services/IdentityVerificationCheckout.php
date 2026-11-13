<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\IdentityVerificationOrder;
use App\Models\IdentityVerificationCredit;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;

class IdentityVerificationCheckout
{
    public const AMOUNT = 300;

    public const CURRENCY = 'eur';

    private const SOURCE = 'e-syrians-id-verification';

    public function __construct(private readonly IdentityVerificationService $wallet)
    {
    }

    public function ready(): bool
    {
        $key = config('identity-verification.stripe.key');
        $secret = config('identity-verification.stripe.webhook_secret');

        return config('identity-verification.stripe.enabled') === true && $this->wallet->providerReady()
            && is_string($key) && preg_match('/^(sk|rk)_'.($this->liveMode() ? 'live' : 'test').'_\S+$/D', $key) === 1
            && is_string($secret) && str_starts_with($secret, 'whsec_')
            && $this->priceId() !== null
            && $this->returnOrigin() !== null;
    }

    /** @return array{url: string} */
    public function start(User $user, string $id, string $locale): array
    {
        $origin = $this->returnOrigin();
        $priceId = $this->priceId();
        abort_unless($this->wallet->allowed($user) && $this->ready() && $origin !== null && $priceId !== null, 503, 'identity_checkout_unavailable');

        $order = IdentityVerificationOrder::query()->firstOrCreate(['uuid' => $id], [
            'user_id' => $user->id,
            'amount_minor' => self::AMOUNT,
            'currency' => self::CURRENCY,
            'stripe_price_id' => $priceId,
            'locale' => $locale,
            'livemode' => $this->liveMode(),
        ]);
        abort_unless($order->user_id === $user->id, 404);
        // A retry must reuse the original Stripe parameters, never create a second charge.
        abort_unless($order->status === 'pending' && $order->livemode === $this->liveMode()
            && $order->created_at->gt(now()->subHours(2)), 409, 'identity_checkout_already_finished');

        $metadata = ['source' => self::SOURCE, 'order' => $order->uuid];
        $return = $origin.'/'.$order->locale.'/account/identity-verification';
        try {
            if ($order->stripe_session_id === null) {
                // A legacy order with an unknown session must never be retried with changed pricing.
                abort_if($order->stripe_price_id === null, 409, 'identity_checkout_already_finished');
                $this->validatePrice($order);
            }
            $response = $order->stripe_session_id !== null
                ? $this->stripe()->get('checkout/sessions/'.$order->stripe_session_id)
                : $this->stripe()->withHeader('Idempotency-Key', self::SOURCE.':'.$order->uuid)->post('checkout/sessions', [
                    'mode' => 'payment',
                    'currency' => $order->currency,
                    'adaptive_pricing' => ['enabled' => 'false'],
                    'payment_method_types' => ['card'],
                    'client_reference_id' => $order->uuid,
                    'metadata' => $metadata,
                    'payment_intent_data' => ['metadata' => $metadata],
                    'line_items' => [[
                        'price' => $order->stripe_price_id,
                        'quantity' => 1,
                    ]],
                    // Stripe Checkout does not offer an Arabic locale. Keep
                    // the E-Syrians return page localized either way.
                    'locale' => $order->locale === 'ar' ? 'auto' : 'en',
                    'success_url' => $return.'?checkout=success&order='.$order->uuid,
                    'cancel_url' => $return.'?checkout=cancelled&order='.$order->uuid,
                    'expires_at' => $order->created_at->copy()->addHours(3)->timestamp,
                ]);
        } catch (ConnectionException) {
            abort(502, 'identity_checkout_unreachable');
        }

        abort_unless($response->successful(), 502, 'identity_checkout_failed');
        $data = $response->json();
        abort_unless(is_array($data) && $this->matchesSession($order, $data), 502, 'identity_checkout_failed');
        $url = $data['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(($data['status'] ?? null) === 'open', 409, 'identity_checkout_already_finished');
        abort_unless(is_string($url) && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'checkout.stripe.com'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']), 502, 'identity_checkout_failed');

        IdentityVerificationOrder::query()->whereKey($order->getKey())->whereNull('stripe_session_id')
            ->update(['stripe_session_id' => $data['id']]);

        return ['url' => $url];
    }

    private function priceId(): ?string
    {
        $id = config('identity-verification.stripe.price_id');

        return is_string($id) && preg_match('/^price_[A-Za-z0-9]+$/D', $id) === 1 ? $id : null;
    }

    private function validatePrice(IdentityVerificationOrder $order): void
    {
        $response = $this->stripe()->get('prices/'.$order->stripe_price_id);

        abort_unless($response->successful()
            && $response->json('id') === $order->stripe_price_id
            && $response->json('object') === 'price'
            && $response->json('active') === true
            && $response->json('livemode') === $order->livemode
            && $response->json('type') === 'one_time'
            && $response->json('billing_scheme') === 'per_unit'
            && $response->json('custom_unit_amount') === null
            && $response->json('transform_quantity') === null
            && $response->json('currency') === $order->currency
            && $response->json('unit_amount') === $order->amount_minor, 503, 'identity_checkout_price_invalid');
    }

    /** @return array{status: string} */
    public function status(User $user, string $id): array
    {
        $order = IdentityVerificationOrder::query()->where('user_id', $user->id)->where('uuid', $id)->firstOrFail();

        return ['status' => $order->status];
    }

    public function webhook(string $raw, string $header): void
    {
        abort_unless($this->validSignature($raw, $header), 400, 'identity_checkout_invalid_signature');
        $event = json_decode($raw, true);
        abort_unless(is_array($event) && is_string($event['type'] ?? null), 400);
        $object = data_get($event, 'data.object');
        if (! is_array($object) || ($event['livemode'] ?? null) !== $this->liveMode()) {
            return;
        }

        if (in_array($event['type'], ['checkout.session.completed', 'checkout.session.expired'], true)) {
            $this->handleSession($object, $event['type'] === 'checkout.session.expired');
        } elseif ($event['type'] === 'charge.refunded' && ($object['amount_refunded'] ?? 0) > 0) {
            $this->reverse(data_get($object, 'metadata'), $object['payment_intent'] ?? null);
        } elseif ($event['type'] === 'charge.dispute.created') {
            $paymentIntent = $object['payment_intent'] ?? null;
            if (is_string($paymentIntent) && preg_match('/^pi_[A-Za-z0-9]+$/D', $paymentIntent) === 1) {
                try {
                    $response = $this->stripe()->get('payment_intents/'.$paymentIntent);
                } catch (ConnectionException) {
                    abort(503);
                }
                abort_unless($response->successful() && $response->json('livemode') === $this->liveMode(), 503);
                $this->reverse($response->json('metadata'), $paymentIntent);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function handleSession(array $data, bool $expired): void
    {
        $id = $this->orderId($data['metadata'] ?? null);
        if ($id === null) {
            return;
        }
        DB::transaction(function () use ($id, $data, $expired): void {
            $order = IdentityVerificationOrder::query()->where('uuid', $id)->lockForUpdate()->first();
            if (! $order || ! $this->matchesSession($order, $data) || $order->status !== 'pending') {
                return;
            }
            if ($expired && ($data['status'] ?? null) === 'expired') {
                $order->forceFill(['status' => 'expired', 'stripe_session_id' => $data['id']])->save();

                return;
            }
            $intent = $data['payment_intent'] ?? null;
            if ($expired || ($data['status'] ?? null) !== 'complete' || ($data['payment_status'] ?? null) !== 'paid'
                || ! is_string($intent) || preg_match('/^pi_[A-Za-z0-9]+$/D', $intent) !== 1) {
                return;
            }
            $buyer = User::query()->whereKey($order->user_id)->lockForUpdate()->first();
            // Honor completed purchases even when sales are paused, but don't grant an unusable credit.
            $credit = $buyer && ! $buyer->hasPendingDeletion() ? $this->wallet->fulfillPurchase($buyer) : null;
            $order->forceFill([
                'stripe_session_id' => $data['id'],
                'stripe_payment_intent' => $intent,
                'credit_id' => $credit?->id,
                'status' => $credit ? 'paid' : 'needs_refund',
            ])->save();
        }, 3);
    }

    private function reverse(mixed $metadata, mixed $intent): void
    {
        $id = $this->orderId($metadata);
        if ($id === null || ! is_string($intent) || preg_match('/^pi_[A-Za-z0-9]+$/D', $intent) !== 1) {
            return;
        }
        DB::transaction(function () use ($id, $intent): void {
            $order = IdentityVerificationOrder::query()->where('uuid', $id)->lockForUpdate()->first();
            if (! $order || $order->livemode !== $this->liveMode()
                || ($order->stripe_payment_intent !== null && $order->stripe_payment_intent !== $intent)) {
                return;
            }
            // Record reversals even if Stripe delivers the paid event later.
            $order->forceFill(['status' => 'reversed', 'stripe_payment_intent' => $intent])->save();
            if ($order->credit_id !== null) {
                IdentityVerificationCredit::query()->whereKey($order->credit_id)
                    ->whereIn('status', ['available', 'creating', 'pending', 'in_review'])
                    ->update(['status' => 'revoked', 'provider_url' => null]);
            }
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function matchesSession(IdentityVerificationOrder $order, array $data): bool
    {
        $sessionId = $data['id'] ?? null;

        return ($data['object'] ?? null) === 'checkout.session'
            && ($data['mode'] ?? null) === 'payment'
            && $order->livemode === $this->liveMode() && ($data['livemode'] ?? null) === $order->livemode
            && ($data['amount_total'] ?? null) === $order->amount_minor && ($data['currency'] ?? null) === $order->currency
            && ($data['client_reference_id'] ?? null) === $order->uuid
            && $this->orderId($data['metadata'] ?? null) === $order->uuid
            && is_string($sessionId) && preg_match('/^cs_'.($order->livemode ? 'live' : 'test').'_[A-Za-z0-9]+$/D', $sessionId) === 1
            && ($order->stripe_session_id === null || $order->stripe_session_id === $sessionId);
    }

    private function orderId(mixed $metadata): ?string
    {
        $id = is_array($metadata) && ($metadata['source'] ?? null) === self::SOURCE ? ($metadata['order'] ?? null) : null;

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    private function validSignature(string $raw, string $header): bool
    {
        $secret = config('identity-verification.stripe.webhook_secret');
        if (! is_string($secret) || ! str_starts_with($secret, 'whsec_') || strlen($header) > 2048) {
            return false;
        }
        $timestamps = $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if (count($timestamps) !== 1 || ! ctype_digit($timestamps[0])
            || abs(now()->timestamp - (int) $timestamps[0]) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$raw, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function liveMode(): bool
    {
        return app()->environment('production');
    }

    private function returnOrigin(): ?string
    {
        $url = rtrim((string) config('app.frontend_url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['path']) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? null;

        return ($scheme === 'https' || (! $this->liveMode() && $scheme === 'http')) ? $url : null;
    }

    private function stripe(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1/')->asForm()->acceptJson()
            ->withToken((string) config('identity-verification.stripe.key'))
            ->withoutRedirecting()->connectTimeout(3)->timeout(10);
    }
}
