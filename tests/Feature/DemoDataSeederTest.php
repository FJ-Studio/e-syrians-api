<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('gives every demo user the standard citizen role and preserves admin access', function (): void {
    $this->seed(DemoDataSeeder::class);

    $users = User::whereLike('email', '%@demo.e-syrians.com')->get();
    expect($users)->toHaveCount(30);
    foreach ($users as $user) {
        expect($user->hasRole('citizen'))->toBeTrue();
    }
    expect(User::where('email', 'admin@demo.e-syrians.com')->firstOrFail()->hasRole('admin'))->toBeTrue();
});

it('allows the community-verified demo login to publish but still blocks an unverified demo', function (): void {
    $this->seed(DemoDataSeeder::class);
    $payload = [
        'question' => 'Can a seeded member publish a poll?',
        'start_date' => now()->toDateString(),
        'duration' => 7,
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => false,
        'options' => ['Yes', 'No'],
    ];
    $verified = User::where('email', 'feras@demo.e-syrians.com')->firstOrFail();
    $this->postJson('/polls', $payload, authHeader($verified))->assertOk()
        ->assertJsonPath('success', true)->assertJsonPath('data.question', $payload['question']);
    $this->assertDatabaseHas('polls', ['question' => $payload['question'], 'created_by' => $verified->id]);

    $unverified = User::where('email', 'omar@demo.e-syrians.com')->firstOrFail();
    resolve('auth')->forgetGuards();
    $before = Poll::withoutGlobalScopes()->count();
    $this->postJson('/polls', $payload, authHeader($unverified))->assertForbidden()
        ->assertJsonPath('messages.0', 'you_are_not_verified');
    expect(Poll::withoutGlobalScopes()->count())->toBe($before);
});

it('repairs existing demo roles idempotently without changing account data or unrelated users', function (): void {
    $legacy = User::factory()->create([
        'email' => 'legacy@demo.e-syrians.com',
        'name' => 'Preserved',
        'surname' => 'Demo',
        'password' => 'preserve-this-password',
        'verified_at' => null,
    ]);
    $unrelated = User::factory()->create(['email' => 'not-a-demo@example.test']);

    $this->seed(DemoDataSeeder::class);
    $userCount = User::count();
    $pollCount = Poll::withoutGlobalScopes()->count();
    $this->seed(DemoDataSeeder::class);

    $legacy->refresh();
    expect($legacy->hasRole('citizen'))->toBeTrue()
        ->and($legacy->roles()->count())->toBe(1)
        ->and($legacy->name)->toBe('Preserved')
        ->and($legacy->verified_at)->toBeNull()
        ->and(Hash::check('preserve-this-password', $legacy->password))->toBeTrue()
        ->and($unrelated->fresh()->getRoleNames()->all())->toBe([])
        ->and(User::count())->toBe($userCount)
        ->and(Poll::withoutGlobalScopes()->count())->toBe($pollCount);
});
