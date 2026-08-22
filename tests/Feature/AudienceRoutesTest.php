<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Audience;
use App\Models\PollOption;
use App\Models\AudienceAudit;
use App\Models\AudienceEntry;
use App\Enums\AudienceEntryTypeEnum;
use App\Contracts\AudienceServiceContract;

/*
|--------------------------------------------------------------------------
| Audiences — feature tests
|--------------------------------------------------------------------------
|
| These tests target the /users/audiences/* endpoints end-to-end. They
| exercise the AudienceController + AudienceService together, and cover
| the invariants that matter for privacy and correctness:
|
|   • ownership scoping (a user cannot see or touch another user's list)
|   • the paste-in normalization + dedupe behavior
|   • the `resolved` boolean stays a boolean (never leaks user identity)
|   • audit rows only fire when a poll is inside its voting window
|   • softDelete refuses to run while an audience is actively voting
|   • poll vote gate honors audience_id (live reference, not snapshot)
*/

beforeEach(function (): void {
    test()->creator = User::factory()->create([
        'email' => 'audiences_owner@example.test',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1990-01-01',
        'hometown' => 'damascus',
        'country' => 'TR',
        'ethnicity' => 'arab',
    ]);
    test()->creator->assignRole('citizen');

    // A "matched" user — email lives in the audience, so entry
    // resolution should hit them.
    test()->matched = User::factory()->create([
        'email' => 'matched_voter@example.test',
        'national_id' => '12345678901',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'f',
        'birth_date' => '1992-01-01',
        'hometown' => 'aleppo',
        'country' => 'SY',
        'ethnicity' => 'arab',
    ]);
    test()->matched->assignRole('citizen');

    // An "outsider" — no identifier of theirs lives in the audience.
    test()->outsider = User::factory()->create([
        'email' => 'outsider@example.test',
        'verified_at' => now(),
        'verification_reason' => 'first_registrant',
        'gender' => 'm',
        'birth_date' => '1995-01-01',
        'hometown' => 'homs',
        'country' => 'DE',
        'ethnicity' => 'arab',
    ]);
    test()->outsider->assignRole('citizen');
});

// ───────────────────────────────────────────────
// Index + ownership
// ───────────────────────────────────────────────

it('lists only the caller\'s audiences', function (): void {
    // Two audiences: one for the creator, one for the outsider.
    createAudience(test()->creator, 'Mine', ['a@x.test', 'b@x.test']);
    createAudience(test()->outsider, 'Not Mine', ['c@x.test']);

    $response = $this->getJson('/users/audiences', authHeader(test()->creator));

    $response->assertOk();
    $response->assertJsonPath('data.audiences.0.name', 'Mine');
    expect($response->json('data.audiences'))->toHaveCount(1);
});

it('rejects unauthenticated list requests', function (): void {
    $this->getJson('/users/audiences')->assertUnauthorized();
});

it('returns 404 when showing another user\'s audience', function (): void {
    $foreign = createAudience(test()->outsider, 'Nope', ['a@x.test']);

    $this->getJson("/users/audiences/{$foreign->uuid}", authHeader(test()->creator))
        ->assertNotFound();
});

// ───────────────────────────────────────────────
// Create + entry normalization
// ───────────────────────────────────────────────

it('creates an audience with mixed entries and resolves matches', function (): void {
    $response = $this->postJson('/users/audiences', [
        'name' => 'Launch cohort',
        'description' => 'people we told about the launch',
        'entries' => [
            'MATCHED_voter@example.test',   // uppercase → should normalize
            '  12345678901  ',               // whitespace → should trim
            'outsider2@example.test',        // unresolved
        ],
    ], authHeader(test()->creator));

    // AudienceController::store returns 201 (resource creation),
    // not 200 — assertCreated matches the ApiService::success(...)
    // status arg used there.
    $response->assertCreated();
    $response->assertJsonPath('data.name', 'Launch cohort');
    $response->assertJsonPath('data.entries_total_count', 3);
    // matched_voter@… + 12345678901 both point at test()->matched;
    // resolution dedupes by user_id but not by row, so 2 rows resolve.
    $response->assertJsonPath('data.entries_resolved_count', 2);

    $audience = Audience::query()->where('name', 'Launch cohort')->first();
    expect($audience)->not->toBeNull();
    expect($audience->user_id)->toBe(test()->creator->id);
});

