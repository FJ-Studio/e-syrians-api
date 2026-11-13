<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const IDENTITY_SANDBOX_ROUTE = '/users/identity-sandbox';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'identity-sandbox.enabled' => true,
        'identity-sandbox.api_key' => 'test-key',
        'identity-sandbox.workflow_id' => 'test-individual-workflow',
    ]);
});

it('requires sign-in, explicit test access, and a non-production environment', function (): void {
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertNotFound();
    config(['identity-sandbox.test_users' => [(string) $user->uuid], 'identity-sandbox.enabled' => false]);
    $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertNotFound();
    config(['identity-sandbox.enabled' => true]);
    app()->instance('env', 'production');
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertNotFound();
    Http::assertNothingSent();
});

it('grants a single test credit at the fixed display price, with no payment', function (): void {
    $user = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $user->uuid], 'identity-sandbox.api_key' => null]);
    Sanctum::actingAs($user);
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertOk()->assertJsonPath('data.can_claim', true);
    $response = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim', ['price_minor' => 1])->assertOk()
        ->assertJsonPath('data.price_minor', 300)->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.can_claim', false)->assertJsonPath('data.provider_ready', false)
        ->assertJsonPath('data.credits.0.status', 'available');
    $id = $response->json('data.credits.0.uuid');
    $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertOk()->assertJsonCount(1, 'data.credits')
        ->assertJsonPath('data.credits.0.uuid', $id);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/start")->assertServiceUnavailable();
    Http::assertNothingSent();
});

it('transfers an unused credit once via a private opt-in code and removes it from the sender', function (): void {
    [$sender, $recipient, $other] = User::factory()->count(3)->create()->all();
    config(['identity-sandbox.test_users' => [(string) $sender->uuid, (string) $recipient->uuid, (string) $other->uuid]]);
    Sanctum::actingAs($recipient);
    $code = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/gift-code')->assertOk()->json('data.code');
    expect(DB::table('identity_sandbox_gift_requests')->value('code_hash'))->toBe(hash('sha256', $code));
    Sanctum::actingAs($sender);
    $id = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertOk()->json('data.credits.0.uuid');
    UserBlock::query()->create(['blocker_id' => $recipient->id, 'blocked_id' => $sender->id]);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/gift", ['code' => $code])->assertUnprocessable();
    UserBlock::query()->where('blocker_id', $recipient->id)->delete();
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/gift", ['code' => $code])->assertOk()
        ->assertJsonCount(0, 'data.credits')->assertJsonPath('data.can_claim', false);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/start")->assertNotFound();
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/refresh")->assertNotFound();
    Sanctum::actingAs($other);
    $otherId = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertOk()->json('data.credits.0.uuid');
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$otherId}/gift", ['code' => $code])->assertUnprocessable();
    $otherCode = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/gift-code')->assertOk()->json('data.code');
    Sanctum::actingAs($recipient);
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertOk()->assertJsonPath('data.credits.0.uuid', $id)
        ->assertJsonPath('data.credits.0.can_gift', false);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$id}/gift", ['code' => $otherCode])->assertConflict();
});

