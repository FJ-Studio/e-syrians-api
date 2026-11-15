<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Services\IdentityStoreCheckout;
use App\Services\IdentityVerificationService;
use App\Services\IdentityVerificationCheckout;

class IdentityVerificationController extends IdentitySandboxController
{
    public function __construct(
        private readonly IdentityVerificationService $wallet,
        private readonly IdentityVerificationCheckout $checkout,
        private readonly IdentityStoreCheckout $storeCheckout,
    ) {
        parent::__construct($wallet);
    }

    protected function summary(User $user): JsonResponse
    {
        return $this->respond([
            ...$this->wallet->summary($user),
            'mode' => 'live',
            'account_approved' => $user->approvedIdentityChecks()->exists(),
            'store_products' => $this->storeCheckout->products(),
            'can_purchase' => $this->checkout->ready(),
            'price_minor' => IdentityVerificationCheckout::AMOUNT,
            'currency' => strtoupper(IdentityVerificationCheckout::CURRENCY),
        ]);
    }

    public function start(Request $request, string $credit): JsonResponse
    {
        $this->user($request);
        $request->validate(['consent' => ['required', 'accepted']]);

        return parent::start($request, $credit);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['order' => ['required', 'uuid'], 'locale' => ['required', 'in:en,ar']]);

        return $this->respond($this->checkout->start($user, $data['order'], $data['locale']));
    }

    public function checkoutStatus(Request $request, string $order): JsonResponse
    {
        return $this->respond($this->checkout->status($this->user($request), $order));
    }

    public function redeemStorePurchase(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'platform' => ['required', 'in:ios,android'],
            'proof' => ['required', 'string', 'max:2048', 'regex:/^[!-~]+$/D'],
        ]);
        $this->storeCheckout->redeem($user, $data['platform'], $data['proof']);

        return $this->respond(['fulfilled' => true]);
    }

    public function stripeWebhook(Request $request): Response
    {
        $this->checkout->webhook($request->getContent(), (string) $request->header('Stripe-Signature'));

        return response()->noContent();
    }
}
