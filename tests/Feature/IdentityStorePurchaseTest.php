<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Services\IdentityStoreCheckout;
use App\Models\IdentityVerificationCredit;

const STORE_IDV_ROOT = '/users/identity-verification';
const STORE_IDV_REDEEM = STORE_IDV_ROOT.'/store-purchases';
const STORE_IDV_SANDBOX = '/users/identity-sandbox';
const STORE_IDV_SKU = 'identity_verification_credit';
const STORE_IDV_APPLE = 'https://api.storekit.apple.com/inApps/v1/transactions/2000000123456789';
const STORE_IDV_APPLE_SANDBOX = 'https://api.storekit-sandbox.apple.com/inApps/v1/transactions/2000000123456789';
const STORE_IDV_GOOGLE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.esyrians.app/purchases/';

beforeEach(function (): void {
    app()->instance('env', 'production');
    Http::preventStrayRequests();
    config([
        'identity-verification.enabled' => true,
        'identity-verification.didit.key' => 'fake-live-key',
        'identity-verification.didit.workflow' => 'fake-live-workflow',
        'identity-verification.store.ios.enabled' => true,
        'identity-verification.store.ios.issuer' => 'example-issuer',
        'identity-verification.store.ios.key_id' => 'example-key-id',
        'identity-verification.store.android.enabled' => true,
    ]);
});

function idvStoreKey(string $platform): void
{
    $options = $platform === 'ios'
        ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
        : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
    $key = openssl_pkey_new($options);
    expect($key)->not->toBeFalse();
    expect(openssl_pkey_export($key, $pem))->toBeTrue();
    $contents = $platform === 'ios' ? $pem : json_encode([
        'client_email' => 'first-party-example@project.invalid',
        'private_key' => $pem,
    ], JSON_THROW_ON_ERROR);

    $file = tmpfile();
    expect($file)->not->toBeFalse();
    fwrite($file, $contents);
    // The service reads a file as it would in production, but the fixture
    // holds only a newly generated test key and is closed at process exit.
    app()->instance('identity-test-key-'.$platform, $file);
    config(["identity-verification.store.{$platform}.key_path" => stream_get_meta_data($file)['uri']]);
}

