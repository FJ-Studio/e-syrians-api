<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Services\IdentityStoreGateway;
use App\Services\IdentityStoreCheckout;
use App\Services\AppStoreSandboxService;
use App\Services\IdentityVerificationService;
use App\Services\IdentityVerificationCheckout;
use App\Contracts\IdentitySandboxServiceContract;

class IdentityVerificationController extends IdentitySandboxController
{
    public function __construct(
        IdentityVerificationService $wallet,
        private readonly IdentityVerificationCheckout $checkout,
        IdentityStoreCheckout $storeCheckout,
    ) {
        parent::__construct($wallet, $storeCheckout);
    }

    protected function wallet(): IdentitySandboxServiceContract
    {
        return $this->storeSandbox() ? resolve(AppStoreSandboxService::class) : parent::wallet();
    }

    protected function storeCheckout(): IdentityStoreCheckout
    {
        return $this->storeSandbox()
            ? new IdentityStoreCheckout(new IdentityStoreGateway('sandbox')) : parent::storeCheckout();
    }

    private function storeSandbox(): bool
    {
        $environment = request()->header('X-Identity-Store-Environment');
        abort_unless($environment === null || in_array($environment, ['production', 'sandbox'], true), 400);

        return $environment === 'sandbox';
    }

    /** @return array<string, mixed> */
    protected function summaryData(User $user): array
    {
        return [
            ...parent::summaryData($user),
            'account_approved' => $user->approvedIdentityChecks()->exists(),
            'can_purchase' => ! $this->storeSandbox() && $this->checkout->ready(),
            'price_minor' => IdentityVerificationCheckout::AMOUNT,
            'currency' => strtoupper(IdentityVerificationCheckout::CURRENCY),
        ];
    }

    public function start(Request $request, string $credit): JsonResponse
    {
        $this->user($request);
        $request->validate(['consent' => ['required', 'accepted']]);

        return parent::start($request, $credit);
    }

    public function createCheckout(Request $request): JsonResponse
    {
        abort_if($this->storeSandbox(), 404);
        $user = $this->user($request);
        $data = $request->validate(['order' => ['required', 'uuid'], 'locale' => ['required', 'in:en,ar']]);

        return $this->respond($this->checkout->start($user, $data['order'], $data['locale']));
    }

    public function checkoutStatus(Request $request, string $order): JsonResponse
    {
        abort_if($this->storeSandbox(), 404);
        return $this->respond($this->checkout->status($this->user($request), $order));
    }

    public function stripeWebhook(Request $request): Response
    {
        $this->checkout->webhook($request->getContent(), (string) $request->header('Stripe-Signature'));

        return response()->noContent();
    }
}