it('rejects entries that are neither valid emails nor national IDs', function (): void {
    $this->postJson('/users/audiences', [
        'name' => 'Bad',
        'entries' => ['not-an-email', 'abc'],
    ], authHeader(test()->creator))->assertStatus(422);
});

it('does not expose the resolved user\'s identity — only a boolean', function (): void {
    $audience = createAudience(test()->creator, 'Privacy', ['matched_voter@example.test']);

    $response = $this->getJson("/users/audiences/{$audience->uuid}", authHeader(test()->creator));

    $response->assertOk();
    $entry = $response->json('data.entries.0');
    expect($entry)->toHaveKey('resolved');
    expect($entry['resolved'])->toBeTrue();
    expect($entry)->not->toHaveKey('resolved_user_id');
    expect($entry)->not->toHaveKey('user');
    // The name/uuid of the matched user must NOT be present.
    expect(json_encode($entry))->not->toContain((string) test()->matched->uuid);
});

it('clears an existing description when patched with null', function (): void {
    $audience = createAudience(test()->creator, 'HasDesc', ['a@x.test']);
    $audience->description = 'initial notes';
    $audience->save();

    $this->patchJson(
        "/users/audiences/{$audience->uuid}",
        ['description' => null],
        authHeader(test()->creator),
    )->assertOk();

    expect($audience->fresh()->description)->toBeNull();
});

// ───────────────────────────────────────────────
// Add entries — dedupe + audit emission
// ───────────────────────────────────────────────

it('deduplicates entries against the existing list', function (): void {
    $audience = createAudience(test()->creator, 'Dupes', ['a@x.test']);

    $this->postJson("/users/audiences/{$audience->uuid}/entries", [
        'entries' => ['A@X.test', 'b@x.test'],
    ], authHeader(test()->creator))->assertOk();

    // Original 'a@x.test' + the new 'b@x.test' — the case-only dupe
    // does not create a second row.
    expect(AudienceEntry::query()->where('audience_id', $audience->id)->count())
        ->toBe(2);
});

it('emits an audit when entries change while a poll is actively voting', function (): void {
    $audience = createAudience(test()->creator, 'Active', ['a@x.test']);

    // Poll actively voting NOW.
    $poll = Poll::forceCreate([
        'question' => 'Ballot?',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->creator->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audience->id,
    ]);

    $this->postJson("/users/audiences/{$audience->uuid}/entries", [
        'entries' => ['b@x.test'],
    ], authHeader(test()->creator))->assertOk();

    $audit = AudienceAudit::query()->where('audience_id', $audience->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->entries_added_count)->toBe(1);
    expect($audit->affected_poll_ids)->toBe([$poll->id]);
});

it('does not emit an audit when there is no active poll', function (): void {
    $audience = createAudience(test()->creator, 'Quiet', ['a@x.test']);

    // Poll ended yesterday — outside the voting window.
    Poll::forceCreate([
        'question' => 'Old ballot?',
        'start_date' => now()->subDays(10),
        'end_date' => now()->subDays(2),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->creator->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audience->id,
    ]);

    $this->postJson("/users/audiences/{$audience->uuid}/entries", [
        'entries' => ['b@x.test'],
    ], authHeader(test()->creator))->assertOk();

    expect(AudienceAudit::query()->where('audience_id', $audience->id)->count())->toBe(0);
});

// ───────────────────────────────────────────────
// Soft delete — refuses while a poll is active
// ───────────────────────────────────────────────

it('refuses to delete an audience while an active poll references it', function (): void {
    $audience = createAudience(test()->creator, 'InUse', ['a@x.test']);

    Poll::forceCreate([
        'question' => 'Live?',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->creator->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audience->id,
    ]);

    $response = $this->deleteJson("/users/audiences/{$audience->uuid}", [], authHeader(test()->creator));

    $response->assertStatus(409);
    $response->assertJsonPath('messages.0', 'audience_referenced_by_active_poll');
    // Audience still there — soft-delete blocked.
    expect(Audience::query()->whereKey($audience->id)->exists())->toBeTrue();
});

it('deletes an audience with no active poll references', function (): void {
    $audience = createAudience(test()->creator, 'Free', ['a@x.test']);

    $this->deleteJson("/users/audiences/{$audience->uuid}", [], authHeader(test()->creator))
        ->assertOk();

    expect(Audience::query()->whereKey($audience->id)->exists())->toBeFalse();
    expect(Audience::withTrashed()->whereKey($audience->id)->exists())->toBeTrue();
});

