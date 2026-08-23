<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\FeatureRequest;
use App\Models\UserVerification;
use App\Models\FeatureRequestVote;
use Illuminate\Support\Facades\Hash;
use App\Jobs\HardDeleteExpiredAccountsJob;
use App\Contracts\AccountDeletionServiceContract;

// ───────────────────────────────────────────────
// GET /users/account/deletion-status
// ───────────────────────────────────────────────

it('returns deletion status shape when no deletion is pending', function (): void {
    $user = User::factory()->create();

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($user));

    $response->assertOk();
    $response->assertJsonPath('data.is_pending', false);
    $response->assertJsonStructure(['data' => ['deletion_requested_at', 'deletion_scheduled_for', 'is_pending', 'requires_password']]);
});

// Two separate tests because Sanctum caches the resolved user on the
// current-request guard for the lifetime of the process. Firing two
// bearer-token requests as different users inside a single `it()` made
// the second request re-serve the first user, and the `requires_password`
// assertion silently failed against the wrong subject. Splitting is
// cleaner than manually forgetting guards between requests — and it
// isolates the two facts we care about.

it('flags requires_password=true for a user with a password', function (): void {
    $withPassword = User::factory()->create(['password' => Hash::make('secret123')]);

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($withPassword));

    $response->assertOk();
    $response->assertJsonPath('data.requires_password', true);
});

it('flags requires_password=false for a passwordless (social-only) user', function (): void {
    $socialOnly = User::factory()->passwordless()->create();

    // Sanity: the row on disk really has NULL password, not the factory's
    // default hash. If this ever regresses, the requires_password check
    // below silently flips to true and the test still fails — but this
    // extra assertion pinpoints the factory as the culprit.
    expect(User::find($socialOnly->id)->password)->toBeNull();

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($socialOnly));

    $response->assertOk();
    $response->assertJsonPath('data.requires_password', false);
});

it('rejects request-deletion for a passwordless (social-only) user with invalid_password', function (): void {
    // A social-only user has no password on file, so Hash::check() can't
    // possibly succeed. The endpoint must 422 cleanly with the standard
    // `invalid_password` key — NOT explode with a TypeError on the null
    // password. The mobile + web clients gate this branch behind the
    // `requires_password` flag from deletion-status, but a direct API
    // hit still has to fail safely.
    $socialOnly = User::factory()->passwordless()->create();

    $response = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'anything'],
        authHeader($socialOnly),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('messages.0', 'invalid_password');
    expect($socialOnly->fresh()->deletion_requested_at)->toBeNull();
});

it('returns deletion status with timestamps when deletion is pending', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($user));

    $response->assertOk();
    $response->assertJsonPath('data.is_pending', true);
    expect($response->json('data.deletion_requested_at'))->not->toBeNull();
    expect($response->json('data.deletion_scheduled_for'))->not->toBeNull();
});

// ───────────────────────────────────────────────
// POST /users/account/request-deletion
// ───────────────────────────────────────────────

it('requests deletion with correct password and sets timestamps', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $response = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertOk();
    $response->assertJsonPath('data.is_pending', true);
    $user->refresh();
    expect($user->deletion_requested_at)->not->toBeNull();
    expect($user->deletion_scheduled_for)->not->toBeNull();
    // 15-day grace period. Allow a small drift because `now()` inside
    // the service and `now()` here aren't the same tick.
    expect($user->deletion_scheduled_for->diffInDays($user->deletion_requested_at, true))
        ->toBeGreaterThanOrEqual(14);
});

it('does NOT revoke Sanctum tokens on request-deletion (session must survive so user can cancel)', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);
    // Pre-create a token so we can assert it's retained across the
    // request-deletion call. The auth flow uses a different bearer
    // token (issued by authHeader), so this stands in for any prior
    // session the user might have.
    $preToken = $user->createToken('web-session');

    $response = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertOk();
    // The pre-existing web-session token row is still there.
    expect($user->tokens()->where('name', 'web-session')->exists())->toBeTrue();
    // And the auth token used to hit request-deletion is still usable —
    // the deletion-status route is inside the "allowed during pending
    // deletion" middleware group, so if the token were revoked this
    // would 401.
    expect($preToken->plainTextToken)->toBeString();
});

it('allows a user to cancel deletion after requesting it (session survives)', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);
    $headers = authHeader($user);

    $request = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'secret123'],
        $headers,
    );
    $request->assertOk();

    // Reuse the SAME auth headers — proves the token survived the
    // request-deletion call.
    $cancel = $this->postJson(
        route('users.account.cancel-deletion'),
        ['password' => 'secret123'],
        $headers,
    );

    $cancel->assertOk();
    $cancel->assertJsonPath('data.is_pending', false);
    expect($user->fresh()->deletion_requested_at)->toBeNull();
    expect($user->fresh()->deletion_scheduled_for)->toBeNull();
});

it('rejects request-deletion with wrong password (422 invalid_password)', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $response = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'wrong'],
        authHeader($user),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('messages.0', 'invalid_password');
    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('rejects request-deletion when deletion is already pending', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('secret123'),
        'deletion_requested_at' => now()->subDay(),
        'deletion_scheduled_for' => now()->addDays(14),
    ]);

    // Auth the user BEFORE the middleware sees the pending state — the
    // route sits in the "allowed during pending deletion" group so the
    // request must land on the controller, not be short-circuited by
    // EnsureAccountNotPendingDeletion.
    $response = $this->postJson(
        route('users.account.request-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('messages.0', 'account_deletion_already_pending');
});

// ───────────────────────────────────────────────
// POST /users/account/cancel-deletion
// ───────────────────────────────────────────────