function appleStoreIdvPayload(User $buyer, array $change = []): array
{
    $json = json_encode(array_replace([
        'transactionId' => '2000000123456789',
        'bundleId' => 'com.esyrians.app',
        'environment' => 'Production',
        'productId' => STORE_IDV_SKU,
        'type' => 'Consumable',
        'quantity' => 1,
        'inAppOwnershipType' => 'PURCHASED',
        'appAccountToken' => (string) $buyer->uuid,
    ], $change), JSON_THROW_ON_ERROR);

    return ['signedTransactionInfo' => 'header.'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.test'];
}

function googleStoreIdvPayload(User $buyer, string $state, bool $consumed = false): array
{
    return [
        'purchaseStateContext' => ['purchaseState' => $state],
        'productLineItem' => [['productId' => STORE_IDV_SKU, 'productOfferDetails' => [
            'quantity' => 1,
            'refundableQuantity' => $state === 'CANCELLED' ? 0 : 1,
            'consumptionState' => $consumed ? 'CONSUMPTION_STATE_CONSUMED' : 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED',
        ]]],
        'obfuscatedExternalAccountId' => hash('sha256', 'e-syrians:verification:'.strtolower((string) $buyer->uuid)),
    ];
}

it('looks up Apple purchases directly, binds them to one account and credits them only once', function (): void {
    idvStoreKey('ios');
    [$buyer, $other] = User::factory()->count(2)->create()->all();
    Http::fake([STORE_IDV_APPLE => Http::response(appleStoreIdvPayload($buyer))]);
    $proof = ['platform' => 'ios', 'proof' => '2000000123456789'];
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertUnprocessable();
    Sanctum::actingAs($buyer);
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.store_products.ios', STORE_IDV_SKU);
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertOk()
        ->assertJsonPath('data.fulfilled', true)
        ->assertJsonPath('data.wallet.credits.0.status', 'available')
        ->assertJsonPath('data.history.items.0.event', 'purchased')
        ->assertJsonPath('data.play_consumed', null)
        ->assertJsonMissingPath('data.proof');
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertOk();
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.credits.0.can_gift', true)
        ->assertJsonPath('data.credits.0.status', 'available')->assertJsonPath('data.account_approved', false);
    Sanctum::actingAs($other);
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertUnprocessable();
    $this->assertDatabaseCount('identity_store_purchases', 1)->assertDatabaseCount('identity_verification_credits', 1)
        ->assertDatabaseCount('identity_verification_credit_events', 1);
    expect(DB::table('identity_store_purchases')->value('proof'))->not->toBe($proof['proof']);
    Http::assertSent(fn (Request $request): bool => $request->url() === STORE_IDV_APPLE && $request->hasHeader('Authorization'));
});

it('does not mint Apple credits for other apps, products, environments, revoked purchases or extra quantities', function (array $change): void {
    idvStoreKey('ios');
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    Http::fake([STORE_IDV_APPLE => Http::response(appleStoreIdvPayload($buyer, $change))]);
    $this->postJson(STORE_IDV_REDEEM, ['platform' => 'ios', 'proof' => '2000000123456789'])->assertUnprocessable();
    $this->assertDatabaseCount('identity_verification_credits', 0);
})->with([
    'another app' => [['bundleId' => 'com.other.app']],
    'another product' => [['productId' => 'other_credit']],
    'wrong environment' => [['environment' => 'Sandbox']],
    'refund' => [['revocationDate' => 1]],
    'two credits' => [['quantity' => 2]],
]);

it('does not grant credits for pending Play purchases and resumes consumption without issuing duplicates', function (): void {
    idvStoreKey('android');
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    $state = 'PENDING';
    $consumed = false;
    $tries = 0;
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token']),
        STORE_IDV_GOOGLE.'productsv2/tokens/example-store-token' => function () use ($buyer, &$state, &$consumed) {
            return Http::response(googleStoreIdvPayload($buyer, $state, $consumed));
        },
        STORE_IDV_GOOGLE.'products/'.STORE_IDV_SKU.'/tokens/example-store-token:consume' => function () use (&$tries, &$consumed) {
            expect(IdentityVerificationCredit::query()->count())->toBe(1);
            if (++$tries === 1) {
                return Http::response([], 502);
            }
            $consumed = true;

            return Http::response(null, 204);
        },
    ]);
    $proof = ['platform' => 'android', 'proof' => 'example-store-token'];
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertConflict();
    $this->assertDatabaseCount('identity_verification_credits', 0);
    $state = 'PURCHASED';
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertOk()
        ->assertJsonPath('data.play_consumed', false)
        ->assertJsonPath('data.wallet.credits.0.status', 'available')
        ->assertJsonPath('data.history.items.0.event', 'purchased');
    $this->travel(75)->seconds();
    resolve(IdentityStoreCheckout::class)->reconcile();
    $this->postJson(STORE_IDV_REDEEM, $proof)->assertOk()->assertJsonPath('data.play_consumed', true);
    expect($tries)->toBe(2);
    $this->assertDatabaseCount('identity_verification_credits', 1)->assertDatabaseCount('identity_verification_credit_events', 1);

    $state = 'CANCELLED';
    $this->travel(25)->hours();
    resolve(IdentityStoreCheckout::class)->reconcile();
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.credits.0.status', 'revoked');
    $this->travelBack();
});