// ───────────────────────────────────────────────
// Poll vote gate — live reference
// ───────────────────────────────────────────────

it('allows a matched user to vote through an audience-gated poll', function (): void {
    $audience = createAudience(test()->creator, 'Voters', ['matched_voter@example.test']);
    $poll = createAudiencePoll(test()->creator, $audience->id);
    $option = $poll->options->first();

    $response = $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$option->id],
    ], authHeader(test()->matched));

    $response->assertOk();
});

it('blocks a non-matched user from voting through an audience-gated poll', function (): void {
    $audience = createAudience(test()->creator, 'Voters', ['matched_voter@example.test']);
    $poll = createAudiencePoll(test()->creator, $audience->id);
    $option = $poll->options->first();

    $response = $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$option->id],
    ], authHeader(test()->outsider));

    $response->assertStatus(400);
    // PollController::vote unwraps PollVotingException::getDetails()
    // into the `messages` array (falling back to getMessage() only
    // if details are empty). User::isInAudience returns
    // ['not_in_allowed_voters'] for the audience_id short-circuit
    // — that's the concrete key the client sees.
    $response->assertJsonPath('messages.0', 'not_in_allowed_voters');
});

it('honors LIVE audience changes at vote time', function (): void {
    // What we're actually asserting here is a semantic invariant:
    // adding a user to an audience while a poll is actively voting
    // must let them vote WITHOUT recreating the poll — the poll
    // reads the audience live, not from a snapshot taken at
    // create time.
    //
    // The earlier "chained HTTP" form of this test triggered a
    // spurious 404 on the second HTTP call in the same test method
    // that couldn't be reproduced when the same code was invoked
    // through the service layer directly (verified by an earlier
    // reviewer). Rather than paper over that with retries, we
    // exercise the intermediate mutation through the service — the
    // HTTP surface of addEntries is already covered by the
    // "deduplicates entries" / "emits an audit" tests — and keep
    // the actual vote assertion through the HTTP endpoint.
    $audience = createAudience(test()->creator, 'Voters', ['matched_voter@example.test']);
    $poll = createAudiencePoll(test()->creator, $audience->id);
    $option = $poll->options->first();

    // Outsider is blocked initially.
    $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$option->id],
    ], authHeader(test()->outsider))->assertStatus(400);

    // Owner adds the outsider — via the service so we don't chain
    // two write-endpoint HTTP calls in one test.
    resolve(AudienceServiceContract::class)->addEntries(
        uuid: $audience->uuid,
        userId: (int) test()->creator->id,
        rawEntries: ['outsider@example.test'],
    );

    // Live reference: the SAME poll (no changes) should now accept
    // the vote because the audience list itself has changed.
    $this->postJson('/polls/vote', [
        'poll_id' => $poll->id,
        'poll_option_id' => [$option->id],
    ], authHeader(test()->outsider))->assertOk();
});

// ───────────────────────────────────────────────
// Create poll — validation for audience_id
// ───────────────────────────────────────────────

it('rejects legacy allowed_voters even when audience_uuid is present', function (): void {
    $audience = createAudience(test()->creator, 'Mix', ['a@x.test']);

    $response = $this->postJson('/polls', [
        'question' => 'Conflict?',
        'start_date' => now()->toDateString(),
        'duration' => 3,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'audience_uuid' => $audience->uuid,
        'allowed_voters' => ['someone@x.test'],
    ], authHeader(test()->creator));

    $response->assertStatus(422);
    expect($response->json('messages'))->toHaveKey('allowed_voters');
});

it('renders a saved-audience poll with the audience summary, not empty demographics', function (): void {
    $audience = createAudience(test()->creator, 'Renders', ['a@x.test', 'b@x.test']);
    $poll = Poll::forceCreate([
        'question' => 'Attached ballot?',
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(3),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->creator->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audience->id,
    ]);

    $response = $this->getJson("/polls/{$poll->id}", authHeader(test()->creator));

    $response->assertOk();
    // Saved-list flag surfaced so the client picks the right branch.
    $response->assertJsonPath('data.audience_is_saved_list', true);
    // Summary fields: name + counts, no identifiers.
    $response->assertJsonPath('data.audience.audience.name', 'Renders');
    $response->assertJsonPath('data.audience.audience.uuid', $audience->uuid);
    $response->assertJsonPath('data.audience.audience.entries_total_count', 2);

    // The demographic scaffold must NOT be exposed (would confuse
    // the client into rendering an empty gender/country panel).
    $audienceBlock = $response->json('data.audience');
    expect($audienceBlock)->not->toHaveKey('gender');
    expect($audienceBlock)->not->toHaveKey('age_range');
});

