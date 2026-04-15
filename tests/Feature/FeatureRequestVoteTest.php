<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestVote;

beforeEach(function (): void {
    test()->verified = User::factory()->create([
        'email' => 'verified_vote@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->verified->assignRole('citizen');

    test()->otherVerified = User::factory()->create([
        'email' => 'verified_vote_other@gmail.com',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
    ]);
    test()->otherVerified->assignRole('citizen');

    test()->unverified = User::factory()->create([
        'email' => 'unverified_vote@gmail.com',
        'verified_at' => null,
    ]);

    test()->feature = FeatureRequest::forceCreate([
        'title' => 'Votable feature',
        'description' => str_repeat('v', 50),
        'created_by' => test()->verified->id,
    ]);
});

// ───────────────────────────────────────────────
// Auth & verification gating
// ───────────────────────────────────────────────

it('rejects unauthenticated vote with 401', function (): void {
    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ]);

    $response->assertStatus(401);
    $this->assertDatabaseCount('feature_request_votes', 0);
});

it('rejects unverified user vote with 403', function (): void {
    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->unverified));

    $response->assertStatus(403);
    $this->assertDatabaseCount('feature_request_votes', 0);
});

// ───────────────────────────────────────────────
// Validation
// ───────────────────────────────────────────────

it('rejects vote with missing fields', function (): void {
    $response = $this->postJson('/feature-requests/vote', [], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('feature_request_id');
    expect($response->json('messages'))->toHaveKey('vote');
});

it('rejects vote with invalid direction', function (): void {
    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'sideways',
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('vote');
});

it('rejects vote on a non-existent feature', function (): void {
    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => 99999,
        'vote' => 'up',
    ], authHeader(test()->verified));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('feature_request_id');
});

// ───────────────────────────────────────────────
// Vote → insert
// ───────────────────────────────────────────────

it('inserts an up-vote and returns outcome=added', function (): void {
    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified));

    $response->assertOk();
    $response->assertJsonPath('data.outcome', 'added');
    $this->assertDatabaseHas('feature_request_votes', [
        'feature_request_id' => test()->feature->id,
        'user_id' => test()->verified->id,
        'vote' => 'up',
    ]);
});

it('counts ups_count = 1 on show after a single upvote', function (): void {
    $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified));

    $response = $this->getJson('/feature-requests/' . test()->feature->id);

    $response->assertOk();
    $response->assertJsonPath('data.ups_count', 1);
    $response->assertJsonPath('data.downs_count', 0);
    $response->assertJsonPath('data.score', 1);
});

// ───────────────────────────────────────────────
// Toggle off (same direction twice)
// ───────────────────────────────────────────────

it('removes the vote when the same direction is submitted twice', function (): void {
    $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified))->assertOk();

    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified));

    $response->assertOk();
    $response->assertJsonPath('data.outcome', 'removed');
    $this->assertDatabaseCount('feature_request_votes', 0);
});

// ───────────────────────────────────────────────
// Switch direction (updates the row, not a new insert)
// ───────────────────────────────────────────────

it('switches direction via UPDATE (unique constraint holds)', function (): void {
    $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified))->assertOk();

    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'down',
    ], authHeader(test()->verified));

    $response->assertOk();
    $response->assertJsonPath('data.outcome', 'switched');

    // Exactly one row, and it's now a down-vote.
    $this->assertDatabaseCount('feature_request_votes', 1);
    $this->assertDatabaseHas('feature_request_votes', [
        'feature_request_id' => test()->feature->id,
        'user_id' => test()->verified->id,
        'vote' => 'down',
    ]);
});

it('independently tracks multiple users voting on the same feature', function (): void {
    $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified))->assertOk();

    // Sanctum's guard is a container singleton; once it resolves User A from
    // the first bearer token, subsequent requests in the same test reuse that
    // cached user and ignore the new bearer. Flushing the guards forces the
    // next request to re-resolve from its own Authorization header.
    app('auth')->forgetGuards();

    $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'down',
    ], authHeader(test()->otherVerified))->assertOk();

    $this->assertDatabaseCount('feature_request_votes', 2);

    app('auth')->forgetGuards();

    $response = $this->getJson('/feature-requests/' . test()->feature->id);
    $response->assertJsonPath('data.ups_count', 1);
    $response->assertJsonPath('data.downs_count', 1);
    $response->assertJsonPath('data.score', 0);
});

// ───────────────────────────────────────────────
// has_upvoted / has_downvoted on authenticated show
// ───────────────────────────────────────────────

it('reflects has_upvoted/has_downvoted for the authenticated caller', function (): void {
    FeatureRequestVote::create([
        'feature_request_id' => test()->feature->id,
        'user_id' => test()->verified->id,
        'vote' => 'up',
    ]);

    $asVoter = $this->getJson(
        '/feature-requests/' . test()->feature->id,
        authHeader(test()->verified),
    );
    $asVoter->assertJsonPath('data.has_upvoted', true);
    $asVoter->assertJsonPath('data.has_downvoted', false);

    // Sanctum's guard is a container singleton; once it resolves User A from
    // the first bearer token, subsequent requests in the same test reuse that
    // cached user and ignore the new bearer. Flushing the guards forces the
    // next request to re-resolve from its own Authorization header.
    app('auth')->forgetGuards();

    $asOther = $this->getJson(
        '/feature-requests/' . test()->feature->id,
        authHeader(test()->otherVerified),
    );
    $asOther->assertJsonPath('data.has_upvoted', false);
    $asOther->assertJsonPath('data.has_downvoted', false);

    app('auth')->forgetGuards();

    // Guests should see false/false regardless of actual votes.
    $asGuest = $this->getJson('/feature-requests/' . test()->feature->id);
    $asGuest->assertJsonPath('data.has_upvoted', false);
    $asGuest->assertJsonPath('data.has_downvoted', false);
});

// ───────────────────────────────────────────────
// DELETE /vote/{id} — explicit unvote
// ───────────────────────────────────────────────

it('removes the current user vote via DELETE', function (): void {
    FeatureRequestVote::create([
        'feature_request_id' => test()->feature->id,
        'user_id' => test()->verified->id,
        'vote' => 'up',
    ]);

    $response = $this->deleteJson(
        '/feature-requests/vote/' . test()->feature->id,
        [],
        authHeader(test()->verified),
    );

    $response->assertOk();
    $this->assertDatabaseCount('feature_request_votes', 0);
});

it('DELETE /vote is idempotent when there is no existing vote', function (): void {
    $response = $this->deleteJson(
        '/feature-requests/vote/' . test()->feature->id,
        [],
        authHeader(test()->verified),
    );

    $response->assertOk();
});

it('rejects unauthenticated DELETE /vote with 401', function (): void {
    $response = $this->deleteJson('/feature-requests/vote/' . test()->feature->id);

    $response->assertStatus(401);
});

// ───────────────────────────────────────────────
// Soft-deleted feature
// ───────────────────────────────────────────────

it('rejects vote on a soft-deleted feature', function (): void {
    test()->feature->delete();

    $response = $this->postJson('/feature-requests/vote', [
        'feature_request_id' => test()->feature->id,
        'vote' => 'up',
    ], authHeader(test()->verified));

    // Soft-deleted row still exists, so validation (exists:feature_requests,id)
    // passes; the service rejects it at the business-logic layer.
    $response->assertStatus(404);
    $this->assertDatabaseCount('feature_request_votes', 0);
});
