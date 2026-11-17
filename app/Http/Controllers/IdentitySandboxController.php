<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\IdentityStoreCheckout;
use App\Contracts\IdentitySandboxServiceContract;
use App\Http\Requests\Identity\RedeemStorePurchaseRequest;

class IdentitySandboxController extends Controller
{
    public function __construct(
        private readonly IdentitySandboxServiceContract $sandbox,
        private readonly IdentityStoreCheckout $storeCheckout,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->summary($this->user($request));
    }

    public function history(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return $this->respond($this->wallet()->history($user, (int) ($validated['page'] ?? 1)));
    }

    public function claim(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->wallet()->claim($user);

        return $this->summary($user);
    }

    public function giftCode(Request $request): JsonResponse
    {
        return $this->respond($this->wallet()->giftCode($this->user($request)));
    }

    public function gift(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $code = $request->validate(['code' => ['required', 'string', 'alpha_num:ascii', 'size:32']])['code'];
        $this->wallet()->gift($user, $credit, $code);

        return $this->summary($user);
    }

    public function start(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $locale = $request->validate(['locale' => ['sometimes', 'in:en,ar']])['locale'] ?? 'en';

        return $this->respond(['url' => $this->wallet()->start($user, $credit, $locale)]);
    }

    public function refresh(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $this->wallet()->refresh($user, $credit);

        return $this->summary($user);
    }

    public function redeemStorePurchase(RedeemStorePurchaseRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();
        $playConsumed = $this->storeCheckout()->redeem($this->wallet(), $user, $data['platform'], $data['proof']);

        return $this->respond([
            'fulfilled' => true,
            'play_consumed' => $playConsumed,
            'wallet' => $this->summaryData($user),
            'history' => $this->wallet()->history($user, 1),
        ]);
    }

    protected function summary(User $user): JsonResponse
    {
        return $this->respond($this->summaryData($user));
    }

    /** @return array<string, mixed> */
    protected function summaryData(User $user): array
    {
        return [
            ...$this->wallet()->summary($user),
            'mode' => $this->wallet()->mode(),
            'store_products' => $this->storeCheckout()->products($this->wallet()),
            'store_recovery_products' => $this->storeCheckout()->recoveryProducts($this->wallet()),
        ];
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->wallet()->allowed($user), 404);

        return $user;
    }

    protected function wallet(): IdentitySandboxServiceContract
    {
        return $this->sandbox;
    }

    protected function storeCheckout(): IdentityStoreCheckout
    {
        return $this->storeCheckout;
    }

    /** @param array<string, mixed> $data */
    protected function respond(array $data): JsonResponse
    {
        return ApiService::success($data)->header('Cache-Control', 'private, no-store');
    }
}
