<?php

declare(strict_types=1);

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use App\Models\IdentityVerificationOrder;
use App\Jobs\HardDeleteExpiredAccountsJob;
use App\Models\IdentityVerificationCredit;
use App\Services\IdentityVerificationService;
use App\Contracts\AccountDeletionServiceContract;

const IDV_ROUTE = '/users/identity-verification';
const IDV_STRIPE_WEBHOOK = '/webhooks/stripe/identity-verification';
const IDV_STRIPE_SESSIONS = 'https://api.stripe.com/v1/checkout/sessions';
const IDV_STRIPE_PRICE = 'price_TestIdvEur';
const IDV_STRIPE_PRICE_URL = 'https://api.stripe.com/v1/prices/'.IDV_STRIPE_PRICE;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'app.frontend_url' => 'https://www.example.test',
        'identity-verification.enabled' => true,
        'identity-verification.didit.key' => 'fake-didit-live-key',
        'identity-verification.didit.workflow' => 'fake-didit-live-workflow',
        'identity-verification.stripe.enabled' => true,
        'identity-verification.stripe.key' => 'sk_test_example',
        'identity-verification.stripe.price_id' => IDV_STRIPE_PRICE,
        'identity-verification.stripe.webhook_secret' => 'whsec_example',
    ]);
});

function idvStripeSession(string $order, array $changes = []): array
{
    return array_replace([
        'id' => 'cs_test_test123',
        'object' => 'checkout.session',
        'mode' => 'payment',
        'livemode' => false,
        'client_reference_id' => $order,
        'metadata' => ['source' => 'e-syrians-id-verification', 'order' => $order],
        'amount_total' => 300,
        'currency' => 'eur',
        'payment_status' => 'paid',
        'status' => 'complete',
        'payment_intent' => 'pi_123',
    ], $changes);
}

function postIdvStripeEvent(TestCase $test, string $type, array $object, int $offset = 0, bool $valid = true): TestResponse
{
    $body = json_encode(['type' => $type, 'livemode' => $object['livemode'] ?? false, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $time = now()->timestamp + $offset;
    $signature = hash_hmac('sha256', $time.'.'.$body, $valid ? 'whsec_example' : 'wrong');

    return $test->call('POST', IDV_STRIPE_WEBHOOK, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
    ], $body);
}

function idvPrice(array $changes = []): array
{
    return array_replace([
        'id' => IDV_STRIPE_PRICE,
        'object' => 'price',
        'active' => true,
        'livemode' => false,
        'type' => 'one_time',
        'billing_scheme' => 'per_unit',
        'currency' => 'eur',
        'unit_amount' => 300,
    ], $changes);
}

function idvOrder(User $buyer, string $currency = 'eur'): IdentityVerificationOrder
{
    return IdentityVerificationOrder::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $buyer->id,
        'amount_minor' => 300, 'currency' => $currency, 'locale' => 'en', 'livemode' => false,
    ]);
}