it('isolates store test purchases and lets an allowlisted user spend them in Didit Sandbox', function (): void {
    app()->instance('env', 'testing');
    idvStoreKey('ios');
    [$buyer, $other] = User::factory()->count(2)->create()->all();
    config([
        'identity-sandbox.enabled' => true,
        'identity-sandbox.test_users' => [(string) $buyer->uuid],
        'identity-sandbox.api_key' => 'sandbox-didit-fixture',
        'identity-sandbox.workflow_id' => 'sandbox-didit-workflow',
    ]);
    $session = (string) Str::uuid();
    $attempt = null;
    Http::fake([
        STORE_IDV_APPLE_SANDBOX => Http::response(appleStoreIdvPayload($buyer, ['environment' => 'Sandbox'])),
        'https://verification.didit.me/v3/session/' => function (Request $request) use ($session, &$attempt) {
            $attempt = $request['vendor_data'];
            expect($request['sandbox_scenario'])->toBe('approve');

            return Http::response([
                'session_id' => $session, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'sandbox-didit-workflow', 'url' => 'https://verify.didit.me/session/test',
            ]);
        },
        "https://verification.didit.me/v3/session/{$session}/decision/" => function () use ($session, &$attempt) {
            return Http::response([
                'session_id' => $session, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'sandbox-didit-workflow', 'environment' => 'sandbox', 'status' => 'Approved',
            ]);
        },
    ]);

    $receipt = ['platform' => 'ios', 'proof' => '2000000123456789'];
    Sanctum::actingAs($other);
    $this->postJson(STORE_IDV_SANDBOX.'/store-purchases', $receipt)->assertNotFound();
    Sanctum::actingAs($buyer);
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.store_products.ios', null);
    $this->postJson(STORE_IDV_REDEEM, $receipt)->assertNotFound();
    Http::assertNothingSent();

    $this->getJson(STORE_IDV_SANDBOX)->assertOk()->assertJsonPath('data.store_products.ios', STORE_IDV_SKU);
    $registered = $this->postJson(STORE_IDV_SANDBOX.'/store-purchases', $receipt)->assertOk()
        ->assertJsonPath('data.wallet.mode', 'sandbox')
        ->assertJsonPath('data.history.items.0.event', 'purchased');
    $creditPath = STORE_IDV_SANDBOX.'/'.$registered->json('data.wallet.credits.0.uuid');
    $this->postJson($creditPath.'/start')->assertOk()->assertJsonPath('data.url', 'https://verify.didit.me/session/test');
    $this->postJson($creditPath.'/refresh')->assertOk()->assertJsonPath('data.credits.0.status', 'approved');
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.account_approved', false);
    $this->assertDatabaseCount('identity_verification_credits', 0)
        ->assertDatabaseHas('identity_store_purchases', ['environment' => 'sandbox', 'credit_id' => null]);
});

it('does not accept a Play license-test purchase into production', function (): void {
    idvStoreKey('android');
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token']),
        STORE_IDV_GOOGLE.'productsv2/tokens/example-store-token' => Http::response([
            ...googleStoreIdvPayload($buyer, 'PURCHASED'),
            'testPurchaseContext' => ['fopType' => 'TEST'],
        ]),
    ]);

    $this->postJson(STORE_IDV_REDEEM, ['platform' => 'android', 'proof' => 'example-store-token'])->assertUnprocessable();
    $this->assertDatabaseCount('identity_verification_credits', 0);
});

it('rejects a Play rental or preorder for the same product', function (string $field): void {
    idvStoreKey('android');
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    $details = googleStoreIdvPayload($buyer, 'PURCHASED');
    $details['productLineItem'][0]['productOfferDetails'][$field] = ['fixture' => true];
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token']),
        STORE_IDV_GOOGLE.'productsv2/tokens/example-store-token' => Http::response($details),
    ]);
    $this->postJson(STORE_IDV_REDEEM, ['platform' => 'android', 'proof' => 'example-store-token'])->assertUnprocessable();
    $this->assertDatabaseCount('identity_verification_credits', 0);
})->with(['rentOfferDetails', 'preorderOfferDetails']);