it('resumes the hosted session and trusts only the matched sandbox decision, without changing peer verification', function (): void {
    $user = User::factory()->create();
    $originalVerifiedAt = $user->verified_at;
    config(['identity-sandbox.test_users' => [(string) $user->uuid]]);
    Sanctum::actingAs($user);
    $credit = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertOk()->json('data.credits.0.uuid');
    $id = (string) Str::uuid();
    $url = 'https://verify.didit.me/session/secret-test-url';
    $vendorData = null;
    $decisionVendorData = 'not-the-attempt';
    Http::fake([
        'https://verification.didit.me/v3/session/' => function (Request $request) use (&$vendorData, $id, $url) {
            $vendorData = $request['vendor_data'];
            expect($request->data())->toBe([
                'workflow_id' => 'test-individual-workflow',
                'vendor_data' => $vendorData,
                'language' => 'ar',
                'sandbox_scenario' => 'approve',
            ])->and(Str::isUuid($vendorData))->toBeTrue();

            return Http::response(['session_id' => $id, 'url' => $url, 'vendor_data' => $vendorData, 'workflow_id' => 'test-individual-workflow'], 201);
        },
        "https://verification.didit.me/v3/session/{$id}/decision/" => function () use (&$decisionVendorData, $id) {
            return Http::response(['session_kind' => 'user', 'session_id' => $id, 'vendor_data' => $decisionVendorData, 'workflow_id' => 'test-individual-workflow', 'status' => 'APPROVED', 'id_verifications' => [['full_name' => 'Synthetic Test Name']]]);
        },
    ]);
    $start = IDENTITY_SANDBOX_ROUTE."/{$credit}/start";
    $this->postJson($start, ['locale' => 'ar'])->assertOk()->assertJsonPath('data.url', $url);
    $this->postJson($start)->assertOk()->assertJsonPath('data.url', $url);
    Http::assertSentCount(1);
    expect(DB::table('identity_sandbox_credits')->where('uuid', $credit)->value('provider_url'))->not->toBe($url);
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertOk()->assertJsonMissingPath('data.credits.0.url');
    $refresh = IDENTITY_SANDBOX_ROUTE."/{$credit}/refresh";
    $this->postJson($refresh)->assertStatus(502);
    $decisionVendorData = $vendorData;
    $this->postJson($refresh, ['status' => 'declined'])->assertOk()->assertJsonPath('data.credits.0.status', 'approved')
        ->assertDontSee('Synthetic Test Name');
    $this->postJson($start)->assertConflict();
    expect($user->fresh()->verified_at)->toEqual($originalVerifiedAt);
});

it('reuses the attempt after a failed start and returns the credit when Didit expires it', function (): void {
    $user = User::factory()->create();
    config(['identity-sandbox.test_users' => [(string) $user->uuid], 'identity-sandbox.scenario' => 'decline_document_expired']);
    Sanctum::actingAs($user);
    $credit = $this->postJson(IDENTITY_SANDBOX_ROUTE.'/claim')->assertOk()->json('data.credits.0.uuid');
    $session = (string) Str::uuid();
    $attempts = [];
    Http::fake([
        'https://verification.didit.me/v3/session/' => function (Request $request) use (&$attempts, $session) {
            $attempts[] = $request['vendor_data'];
            expect($request['sandbox_scenario'])->toBe('decline_document_expired');

            return Http::response([
                'session_id' => $session,
                'url' => count($attempts) === 1 ? 'https://verify.didit.me.example.test/unsafe' : 'https://verify.didit.me/session/test',
                'session_kind' => count($attempts) === 2 ? 'business' : 'user',
                'workflow_id' => 'test-individual-workflow',
                'vendor_data' => $request['vendor_data'],
            ], 201);
        },
        "https://verification.didit.me/v3/session/{$session}/decision/" => function () use ($session, &$attempts) {
            return Http::response(['session_id' => $session, 'session_kind' => 'user', 'workflow_id' => 'test-individual-workflow', 'vendor_data' => $attempts[0], 'status' => 'Expired']);
        },
    ]);

    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$credit}/start")->assertStatus(502);
    $this->getJson(IDENTITY_SANDBOX_ROUTE)->assertJsonPath('data.credits.0.status', 'creating');
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$credit}/start")->assertStatus(502);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$credit}/start")->assertOk();
    expect($attempts[0])->toBe($attempts[1])->toBe($attempts[2]);
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$credit}/refresh")->assertOk()->assertJsonPath('data.credits.0.status', 'available');
    $this->postJson(IDENTITY_SANDBOX_ROUTE."/{$credit}/start")->assertOk();
    expect($attempts[3])->not->toBe($attempts[0]);
});