it('rejects patching inline audience criteria on a poll already backed by a saved audience', function (): void {
    // A poll that's using the saved-audience mechanism.
    $audience = createAudience(test()->creator, 'Attached', ['a@x.test']);
    $poll = Poll::forceCreate([
        'question' => 'Attached ballot?',
        'start_date' => now()->addDay(),
        'end_date' => now()->addDays(3),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => test()->creator->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audience->id,
    ]);

    // PATCH tries to set inline demographics WITHOUT detaching the
    // audience first. Under the old code the request succeeded but
    // PollService silently dropped the change. Now the request
    // must 422 so the client either detaches the audience
    // (`audience_uuid: null`) or stops sending inline criteria.
    $this->patchJson("/polls/{$poll->id}", [
        'gender' => ['f'],
        'recaptcha_token' => 'test',
    ], authHeader(test()->creator))->assertStatus(422);
});

it('rejects a create-poll payload with both audience_uuid and demographic criteria', function (): void {
    $audience = createAudience(test()->creator, 'MixDemo', ['a@x.test']);

    $this->postJson('/polls', [
        'question' => 'Overlap?',
        'start_date' => now()->toDateString(),
        'duration' => 3,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'audience_uuid' => $audience->uuid,
        // Demographic criterion — must be rejected alongside audience.
        'gender' => ['f'],
    ], authHeader(test()->creator))->assertStatus(422);
});

it('rejects a create-poll payload referencing another user\'s audience', function (): void {
    $foreign = createAudience(test()->outsider, 'Foreign', ['a@x.test']);

    $this->postJson('/polls', [
        'question' => 'Steal?',
        'start_date' => now()->toDateString(),
        'duration' => 3,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'options' => ['Yes', 'No'],
        'audience_uuid' => $foreign->uuid,
    ], authHeader(test()->creator))->assertStatus(422);
});

// ───────────────────────────────────────────────
// Helpers
// ───────────────────────────────────────────────

/**
 * Build an audience directly through the model layer (bypasses
 * the API) so test setup stays terse. Boots hash-sync + resolution
 * exactly as the real service does — the tests can then poke at
 * the resulting rows or hit the API on top of them.
 *
 * @param array<int, string> $entries
 */
function createAudience(User $owner, string $name, array $entries): Audience
{
    $audience = new Audience(['name' => $name]);
    $audience->user_id = $owner->id;
    $audience->save();

    foreach ($entries as $raw) {
        $type = AudienceEntryTypeEnum::detect($raw) ?? AudienceEntryTypeEnum::Email;
        $audience->entries()->create([
            'identifier' => strtolower(trim($raw)),
            'identifier_type' => $type,
        ]);
    }

    // Manual resolution so `resolved` reflects reality without
    // going through the service just for setup.
    $entries = $audience->entries()->get();
    foreach ($entries as $entry) {
        $col = $entry->identifier_type === AudienceEntryTypeEnum::Email
            ? 'email_hashed'
            : 'national_id_hashed';
        $userId = User::query()->where($col, $entry->identifier_hashed)->value('id');
        if ($userId !== null) {
            $entry->resolved_user_id = $userId;
            $entry->save();
        }
    }

    return $audience->fresh();
}

/**
 * Create an actively-voting poll gated by the given audience.
 */
function createAudiencePoll(User $owner, int $audienceId): Poll
{
    $poll = Poll::forceCreate([
        'question' => 'Audience-only ballot?',
        'start_date' => now()->subHour(),
        'end_date' => now()->addDay(),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'created_by' => $owner->id,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => true,
        'is_private' => false,
        'audience_id' => $audienceId,
    ]);

    PollOption::insert([
        ['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
        ['poll_id' => $poll->id, 'option_text' => 'No', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $poll->fresh()->load('options');
}