it('creates and reuses one authenticated fixed-price Checkout order without issuing a credit on redirect', function (): void {
    $order = (string) Str::uuid();
    $checkoutUrl = 'https://checkout.stripe.com/c/pay/cs_test_test123';
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'ar'])->assertUnauthorized();
    [$buyer, $other] = User::factory()->count(2)->create()->all();
    Sanctum::actingAs($buyer);
    $this->getJson(IDV_ROUTE)->assertOk()->assertJsonPath('data.price_minor', 300)->assertJsonPath('data.currency', 'EUR');
    Http::fake([
        IDV_STRIPE_PRICE_URL => Http::response(idvPrice()),
        IDV_STRIPE_SESSIONS => function (Request $request) use ($order, $checkoutUrl) {
            expect($request['line_items'])->toBe([['price' => IDV_STRIPE_PRICE, 'quantity' => 1]])
                ->and($request['currency'])->toBe('eur')
                ->and($request['locale'])->toBe('auto')
                ->and($request['success_url'])->toBe('https://www.example.test/ar/account/identity-verification?checkout=success&order='.$order)
                ->and($request->hasHeader('Idempotency-Key', 'e-syrians-id-verification:'.$order))->toBeTrue();

            return Http::response(idvStripeSession($order, ['status' => 'open', 'url' => $checkoutUrl]));
        },
        IDV_STRIPE_SESSIONS.'/cs_test_test123' => Http::response(idvStripeSession($order, ['status' => 'open', 'url' => $checkoutUrl])),
    ]);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'ar', 'price_minor' => 1, 'price_id' => 'price_other'])
        ->assertOk()->assertJsonPath('data.url', $checkoutUrl);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'en'])->assertOk();
    $this->getJson(IDV_ROUTE.'/checkout/'.$order)->assertOk()->assertJsonPath('data.status', 'pending');
    $this->assertDatabaseCount('identity_verification_orders', 1);
    $this->assertDatabaseCount('identity_verification_credits', 0);
    Sanctum::actingAs($other);
    $this->getJson(IDV_ROUTE.'/checkout/'.$order)->assertNotFound();
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'ar'])->assertNotFound();
});

it('rejects the wrong Stripe price before creating a Checkout Session', function (array $changes): void {
    Sanctum::actingAs(User::factory()->create());
    Http::fake([IDV_STRIPE_PRICE_URL => Http::response(idvPrice($changes))]);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => (string) Str::uuid(), 'locale' => 'en'])->assertStatus(503);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === IDV_STRIPE_PRICE_URL);
    $this->assertDatabaseCount('identity_verification_credits', 0);
})->with([
    'wrong currency' => [['currency' => 'usd']],
    'wrong amount' => [['unit_amount' => 301]],
    'wrong mode' => [['livemode' => true]],
    'inactive' => [['active' => false]],
    'recurring' => [['type' => 'recurring']],
]);

it('keeps an order tied to the original Price when checkout creation is retried', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $order = (string) Str::uuid();
    $attempts = 0;
    Http::fake([
        IDV_STRIPE_PRICE_URL => Http::response(idvPrice()),
        IDV_STRIPE_SESSIONS => function (Request $request) use ($order, &$attempts) {
            expect($request['line_items'][0]['price'])->toBe(IDV_STRIPE_PRICE);
            if (++$attempts === 1) {
                return Http::response([], 500);
            }

            return Http::response(idvStripeSession($order, ['status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/example']));
        },
    ]);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'en'])->assertStatus(502);
    config(['identity-verification.stripe.price_id' => 'price_New']);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => $order, 'locale' => 'en'])->assertOk();
    expect($attempts)->toBe(2);
});

it('still fulfills a previously opened USD order based on its original currency', function (): void {
    $order = idvOrder(User::factory()->create(), 'usd');
    postIdvStripeEvent($this, 'checkout.session.completed', idvStripeSession($order->uuid))->assertNoContent();
    expect($order->refresh()->status)->toBe('pending');
    postIdvStripeEvent($this, 'checkout.session.completed', idvStripeSession($order->uuid, ['currency' => 'usd']))->assertNoContent();
    expect($order->refresh()->status)->toBe('paid');
});

it('rejects invalid and stale signatures, unrelated purchases, unpaid sessions, and mismatched amounts or modes', function (): void {
    $order = idvOrder(User::factory()->create());
    $session = idvStripeSession($order->uuid);
    postIdvStripeEvent($this, 'checkout.session.completed', $session, valid: false)->assertStatus(400);
    postIdvStripeEvent($this, 'checkout.session.completed', $session, offset: -301)->assertStatus(400);
    foreach ([
        ['amount_total' => 1],
        ['currency' => 'usd'],
        ['payment_status' => 'unpaid'],
        ['livemode' => true],
        ['metadata' => ['source' => 'e-syrians-donate', 'order' => $order->uuid]],
        ['client_reference_id' => (string) Str::uuid()],
    ] as $changes) {
        postIdvStripeEvent($this, 'checkout.session.completed', array_replace($session, $changes))->assertNoContent();
    }
    $this->assertDatabaseCount('identity_verification_credits', 0);
    expect($order->refresh()->status)->toBe('pending');
});

