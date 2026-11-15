<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\IdentityStorePurchase;
use App\Models\IdentityVerificationCredit;

class IdentityStoreCheckout
{
    public function __construct(
        private readonly IdentityStoreGateway $stores,
        private readonly IdentityVerificationService $wallet,
    ) {
    }

    /** @return array{ios: ?string, android: ?string} */
    public function products(): array
    {
        return ['ios' => $this->saleProduct('ios'), 'android' => $this->saleProduct('android')];
    }

    public function redeem(User $user, string $platform, string $proof): void
    {
        $hash = hash('sha256', $proof);
        $existing = $this->find($platform, $hash);
        if ($existing) {
            $this->finalizeExisting($existing, $user);

            return;
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

        $purchase = DB::transaction(function () use ($user, $platform, $proof, $hash, $product, $lookup): IdentityStorePurchase {
            // A store purchase can bind to only one account. The row lock serializes
            // concurrent delivery callbacks for that account before wallet mutation.
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->wallet->allowed($current), 404);
            $existing = $this->find($platform, $hash);
            if ($existing) {
                $this->assertOwned($existing, $current);

                return $existing;
            }
            abort_if($lookup['consumed'], 422, 'identity_store_invalid_purchase');
            $credit = $this->wallet->fulfillPurchase($current);
            $purchase = new IdentityStorePurchase();
            $purchase->forceFill([
                'user_id' => $current->id,
                'credit_id' => $credit->id,
                'platform' => $platform,
                'environment' => $this->stores->environment(),
                'product_id' => $product,
                'proof' => $proof,
                'proof_hash' => $hash,
                'check_after' => now()->addMinutes(5),
            ])->save();

            return $purchase;
        }, 3);

        if ($platform === 'android') {
            $this->stores->consumeGoogle($purchase->product_id, $proof);
        }
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
                    $purchase->forceFill(['check_after' => now()->addMinutes(30)])->save();
                }
            });
    }

    private function saleProduct(string $platform): ?string
    {
        return config("identity-verification.store.{$platform}.enabled") === true
            && $this->wallet->providerReady()
            && $this->stores->configured($platform) ? $this->stores->product($platform) : null;
    }

    private function find(string $platform, string $hash): ?IdentityStorePurchase
    {
        return IdentityStorePurchase::query()->where('platform', $platform)->where('proof_hash', $hash)->first();
    }

    private function assertOwned(IdentityStorePurchase $purchase, User $user): void
    {
        abort_unless($purchase->user_id === $user->id && $purchase->environment === $this->stores->environment()
            && $purchase->reversed_at === null, 422, 'identity_store_invalid_purchase');
    }

    private function finalizeExisting(IdentityStorePurchase $purchase, User $user): void
    {
        $this->assertOwned($purchase, $user);
        if ($purchase->platform === 'android') {
            $this->recheck($purchase);
            $this->assertOwned($purchase->refresh(), $user);
        }
        // iOS can now safely finish the local StoreKit transaction. Re-delivery
        // never grants another credit, even if the app stopped after fulfillment.
    }

    private function recheck(IdentityStorePurchase $purchase): void
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
                if ($current->credit_id !== null) {
                    IdentityVerificationCredit::query()->whereKey($current->credit_id)
                        ->whereIn('status', ['available', 'creating', 'pending', 'in_review'])
                        ->update(['status' => 'revoked', 'provider_url' => null]);
                }
            }, 3);

            return;
        }
        if ($purchase->platform === 'android' && $lookup['state'] === 'paid' && ! $lookup['consumed']) {
            $this->stores->consumeGoogle($purchase->product_id, $purchase->proof);
        }
        $purchase->forceFill(['check_after' => $lookup['state'] === 'paid' ? now()->addDay() : now()->addMinutes(30)])->save();
    }
}
