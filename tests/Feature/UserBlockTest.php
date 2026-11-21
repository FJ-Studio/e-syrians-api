<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\PollVote;
use App\Models\UserBlock;
use App\Models\PollOption;
use App\Models\FeatureRequest;

it('keeps a private, idempotent block list and lets its owner undo a block', function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $headers = authHeader($actor);
    $url = '/users/blocks/'.$target->uuid;

    $this->putJson($url, [], $headers)->assertOk();
    $this->putJson($url, [], $headers)->assertOk();
    $this->putJson('/users/blocks/'.$actor->uuid, [], $headers)->assertUnprocessable();
    expect(UserBlock::count())->toBe(1);
    $this->getJson('/users/blocks', $headers)
        ->assertJsonPath('data.0.uuid', (string) $target->uuid)
        ->assertJsonMissingPath('data.0.email');

    auth()->forgetGuards();
    $targetHeaders = authHeader($target);
    $this->getJson('/users/blocks', $targetHeaders)->assertJsonPath('data', []);
    $this->deleteJson('/users/blocks/'.$actor->uuid, [], $targetHeaders)->assertOk();
    expect(UserBlock::count())->toBe(1);

    auth()->forgetGuards();
    $this->deleteJson($url, [], $headers)->assertOk();
    $this->deleteJson($url, [], $headers)->assertOk();
    $this->getJson('/users/blocks', $headers)->assertJsonPath('data', []);
});

it('hides blocked profiles and authored content from both signed-in accounts but not from unrelated viewers', function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $other = User::factory()->create();
    $poll = Poll::forceCreate([
        'question' => 'A public question from the blocked account?',
        'created_by' => $target->id,
        'start_date' => now(),
        'end_date' => now()->addWeek(),
        'max_selections' => 1,
        'is_private' => false,
        'audience_only' => false,
    ]);
    $feature = FeatureRequest::forceCreate([
        'title' => 'A public request from the blocked account',
        'description' => 'Test content',
        'created_by' => $target->id,
    ]);
    $headers = authHeader($actor);
    $profileUrl = '/users/verify/'.$target->uuid;
    $this->getJson($profileUrl, $headers)->assertOk();
    $this->putJson('/users/blocks/'.$target->uuid, [], $headers)->assertOk();
    $this->getJson($profileUrl, $headers)->assertNotFound();
    $this->getJson('/polls/'.$poll->id, $headers)->assertNotFound();
    $this->getJson('/polls', $headers)->assertJsonPath('data.polls', []);
    $this->getJson('/feature-requests/'.$feature->id, $headers)->assertNotFound();
    $this->getJson('/feature-requests', $headers)->assertJsonPath('data.feature_requests', []);

    auth()->forgetGuards();
    $this->getJson('/users/verify/'.$actor->uuid, authHeader($target))->assertNotFound();
    auth()->forgetGuards();
    $this->getJson($profileUrl, authHeader($other))->assertOk();
    auth()->forgetGuards();
    $this->getJson('/polls/'.$poll->id)->assertOk();
    auth()->forgetGuards();
    $this->deleteJson('/users/blocks/'.$target->uuid, [], $headers)->assertOk();
    $this->getJson('/polls/'.$poll->id, $headers)->assertOk();
    $this->getJson('/feature-requests/'.$feature->id, $headers)->assertOk();
});

it('filters blocked voters from the visible voter preview without changing poll totals', function (): void {
    $viewer = User::factory()->create();
    $blocked = User::factory()->create();
    $author = User::factory()->create();
    $poll = Poll::forceCreate([
        'question' => 'A public question from another account?',
        'created_by' => $author->id,
        'start_date' => now(),
        'end_date' => now()->addWeek(),
        'max_selections' => 1,
        'voters_are_visible' => true,
        'is_private' => false,
    ]);
    $option = PollOption::forceCreate(['poll_id' => $poll->id, 'option_text' => 'Yes', 'created_by' => $author->id]);
    PollVote::forceCreate(['poll_id' => $poll->id, 'poll_option_id' => $option->id, 'user_id' => $blocked->id]);
    $headers = authHeader($viewer);
    $pollUrl = '/polls/'.$poll->id;

    $this->getJson($pollUrl, $headers)->assertOk()->assertJsonPath('data.options.0.voters_preview.0.id', (string) $blocked->uuid);
    $this->putJson('/users/blocks/'.$blocked->uuid, [], $headers)->assertOk();
    $this->getJson($pollUrl, $headers)->assertOk()
        ->assertJsonPath('data.options.0.voters_preview', [])
        ->assertJsonPath('data.unique_voters_count', 1);
});

it('requires authentication to read or modify blocks', function (): void {
    $target = User::factory()->create();
    $this->getJson('/users/blocks')->assertUnauthorized();
    $this->putJson('/users/blocks/'.$target->uuid)->assertUnauthorized();
    $this->deleteJson('/users/blocks/'.$target->uuid)->assertUnauthorized();
});