it('fails closed in production with a Stripe test key', function (): void {
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    app()->instance('env', 'production');
    $this->getJson(IDV_ROUTE)->assertOk()->assertJsonPath('data.can_purchase', false);
    $this->postJson(IDV_ROUTE.'/checkout', ['order' => (string) Str::uuid(), 'locale' => 'en'])->assertStatus(503);
    $this->assertDatabaseCount('identity_verification_orders', 0);
    Http::assertNothingSent();
});

it('fulfills a paid order once even after checkout is disabled, and allows a single private transfer', function (): void {
    [$buyer, $recipient] = User::factory()->count(2)->create()->all();
    $order = idvOrder($buyer);
    config(['identity-verification.stripe.enabled' => false]);
    $session = idvStripeSession($order->uuid);
    postIdvStripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    postIdvStripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    $credit = IdentityVerificationCredit::query()->sole();
    expect($credit->owner_id)->toBe($buyer->id)->and($order->refresh()->status)->toBe('paid');
    $this->assertDatabaseCount('identity_sandbox_credits', 0);
    Sanctum::actingAs($recipient);
    $code = $this->postJson(IDV_ROUTE.'/gift-code')->assertOk()->json('data.code');
    Sanctum::actingAs($buyer);
    $this->getJson(IDV_ROUTE)->assertOk()->assertJsonPath('data.can_claim', false)
        ->assertJsonPath('data.credits.0.can_gift', true);
    $this->postJson(IDV_ROUTE.'/'.$credit->uuid.'/gift', ['code' => $code])->assertOk()->assertJsonPath('data.credits', []);
    $this->getJson(IDV_ROUTE.'/history')->assertOk()->assertJsonPath('data.items.0.event', 'gift_sent')
        ->assertJsonPath('data.items.1.event', 'purchased');
    Sanctum::actingAs($recipient);
    $this->getJson(IDV_ROUTE)->assertOk()->assertJsonPath('data.credits.0.can_gift', false);
    $this->getJson(IDV_ROUTE.'/history')->assertOk()->assertJsonPath('data.items.0.event', 'gift_received');
});

it('flags a late payment for refund review when the buyer can no longer access their wallet', function (string $accountState): void {
    $buyer = User::factory()->create();
    $order = idvOrder($buyer);
    if ($accountState === 'pending deletion') {
        $buyer->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(15)])->save();
    } else {
        $buyer->delete();
    }

    $session = idvStripeSession($order->uuid);
    postIdvStripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    postIdvStripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();

    expect($order->refresh()->status)->toBe('needs_refund')
        ->and($order->stripe_payment_intent)->toBe('pi_123')
        ->and($order->credit_id)->toBeNull();
    $this->assertDatabaseCount('identity_verification_credits', 0);
    $this->assertDatabaseCount('identity_verification_credit_events', 0);
})->with(['pending deletion', 'soft-deleted']);

it('revokes a refunded credit and ignores a paid event delivered after a reversal', function (): void {
    $buyer = User::factory()->create();
    $order = idvOrder($buyer);
    $session = idvStripeSession($order->uuid);
    $refund = ['metadata' => $session['metadata'], 'payment_intent' => 'pi_123', 'amount_refunded' => 300];
    postIdvStripeEvent($this, 'checkout.session.completed', $session)->assertNoContent();
    postIdvStripeEvent($this, 'charge.refunded', $refund)->assertNoContent();
    expect(IdentityVerificationCredit::query()->sole()->status)->toBe('revoked')
        ->and($order->refresh()->status)->toBe('reversed');

    $laterOrder = idvOrder($buyer);
    $laterSession = idvStripeSession($laterOrder->uuid, ['id' => 'cs_test_other', 'payment_intent' => 'pi_other']);
    postIdvStripeEvent($this, 'charge.refunded', [
        'metadata' => $laterSession['metadata'], 'payment_intent' => 'pi_other', 'amount_refunded' => 300,
    ])->assertNoContent();
    postIdvStripeEvent($this, 'checkout.session.completed', $laterSession)->assertNoContent();
    expect($laterOrder->refresh()->status)->toBe('reversed');
    $this->assertDatabaseCount('identity_verification_credits', 1);
});

