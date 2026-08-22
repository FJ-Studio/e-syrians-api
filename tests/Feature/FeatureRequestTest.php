<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\FeatureRequest;

beforeEach(function (): void {
    test()->verified = User::factory()->create([
        'email' => 'verified_feature@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->verified->assignRole('citizen');

    test()->unverified = User::factory()->create([
        'email' => 'unverified_feature@gmail.com',
        'verified_at' => null,
    ]);
});

// ───────────────────────────────────────────────
// Index
// ───────────────────────────────────────────────

it('lists feature requests as guest (empty)', function (): void {
    $response = $this->getJson('/feature-requests');

    $response->assertOk();
    $response->assertJsonPath('data.feature_requests', []);
    $response->assertJsonStructure([
        'data' => ['feature_requests', 'current_page', 'last_page', 'per_page', 'total'],
    ]);
});

it('lists existing feature requests in newest-first order by default', function (): void {
    $older = FeatureRequest::forceCreate([
        'title' => 'Older idea',
        'description' => str_repeat('a', 50),
        'created_by' => test()->verified->id,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $newer = FeatureRequest::forceCreate([
        'title' => 'Newer idea',
        'description' => str_repeat('b', 50),
        'created_by' => test()->verified->id,
    ]);

    $response = $this->getJson('/feature-requests');

    $response->assertOk();
    $ids = array_column($response->json('data.feature_requests'), 'id');
    expect($ids)->toEqual([$newer->id, $older->id]);
});

it('filters by status=shipped', function (): void {
    FeatureRequest::forceCreate([
        'title' => 'Just an idea',
        'description' => str_repeat('i', 50),
        'created_by' => test()->verified->id,
    ]);
    $shipped = FeatureRequest::forceCreate([
        'title' => 'Shipped feature',
        'description' => str_repeat('s', 50),
        'created_by' => test()->verified->id,
        'coded_at' => now()->subDays(3),
        'tested_at' => now()->subDays(2),
        'deployed_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/feature-requests?status=shipped');

    $response->assertOk();
    $ids = array_column($response->json('data.feature_requests'), 'id');
    expect($ids)->toEqual([$shipped->id]);
});

// ───────────────────────────────────────────────
// Show
// ───────────────────────────────────────────────

it('shows a single feature request with derived status', function (): void {
    $feature = FeatureRequest::forceCreate([
        'title' => 'Dark mode',
        'description' => str_repeat('d', 50),
        'created_by' => test()->verified->id,
        'coded_at' => now()->subDay(),
    ]);

    $response = $this->getJson("/feature-requests/{$feature->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $feature->id);
    $response->assertJsonPath('data.status', 'in_development');
    $response->assertJsonPath('data.title', 'Dark mode');
    $response->assertJsonPath('data.ups_count', 0);
    $response->assertJsonPath('data.downs_count', 0);
    $response->assertJsonPath('data.score', 0);
});

it('returns 404 for unknown feature request', function (): void {
    $response = $this->getJson('/feature-requests/99999');

    $response->assertStatus(404);
});

// ───────────────────────────────────────────────
// Store — auth & verification gating
// ───────────────────────────────────────────────

it('rejects unauthenticated store with 401', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => 'Some idea',
        'description' => str_repeat('x', 50),
    ]);

    $response->assertStatus(401);
});

it('rejects unverified user store with 403', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => 'Some idea',
        'description' => str_repeat('x', 50),
    ], authHeader(test()->unverified));

    $response->assertStatus(403);
});

it('creates a feature request for a verified user', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => 'Add RSS feed',
        'description' => str_repeat('y', 50),
    ], authHeader(test()->verified));

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Add RSS feed');
    $response->assertJsonPath('data.status', 'idea');
    $this->assertDatabaseHas('feature_requests', [
        'title' => 'Add RSS feed',
        'created_by' => test()->verified->id,
    ]);
});

// ───────────────────────────────────────────────
// Store — validation
// ───────────────────────────────────────────────

// Note: this project's bootstrap/app.php re-renders ValidationException via
// ApiService::error(422, $e->errors()) — field errors land under `messages`,
// not under the default Laravel `errors` key. So `assertJsonValidationErrors`
// won't match; we assert on the `messages` path instead.

it('rejects store with missing title', function (): void {
    $response = $this->postJson('/feature-requests', [
        'description' => str_repeat('y', 50),
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('title');
});

it('rejects store with short title', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => 'abc',
        'description' => str_repeat('y', 50),
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('title');
});

it('rejects store with short description', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => 'Good title',
        'description' => 'too short',
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('description');
});

it('rejects store with overlong title', function (): void {
    $response = $this->postJson('/feature-requests', [
        'title' => str_repeat('t', 161),
        'description' => str_repeat('y', 50),
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('title');
});

// ───────────────────────────────────────────────
// Status derivation
// ───────────────────────────────────────────────

it('derives status from timeline timestamps', function (): void {
    $cases = [
        ['expected' => 'idea',           'stamps' => []],
        ['expected' => 'in_development', 'stamps' => ['coded_at' => now()]],
        ['expected' => 'in_testing',     'stamps' => ['coded_at' => now(), 'tested_at' => now()]],
        ['expected' => 'shipped',        'stamps' => ['coded_at' => now(), 'tested_at' => now(), 'deployed_at' => now()]],
        // deployed_at alone still yields "shipped"
        ['expected' => 'shipped',        'stamps' => ['deployed_at' => now()]],
    ];

    foreach ($cases as $case) {
        $feature = FeatureRequest::forceCreate(array_merge([
            'title' => 'Status test',
            'description' => str_repeat('s', 50),
            'created_by' => test()->verified->id,
        ], $case['stamps']));

        expect($feature->fresh()->status)->toBe($case['expected']);
    }
});