it('recovers a paid transaction using the configured SKU even when sales are paused', function (): void {
    idvStoreKey('ios');
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    config([
        'identity-verification.store.ios.enabled' => false,
        'identity-verification.store.ios.product' => 'fixture_custom_credit',
    ]);
    Http::fake([STORE_IDV_APPLE => Http::response(appleStoreIdvPayload($buyer, ['productId' => 'fixture_custom_credit']))]);
    $this->getJson(STORE_IDV_ROOT)->assertOk()
        ->assertJsonPath('data.store_products.ios', null)
        ->assertJsonPath('data.store_recovery_products.ios', 'fixture_custom_credit');
    $this->postJson(STORE_IDV_REDEEM, ['platform' => 'ios', 'proof' => '2000000123456789'])->assertOk();
    $this->assertDatabaseCount('identity_verification_credits', 1);
});

it('keeps account ID approval separate from a later declined attempt', function (): void {
    $buyer = User::factory()->create();
    Sanctum::actingAs($buyer);
    foreach (['approved' => now()->subDay(), 'declined' => now()] as $status => $attempted) {
        (new IdentityVerificationCredit())->forceFill([
            'uuid' => (string) Str::uuid(), 'status' => $status, 'owner_id' => $buyer->id,
            'source' => IdentityVerificationCredit::SOURCE_PURCHASE, 'consented_at' => $attempted,
            'provider_session_id' => (string) Str::uuid(),
        ])->save();
    }
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.account_approved', true)
        ->assertJsonPath('data.credits.0.status', 'declined')->assertJsonStructure(['data' => ['credits' => [['attempted_at']]]]);
});

it('lets a recipient spend a gifted store credit in Didit without changing community verification', function (): void {
    idvStoreKey('ios');
    [$buyer, $recipient] = User::factory()->count(2)->create(['verified_at' => null])->all();
    $session = (string) Str::uuid();
    $attempt = null;
    $decision = 'Not Started';
    Http::fake([
        STORE_IDV_APPLE => Http::response(appleStoreIdvPayload($buyer)),
        'https://verification.didit.me/v3/session/' => function (Request $request) use ($session, &$attempt) {
            $attempt = $request['vendor_data'];

            return Http::response([
                'session_id' => $session, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'fake-live-workflow', 'url' => 'https://verify.didit.me/session/test',
            ]);
        },
        "https://verification.didit.me/v3/session/{$session}/decision/" => function () use ($session, &$attempt, &$decision) {
            return Http::response([
                'session_id' => $session, 'session_kind' => 'user', 'vendor_data' => $attempt,
                'workflow_id' => 'fake-live-workflow', 'environment' => 'live', 'status' => $decision,
            ]);
        },
    ]);
    Sanctum::actingAs($recipient);
    $code = $this->postJson(STORE_IDV_ROOT.'/gift-code')->assertOk()->json('data.code');
    Sanctum::actingAs($buyer);
    $this->postJson(STORE_IDV_REDEEM, ['platform' => 'ios', 'proof' => '2000000123456789'])->assertOk();
    $credit = IdentityVerificationCredit::query()->sole();
    $path = STORE_IDV_ROOT.'/'.$credit->uuid;
    $this->postJson($path.'/gift', ['code' => $code])->assertOk()->assertJsonCount(0, 'data.credits');

    Sanctum::actingAs($recipient);
    $this->getJson(STORE_IDV_ROOT)->assertOk()->assertJsonPath('data.credits.0.can_gift', false);
    $this->postJson($path.'/start', ['consent' => true])->assertOk()
        ->assertJsonPath('data.url', 'https://verify.didit.me/session/test');
    $decision = 'Approved';
    $this->postJson($path.'/refresh')->assertOk()->assertJsonPath('data.credits.0.status', 'approved')
        ->assertJsonPath('data.account_approved', true);
    expect($recipient->refresh()->verified_at)->toBeNull();
});