it('requires consent, rejects a sandbox provider session, and stores only the correlated live result', function (): void {
    $user = User::factory()->create(['verified_at' => null]);
    $admin = User::factory()->create()->assignRole('admin');
    resolve(IdentityVerificationService::class)->grant($admin, $user, 'Authorized live grant');
    $credit = IdentityVerificationCredit::query()->sole();
    Sanctum::actingAs($user);
    $path = IDV_ROUTE.'/'.$credit->uuid;
    $this->postJson($path.'/start')->assertUnprocessable();
    Http::assertNothingSent();
    $sessionId = (string) Str::uuid();
    $providerMode = 'sandbox';
    $status = 'Not Started';
    $attempt = null;
    Http::fake([
        'https://verification.didit.me/v3/session/' => function (Request $request) use (&$attempt, $sessionId) {
            $attempt = $request['vendor_data'];
            expect($request->data())->not->toHaveKey('sandbox_scenario')->not->toHaveKey('contact_details');

            return Http::response([
                'session_id' => $sessionId, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'fake-didit-live-workflow', 'url' => 'https://verify.didit.me/session/test',
            ]);
        },
        "https://verification.didit.me/v3/session/{$sessionId}/decision/" => function () use (&$attempt, &$providerMode, &$status, $sessionId) {
            return Http::response([
                'session_id' => $sessionId, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'fake-didit-live-workflow', 'environment' => $providerMode, 'status' => $status,
                'id_verifications' => [['full_name' => 'PRIVATE PROVIDER DATA']],
            ]);
        },
    ]);
    $this->postJson($path.'/start', ['consent' => true])->assertStatus(502);
    $providerMode = 'live';
    $this->postJson($path.'/start', ['consent' => true])->assertOk();
    $status = 'Approved';
    $this->postJson($path.'/refresh')->assertOk()->assertJsonPath('data.credits.0.status', 'approved')
        ->assertDontSee('PRIVATE PROVIDER DATA');
    expect($credit->refresh()->provider_url)->toBeNull()->and($user->refresh()->verified_at)->toBeNull();
    expect(DB::table('identity_verification_credit_events')->where('event', 'admin_granted')->count())->toBe(1);
});

it('retains the provider session reference on erasure failure and retries after the feature is disabled', function (): void {
    $user = User::factory()->create(['deletion_scheduled_for' => now()->subDay()]);
    $session = (string) Str::uuid();
    $credit = new IdentityVerificationCredit();
    $credit->forceFill([
        'uuid' => (string) Str::uuid(), 'owner_id' => $user->id, 'source' => 'admin_grant',
        'status' => 'approved', 'provider_session_id' => $session,
    ])->save();
    config(['identity-verification.enabled' => false]);
    $endpoint = "https://verification.didit.me/v3/session/{$session}/delete/";
    $status = 503;
    Http::fake([
        $endpoint => function (Request $request) use (&$status) {
            expect($request->method())->toBe('DELETE')->and($request['deletion_instruction'])->toBe('privacy_erasure');

            return Http::response([], $status);
        },
    ]);
    $service = resolve(AccountDeletionServiceContract::class);
    (new HardDeleteExpiredAccountsJob())->handle($service);
    expect($credit->fresh()?->provider_session_id)->toBe($session);
    $status = 200;
    (new HardDeleteExpiredAccountsJob())->handle($service);
    expect($credit->fresh())->toBeNull();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});