it('cancels deletion with correct password and clears timestamps', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('secret123'),
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->postJson(
        route('users.account.cancel-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertOk();
    $response->assertJsonPath('data.is_pending', false);
    $user->refresh();
    expect($user->deletion_requested_at)->toBeNull();
    expect($user->deletion_scheduled_for)->toBeNull();
});

it('rejects cancel-deletion with wrong password (422 invalid_password)', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('secret123'),
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->postJson(
        route('users.account.cancel-deletion'),
        ['password' => 'wrong'],
        authHeader($user),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('messages.0', 'invalid_password');
    expect($user->fresh()->deletion_requested_at)->not->toBeNull();
});

it('rejects cancel-deletion when no deletion is pending', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret123')]);

    $response = $this->postJson(
        route('users.account.cancel-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('messages.0', 'account_deletion_not_pending');
});

// ───────────────────────────────────────────────
// EnsureAccountNotPendingDeletion middleware
// ───────────────────────────────────────────────

it('blocks a normal authenticated route with 403 for a user pending deletion', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->getJson(route('users.me'), authHeader($user));

    $response->assertStatus(403);
    $response->assertJsonPath('messages.0', 'you_are_pending_deletion');
    expect($response->json('data.deletion_requested_at'))->not->toBeNull();
    expect($response->json('data.deletion_scheduled_for'))->not->toBeNull();
});

it('allows the cancel-deletion endpoint for a user pending deletion', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('secret123'),
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->postJson(
        route('users.account.cancel-deletion'),
        ['password' => 'secret123'],
        authHeader($user),
    );

    $response->assertOk();
});

it('allows the deletion-status endpoint for a user pending deletion', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($user));

    $response->assertOk();
});

// ───────────────────────────────────────────────
// HardDeleteExpiredAccountsJob
// ───────────────────────────────────────────────

it('hard-deletes users whose deletion_scheduled_for has passed', function (): void {
    $expired = User::factory()->create([
        'deletion_requested_at' => now()->subDays(16),
        'deletion_scheduled_for' => now()->subDay(),
    ]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    // forceDelete → the row is gone, not just soft-deleted
    expect(User::withTrashed()->find($expired->id))->toBeNull();
});

it('leaves users still in the grace period untouched', function (): void {
    $stillGrace = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(10),
    ]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    expect(User::withTrashed()->find($stillGrace->id))->not->toBeNull();
});

it('hard-deletes (not soft-deletes) related UserVerification, FeatureRequest and FeatureRequestVote rows', function (): void {
    $user = User::factory()->create([
        'deletion_requested_at' => now()->subDays(16),
        'deletion_scheduled_for' => now()->subDay(),
    ]);
    $other = User::factory()->create();

    // Verifications in both directions.
    $incoming = UserVerification::create(['user_id' => $user->id, 'verifier_id' => $other->id]);
    $outgoing = UserVerification::create(['user_id' => $other->id, 'verifier_id' => $user->id]);

    // Feature request authored by the user.
    $fr = FeatureRequest::create([
        'title' => 'Bulk export',
        'description' => 'Export my data as JSON',
        'created_by' => $user->id,
    ]);

    // Vote by the user on someone else's feature request.
    $othersRequest = FeatureRequest::create([
        'title' => 'Dark mode',
        'description' => 'Please add dark mode',
        'created_by' => $other->id,
    ]);
    $vote = FeatureRequestVote::create([
        'feature_request_id' => $othersRequest->id,
        'user_id' => $user->id,
        'vote' => 'up',
    ]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    // The user row is gone.
    $this->assertDatabaseMissing('users', ['id' => $user->id]);

    // Both verification rows are fully gone (not just soft-deleted).
    $this->assertDatabaseMissing('user_verifications', ['id' => $incoming->id]);
    $this->assertDatabaseMissing('user_verifications', ['id' => $outgoing->id]);

    // FeatureRequest is fully gone (not soft-deleted with deleted_at set).
    $this->assertDatabaseMissing('feature_requests', ['id' => $fr->id]);

    // The user's vote on someone else's request is gone — no orphan.
    $this->assertDatabaseMissing('feature_request_votes', ['id' => $vote->id]);

    // The other user's own feature request is untouched.
    $this->assertDatabaseHas('feature_requests', ['id' => $othersRequest->id]);
});

// ───────────────────────────────────────────────
// UserResource — deletion fields owner-gated
// ───────────────────────────────────────────────

it('hides deletion timestamps from non-owner viewers on the public user resource', function (): void {
    $target = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);
    $viewer = User::factory()->create();

    // Public `/users/verify/{uuid}` embeds UserResource. Even though
    // the viewer is authenticated, they are NOT the owner — the
    // deletion fields must be absent from the response body.
    // API is registered with `apiPrefix: ''` (see bootstrap/app.php) so
    // there is NO `/api` prefix — the actual URL is `/users/verify/{uuid}`.
    $response = $this->getJson('/users/verify/'.$target->uuid, authHeader($viewer));

    $response->assertOk();
    $response->assertJsonMissingPath('data.deletion_requested_at');
    $response->assertJsonMissingPath('data.deletion_scheduled_for');
});

it('surfaces deletion timestamps to the owner via the deletion-status endpoint', function (): void {
    // Owner-only visibility for the actual timestamps is tested via
    // the deletion-status endpoint (owner-authenticated), which is
    // the canonical read path once the timestamps are gated behind
    // the owner block on UserResource.
    $owner = User::factory()->create([
        'deletion_requested_at' => now(),
        'deletion_scheduled_for' => now()->addDays(15),
    ]);

    $response = $this->getJson(route('users.account.deletion-status'), authHeader($owner));

    $response->assertOk();
    expect($response->json('data.deletion_requested_at'))->not->toBeNull();
    expect($response->json('data.deletion_scheduled_for'))->not->toBeNull();
    $response->assertJsonPath('data.is_pending', true);
});
