<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\PollOption;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use App\Models\IdentityVerificationCredit;

beforeEach(function (): void {
    Queue::fake();
});

function publishingPayload(): array
{
    return [
        'question' => 'Do you agree with this proposal?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => false,
        'audience_only' => false,
        'options' => ['Yes', 'No', 'I am not sure'],
    ];
}

it('uses community status alone for a complete publish request and its read-back', function (bool $community, bool $identity): void {
    $member = User::factory()->create(['verified_at' => $community ? now() : null]);
    $member->assignRole('citizen');
    if ($identity) {
        IdentityVerificationCredit::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $member->id,
            'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
            'status' => 'approved',
            'provider_session_id' => (string) Str::uuid(),
        ]);
    }
    $headers = authHeader($member);
    $before = Poll::withoutGlobalScopes()->count();
    $optionsBefore = PollOption::count();
    $response = $this->postJson('/polls', publishingPayload(), $headers);

    if (! $community) {
        $response->assertForbidden()->assertJsonPath('success', false)
            ->assertJsonPath('messages.0', 'you_are_not_verified');
        expect(Poll::withoutGlobalScopes()->count())->toBe($before);
        expect(PollOption::count())->toBe($optionsBefore);

        return;
    }

    $response->assertOk()->assertJsonPath('success', true);
    $id = $response->json('data.id');
    expect($id)->toBeInt()->toBeGreaterThan(0);
    $this->assertDatabaseHas('polls', ['id' => $id, 'created_by' => $member->id, 'is_private' => false]);
    expect(PollOption::where('poll_id', $id)->count())->toBe(3);

    $this->getJson('/polls/'.$id, $headers)->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.question', publishingPayload()['question'])
        ->assertJsonCount(3, 'data.options');
    $this->getJson('/polls?year='.now()->year.'&month='.now()->month, $headers)->assertOk()
        ->assertJsonFragment(['id' => $id, 'question' => publishingPayload()['question']]);
    $this->getJson('/users/my-polls', $headers)->assertOk()
        ->assertJsonFragment(['id' => $id, 'question' => publishingPayload()['question']]);
})->with([
    'unverified' => [false, false],
    'ID only' => [false, true],
    'community only' => [true, false],
    'both' => [true, true],
]);

it('rejects a complete anonymous publish without writing a poll', function (): void {
    $before = Poll::withoutGlobalScopes()->count();
    $this->postJson('/polls', publishingPayload())->assertUnauthorized();
    expect(Poll::withoutGlobalScopes()->count())->toBe($before);
});

it('rechecks community verification at write time after the editor has loaded', function (): void {
    $member = User::factory()->create(['verified_at' => now()]);
    $member->assignRole('citizen');
    $headers = authHeader($member);
    $this->getJson('/users/me', $headers)->assertOk()
        ->assertJsonPath('data.community_verified_at', fn ($value): bool => is_string($value) && $value !== '');
    $member->forceFill(['verified_at' => null])->save();
    resolve('auth')->forgetGuards();

    $this->postJson('/polls', publishingPayload(), $headers)->assertForbidden()
        ->assertJsonPath('success', false)->assertJsonPath('messages.0', 'you_are_not_verified');
    $this->assertDatabaseMissing('polls', ['question' => publishingPayload()['question']]);
});

it('does not allow an ID-only user to vote with a valid poll and option', function (): void {
    $owner = User::factory()->create(['verified_at' => now()]);
    $owner->assignRole('citizen');
    $created = $this->postJson('/polls', publishingPayload(), authHeader($owner))->assertOk();
    $pollId = $created->json('data.id');
    $optionId = PollOption::where('poll_id', $pollId)->firstOrFail()->id;
    $member = User::factory()->create(['verified_at' => null]);
    $member->assignRole('citizen');
    IdentityVerificationCredit::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'owner_id' => $member->id,
        'source' => IdentityVerificationCredit::SOURCE_PURCHASE,
        'status' => 'approved',
        'provider_session_id' => (string) Str::uuid(),
    ]);
    resolve('auth')->forgetGuards();
    $this->postJson('/polls/vote', ['poll_id' => $pollId, 'poll_option_id' => [$optionId]], authHeader($member))
        ->assertForbidden()->assertJsonPath('messages.0', 'you_are_not_verified');
    expect(PollVote::where('poll_id', $pollId)->count())->toBe(0);
});

it('allows a community-only user to vote without an ID approval', function (): void {
    $member = User::factory()->create(['verified_at' => now()]);
    $member->assignRole('citizen');
    $headers = authHeader($member);
    $created = $this->postJson('/polls', publishingPayload(), $headers)->assertOk();
    $pollId = $created->json('data.id');
    $optionId = PollOption::where('poll_id', $pollId)->firstOrFail()->id;
    $this->postJson('/polls/vote', ['poll_id' => $pollId, 'poll_option_id' => [$optionId]], $headers)
        ->assertOk()->assertJsonPath('success', true);
    $this->assertDatabaseHas('poll_votes', ['poll_id' => $pollId, 'user_id' => $member->id, 'poll_option_id' => $optionId]);
});
