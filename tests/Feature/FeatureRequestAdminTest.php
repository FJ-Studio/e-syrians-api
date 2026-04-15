<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\FeatureRequest;

beforeEach(function (): void {
    test()->admin = User::factory()->create([
        'email' => 'admin_feature@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->admin->assignRole('admin');

    test()->verified = User::factory()->create([
        'email' => 'verified_admin_scenario@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->verified->assignRole('citizen');

    test()->feature = FeatureRequest::forceCreate([
        'title' => 'Add RSS feed',
        'description' => str_repeat('y', 50),
        'created_by' => test()->verified->id,
    ]);
});

// ───────────────────────────────────────────────
// Timeline — gating
// ───────────────────────────────────────────────

it('rejects guest setting a timeline with 401', function (): void {
    $response = $this->postJson('/feature-requests/' . test()->feature->id . '/timeline', [
        'coded_at' => now()->toISOString(),
    ]);

    $response->assertStatus(401);
});

it('rejects non-admin setting a timeline with 403', function (): void {
    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['coded_at' => now()->toISOString()],
        authHeader(test()->verified),
    );

    $response->assertStatus(403);
});

// ───────────────────────────────────────────────
// Timeline — happy paths
// ───────────────────────────────────────────────

it('allows admin to set all three timeline stamps atomically', function (): void {
    $coded = now()->subDays(3)->startOfSecond();
    $tested = now()->subDays(2)->startOfSecond();
    $deployed = now()->subDay()->startOfSecond();

    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        [
            'coded_at' => $coded->toISOString(),
            'tested_at' => $tested->toISOString(),
            'deployed_at' => $deployed->toISOString(),
        ],
        authHeader(test()->admin),
    );

    $response->assertOk();
    $response->assertJsonPath('data.status', 'shipped');
    $response->assertJsonPath('data.timeline.coded_at', $coded->toISOString());
    $response->assertJsonPath('data.timeline.tested_at', $tested->toISOString());
    $response->assertJsonPath('data.timeline.deployed_at', $deployed->toISOString());
});

it('accepts a partial timeline update', function (): void {
    $coded = now()->subDay()->startOfSecond();

    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['coded_at' => $coded->toISOString()],
        authHeader(test()->admin),
    );

    $response->assertOk();
    $response->assertJsonPath('data.status', 'in_development');
    $response->assertJsonPath('data.timeline.coded_at', $coded->toISOString());
    $response->assertJsonPath('data.timeline.tested_at', null);
    $response->assertJsonPath('data.timeline.deployed_at', null);
});

it('clears a previously-set stamp when null is passed', function (): void {
    // Arrange: put the feature in "in_testing" (coded + tested).
    test()->feature->forceFill([
        'coded_at' => now()->subDays(2),
        'tested_at' => now()->subDay(),
    ])->save();

    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['tested_at' => null],
        authHeader(test()->admin),
    );

    $response->assertOk();
    $response->assertJsonPath('data.timeline.tested_at', null);
    // After clearing tested_at we fall back to the next-most-progressed stamp.
    $response->assertJsonPath('data.status', 'in_development');
});

it('omitted keys leave existing stamps untouched', function (): void {
    $originalCoded = now()->subDays(5)->startOfSecond();
    test()->feature->forceFill(['coded_at' => $originalCoded])->save();

    // Submit only deployed_at; coded_at should survive untouched.
    $deployed = now()->startOfSecond();
    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['deployed_at' => $deployed->toISOString()],
        authHeader(test()->admin),
    );

    $response->assertOk();
    $response->assertJsonPath('data.timeline.coded_at', $originalCoded->toISOString());
    $response->assertJsonPath('data.timeline.deployed_at', $deployed->toISOString());
    $response->assertJsonPath('data.status', 'shipped');
});

it('rejects timeline update with non-date value', function (): void {
    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['coded_at' => 'not-a-date'],
        authHeader(test()->admin),
    );

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('coded_at');
});

it('returns 404 when setting timeline on a soft-deleted feature', function (): void {
    test()->feature->forceFill(['deletion_reason' => 'spam'])->save();
    test()->feature->delete();

    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/timeline',
        ['coded_at' => now()->toISOString()],
        authHeader(test()->admin),
    );

    $response->assertStatus(404);
});

// ───────────────────────────────────────────────
// Destroy — soft delete
// ───────────────────────────────────────────────

it('rejects guest destroy with 401', function (): void {
    $response = $this->deleteJson('/feature-requests/' . test()->feature->id, [
        'deletion_reason' => 'obvious spam',
    ]);

    $response->assertStatus(401);
});

it('rejects non-admin destroy with 403', function (): void {
    $response = $this->deleteJson(
        '/feature-requests/' . test()->feature->id,
        ['deletion_reason' => 'obvious spam'],
        authHeader(test()->verified),
    );

    $response->assertStatus(403);
});

it('requires a deletion_reason', function (): void {
    $response = $this->deleteJson(
        '/feature-requests/' . test()->feature->id,
        [],
        authHeader(test()->admin),
    );

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('deletion_reason');
});

it('allows admin to soft-delete with a reason', function (): void {
    $response = $this->deleteJson(
        '/feature-requests/' . test()->feature->id,
        ['deletion_reason' => 'off-topic'],
        authHeader(test()->admin),
    );

    $response->assertOk();
    $this->assertSoftDeleted('feature_requests', ['id' => test()->feature->id]);
    expect(test()->feature->fresh()->deletion_reason)->toBe('off-topic');
});

it('hides soft-deleted features from the public list', function (): void {
    test()->feature->forceFill(['deletion_reason' => 'off-topic'])->save();
    test()->feature->delete();

    $response = $this->getJson('/feature-requests');

    $response->assertOk();
    $response->assertJsonPath('data.feature_requests', []);
});

it('returns 404 when showing a soft-deleted feature', function (): void {
    test()->feature->forceFill(['deletion_reason' => 'off-topic'])->save();
    test()->feature->delete();

    $response = $this->getJson('/feature-requests/' . test()->feature->id);

    $response->assertStatus(404);
});

// ───────────────────────────────────────────────
// Restore
// ───────────────────────────────────────────────

it('rejects guest restore with 401', function (): void {
    $response = $this->postJson('/feature-requests/' . test()->feature->id . '/restore');

    $response->assertStatus(401);
});

it('rejects non-admin restore with 403', function (): void {
    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/restore',
        [],
        authHeader(test()->verified),
    );

    $response->assertStatus(403);
});

it('allows admin to restore a soft-deleted feature and clear the reason', function (): void {
    test()->feature->forceFill(['deletion_reason' => 'off-topic'])->save();
    test()->feature->delete();

    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/restore',
        [],
        authHeader(test()->admin),
    );

    $response->assertOk();

    $fresh = test()->feature->fresh();
    expect($fresh->deleted_at)->toBeNull();
    expect($fresh->deletion_reason)->toBeNull();

    // And the public list now sees it again.
    app('auth')->forgetGuards();
    $listResponse = $this->getJson('/feature-requests');
    $ids = array_column($listResponse->json('data.feature_requests'), 'id');
    expect($ids)->toContain(test()->feature->id);
});

it('restore on a non-deleted feature is a no-op', function (): void {
    $response = $this->postJson(
        '/feature-requests/' . test()->feature->id . '/restore',
        [],
        authHeader(test()->admin),
    );

    $response->assertOk();
    expect(test()->feature->fresh()->deleted_at)->toBeNull();
});
