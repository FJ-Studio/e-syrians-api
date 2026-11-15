<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\IdentitySandboxCredit;
use App\Models\IdentityStorePurchase;
use App\Models\IdentityVerificationCredit;
use App\Contracts\IdentitySandboxServiceContract;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class IdentityStoreCheckout
{
    public function __construct(
        private readonly IdentityStoreGateway $stores,
    ) {
    }

    /** @return array{ios: ?string, android: ?string} */
    public function products(IdentitySandboxServiceContract $wallet): array
    {
        if (! $this->supports($wallet) || ! $wallet->providerReady()) {
            return ['ios' => null, 'android' => null];
        }

        return ['ios' => $this->saleProduct('ios'), 'android' => $this->saleProduct('android')];
    }

    /** @return array{ios: ?string, android: ?string} */
    public function recoveryProducts(IdentitySandboxServiceContract $wallet): array
    {
        return $this->supports($wallet)
            ? ['ios' => $this->stores->product('ios'), 'android' => $this->stores->product('android')]
            : ['ios' => null, 'android' => null];
    }

    /** True/false is Play consumption status. StoreKit finalization is done by the app. */
    public function redeem(IdentitySandboxServiceContract $wallet, User $user, string $platform, string $proof): ?bool
    {
        abort_unless($this->supports($wallet), 404);
        $hash = hash('sha256', $proof);
        $existing = $this->find($platform, $hash);
        if ($existing) {
            return $this->finalizeExisting($existing, $user);
        }

        // Verification still works while new sales are paused so a paid purchase
        // made just before the pause can finish. Keys are required for both.
        $product = $this->stores->product($platform);
        abort_if($product === null, 503, 'identity_store_unavailable');
        $lookup = $this->stores->lookup($platform, $product, $proof);
        $account = strtolower((string) $user->uuid);
        $expected = $platform === 'ios' ? $account : hash('sha256', 'e-syrians:verification:'.$account);
        abort_unless(hash_equals($expected, $lookup['owner']), 422, 'identity_store_invalid_purchase');
        abort_if($lookup['state'] === 'pending', 409, 'identity_store_payment_pending');
        abort_unless($lookup['state'] === 'paid', 422, 'identity_store_invalid_purchase');

        [$purchase, $alreadyRegistered] = DB::transaction(function () use ($wallet, $user, $platform, $proof, $hash, $product, $lookup): array {
            // A store purchase can bind to only one account. The row lock serializes
            // concurrent delivery callbacks for that account before wallet mutation.
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($wallet->allowed($current) && ! $current->hasPendingDeletion(), 404);
            $existing = $this->find($platform, $hash);
            if ($existing) {
                $this->assertOwned($existing, $current);

                return [$existing, true];
            }
            abort_if($lookup['consumed'], 422, 'identity_store_invalid_purchase');
            $credit = $wallet->fulfillPurchase($current);
            $purchase = new IdentityStorePurchase();
            $purchase->forceFill([
                'user_id' => $current->id,
                $wallet->mode() === 'live' ? 'credit_id' : 'sandbox_credit_id' => $credit->id,
                'platform' => $platform,
                'environment' => $this->stores->environment(),
                'product_id' => $product,
                'proof' => $proof,
                'proof_hash' => $hash,
                'check_after' => now()->addMinute(),
            ])->save();

            return [$purchase, false];
        }, 3);

        if ($alreadyRegistered) {
            return $this->finalizeExisting($purchase, $user);
        }

        return $platform === 'android'
            ? $this->playFinalization(fn () => $this->consumePlay($purchase))
            : null;
    }

    public function reconcile(): void
    {
        $platforms = array_values(array_filter(['ios', 'android'], $this->stores->configured(...)));
        if ($platforms === []) {
            return;
        }

        IdentityStorePurchase::query()->whereNull('reversed_at')->where('environment', $this->stores->environment())
            ->whereIn('platform', $platforms)->where('check_after', '<=', now())->orderBy('check_after')
            ->limit(30)->get()->each(function (IdentityStorePurchase $purchase): void {
                try {
                    $this->recheck($purchase);
                } catch (Throwable) {
                    // Never log the purchase token, provider response, or any API credentials.
                    Log::warning('Could not reconcile identity store purchase', ['id' => $purchase->id, 'platform' => $purchase->platform]);
                    $unconsumed = $purchase->platform === 'android' && $purchase->play_consumed_at === null;
                    $purchase->forceFill(['check_after' => $unconsumed ? now()->addMinute() : now()->addMinutes(30)])->save();
                }
            });
    }

    private function saleProduct(string $platform): ?string
    {
        return config("identity-verification.store.{$platform}.enabled") === true
            && $this->stores->configured($platform) ? $this->stores->product($platform) : null;
    }

    private function supports(IdentitySandboxServiceContract $wallet): bool
    {
        return $wallet->mode() === ($this->stores->environment() === 'production' ? 'live' : 'sandbox');
    }

    private function find(string $platform, string $hash): ?IdentityStorePurchase
    {
        return IdentityStorePurchase::query()->where('platform', $platform)
            ->where('environment', $this->stores->environment())->where('proof_hash', $hash)->first();
    }

    private function assertOwned(IdentityStorePurchase $purchase, User $user): void
    {
        abort_unless($purchase->user_id === $user->id && $purchase->environment === $this->stores->environment()
            && $purchase->reversed_at === null, 422, 'identity_store_invalid_purchase');
    }

    private function finalizeExisting(IdentityStorePurchase $purchase, User $user): ?bool
    {
        $this->assertOwned($purchase, $user);
        if ($purchase->platform === 'android') {
            if ($purchase->play_consumed_at !== null) {
                return true;
            }
            $consumed = $this->playFinalization(fn () => $this->recheck($purchase));
            $this->assertOwned($purchase->refresh(), $user);

            return $consumed;
        }
        // iOS can now safely finish the local StoreKit transaction. Re-delivery
        // never grants another credit, even if the app stopped after fulfillment.
        return null;
    }

    /** @param callable(): bool $finalize */
    private function playFinalization(callable $finalize): bool
    {
        try {
            return $finalize();
        } catch (HttpExceptionInterface $failure) {
            if ($failure->getStatusCode() !== 503) {
                throw $failure;
            }
            // The verified credit is already durable. Play may be temporarily
            // unavailable for consumption; keep it eligible for reconciliation.
            return false;
        }
    }

    private function consumePlay(IdentityStorePurchase $purchase): bool
    {
        $this->stores->consumeGoogle($purchase->product_id, $purchase->proof);
        $purchase->forceFill(['play_consumed_at' => now(), 'check_after' => now()->addDay()])->save();

        return true;
    }

    private function recheck(IdentityStorePurchase $purchase): bool
    {
        $lookup = $this->stores->lookup($purchase->platform, $purchase->product_id, $purchase->proof);
        if ($lookup['state'] === 'reversed') {
            DB::transaction(function () use ($purchase): void {
                $current = IdentityStorePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
                if ($current->reversed_at !== null) {
                    return;
                }

                $current->forceFill(['reversed_at' => now()])->save();
                // Mirror the Stripe policy: invalidate even a gifted credit if
                // it is unused or in flight, but do not change a completed check.
                $credit = $current->environment === 'production' ? $current->credit_id : $current->sandbox_credit_id;
                $model = $current->environment === 'production' ? IdentityVerificationCredit::class : IdentitySandboxCredit::class;
                if ($credit !== null) {
                    $model::query()->whereKey($credit)
                        ->whereIn('status', ['available', 'creating', 'pending', 'in_review'])
                        ->update(['status' => 'revoked', 'provider_url' => null]);
                }
            }, 3);

            return false;
        }
        if ($purchase->platform === 'android' && $lookup['state'] === 'paid' && ! $lookup['consumed']) {
            return $this->consumePlay($purchase);
        }
        $purchase->forceFill([
            'check_after' => $lookup['state'] === 'paid' ? now()->addDay() : now()->addMinute(),
            ...($lookup['consumed'] && $purchase->play_consumed_at === null ? ['play_consumed_at' => now()] : []),
        ])->save();

        return $lookup['state'] === 'paid';
    }
}
