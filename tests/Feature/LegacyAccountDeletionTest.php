<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\FeatureRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Services\AccountDeletionService;
use App\Jobs\HardDeleteExpiredAccountsJob;
use Illuminate\Support\Facades\Exceptions;
use App\Contracts\AccountDeletionServiceContract;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Mail::fake();
    Exceptions::fake();
});

it('honors explicit deadlines and the legacy soft-deletion grace period', function (?int $deletedDaysAgo, ?int $scheduledDaysFromNow, bool $eligible): void {
    $user = User::factory()->create([
        'created_at' => now()->subYears(3),
        'deleted_at' => $deletedDaysAgo === null ? null : now()->subDays($deletedDaysAgo),
        'deletion_requested_at' => $scheduledDaysFromNow === null ? null : now()->subDays(16),
        'deletion_scheduled_for' => $scheduledDaysFromNow === null ? null : now()->addDays($scheduledDaysFromNow),
    ]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    expect(User::withTrashed()->find($user->id) === null)->toBe($eligible);
    Exceptions::assertNothingReported();
    Http::assertNothingSent();
})->with([
    'legacy expired' => [16, null, true],
    'legacy at deadline' => [AccountDeletionService::GRACE_DAYS, null, true],
    'legacy inside grace period' => [14, null, false],
    'old active account without a request' => [null, null, false],
    'active scheduled account expired' => [null, -1, true],
    'soft-deleted scheduled account expired' => [1, -1, true],
    'soft-deleted scheduled account at deadline' => [1, 0, true],
    'future explicit deadline overrides old soft-deletion date' => [30, 1, false],
    'active account inside grace period' => [null, 1, false],
]);

it('does not delete a legacy account one second before its grace period ends', function (): void {
    $user = User::factory()->create([
        'deleted_at' => now()->subDays(AccountDeletionService::GRACE_DAYS)->addSecond(),
    ]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    expect(User::withTrashed()->find($user->id))->not->toBeNull();
    Exceptions::assertNothingReported();
});

it('allows password registration with a fresh identity only after permanent deletion', function (bool $legacy): void {
    $user = User::factory()->create([
        'email' => 'reusable-email@gmail.com',
        ...($legacy ? ['deleted_at' => now()->subDays(16)] : [
            'deletion_requested_at' => now()->subDays(16), 'deletion_scheduled_for' => now()->subDay(),
        ]),
    ]);
    $previousUuid = (string) $user->uuid;
    $payload = [
        'name' => 'Registration', 'surname' => 'Fixture', 'email' => $user->email,
        'password' => 'password123', 'password_confirmation' => 'password123',
        'national_id' => '99887766123', 'gender' => 'm', 'birth_date' => '1990-01-01',
        'hometown' => 'damascus', 'country' => 'TR', 'ethnicity' => 'arab',
    ];

    $this->postJson(route('users.register'), $payload)
        ->assertUnprocessable()->assertJsonStructure(['messages' => ['email']]);

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));
    $this->assertDatabaseMissing('users', ['id' => $user->id]);

    $this->postJson(route('users.register'), $payload)->assertCreated();

    $registered = User::where('email', $user->email)->sole();
    expect((string) $registered->uuid)->not->toBe($previousUuid)
        ->and($registered->hasVerifiedEmail())->toBeFalse()
        ->and($registered->google_id)->toBeNull()
        ->and($registered->verified_at)->toBeNull()
        ->and($registered->hasPendingDeletion())->toBeFalse();
    Exceptions::assertNothingReported();
})->with(['scheduled' => [false], 'legacy soft-deleted' => [true]]);

it('rechecks eligibility when deletion is cancelled, restored or postponed after a batch is loaded', function (string $change): void {
    $first = User::factory()->create(['deleted_at' => now()->subDays(16)]);
    $next = User::factory()->create($change === 'restored' ? [
        'deleted_at' => now()->subDays(16),
    ] : [
        'deletion_requested_at' => now()->subDays(16),
        'deletion_scheduled_for' => now()->subDay(),
    ]);
    $service = resolve(AccountDeletionServiceContract::class);
    $guardedService = Mockery::mock(AccountDeletionServiceContract::class);
    $guardedService->shouldReceive('hardDeleteAccount')->once()
        ->withArgs(fn (User $user): bool => $user->id === $first->id)
        ->andReturnUsing(function (User $user) use ($service, $next, $change): void {
            $service->hardDeleteAccount($user);
            if ($change === 'restored') {
                $next->restore();
            } else {
                $next->forceFill([
                    'deletion_requested_at' => $change === 'cancelled' ? null : now(),
                    'deletion_scheduled_for' => $change === 'cancelled' ? null : now()->addDays(15),
                ])->save();
            }
        });

    (new HardDeleteExpiredAccountsJob())->handle($guardedService);

    expect(User::withTrashed()->find($first->id))->toBeNull()
        ->and(User::find($next->id))->not->toBeNull();
    Exceptions::assertNothingReported();
})->with(['cancelled', 'restored', 'postponed']);

it('does not skip legacy accounts across deletion batch boundaries', function (): void {
    $users = User::factory()->count(51)->create(['deleted_at' => now()->subDays(16)]);
    $active = User::factory()->create();

    (new HardDeleteExpiredAccountsJob())->handle(resolve(AccountDeletionServiceContract::class));

    expect(User::withTrashed()->whereKey($users->modelKeys())->count())->toBe(0)
        ->and(User::find($active->id))->not->toBeNull();
    Exceptions::assertNothingReported();
});

it('rolls back failed local cleanup, continues the sweep and retries without releasing the email early', function (): void {
    $user = User::factory()->create(['deleted_at' => now()->subDays(16)]);
    $request = FeatureRequest::create([
        'title' => 'Retain on rollback', 'description' => 'Deletion regression fixture', 'created_by' => $user->id,
    ]);
    $user->createToken('deletion-fixture');
    $other = User::factory()->create(['deleted_at' => now()->subDays(16)]);
    $fail = true;
    User::forceDeleting(function (User $deleting) use ($user, &$fail): void {
        if ($deleting->id === $user->id && $fail) {
            throw new RuntimeException('Simulated local deletion failure');
        }
    });
    $service = resolve(AccountDeletionServiceContract::class);

    (new HardDeleteExpiredAccountsJob())->handle($service);

    expect(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and(User::withTrashed()->find($other->id))->toBeNull()
        ->and(FeatureRequest::withTrashed()->find($request->id))->not->toBeNull()
        ->and($user->tokens()->count())->toBe(1);
    $this->assertDatabaseHas('users', ['email' => $user->email]);
    Exceptions::assertReported(RuntimeException::class);

    $fail = false;
    (new HardDeleteExpiredAccountsJob())->handle($service);

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('feature_requests', ['id' => $request->id]);
    expect($user->tokens()->count())->toBe(0);
});

it('previews eligible scheduled and legacy deletions without changing data or calling providers', function (): void {
    $scheduled = User::factory()->create(['deletion_scheduled_for' => now()->subDay()]);
    $legacy = User::factory()->create(['deleted_at' => now()->subDays(16)]);
    User::factory()->create(['deleted_at' => now()->subDay()]);
    User::factory()->create(['deleted_at' => now()->subDays(30), 'deletion_scheduled_for' => now()->addDay()]);
    $before = User::withTrashed()->get()->map->getAttributes()->all();
    $service = Mockery::mock(AccountDeletionServiceContract::class);
    $service->shouldNotReceive('hardDeleteAccount');
    $this->app->instance(AccountDeletionServiceContract::class, $service);

    $this->artisan('accounts:preview-deletions')
        ->expectsOutput('Eligible accounts: 2')
        ->expectsOutput('Scheduled deadline reached: 1')
        ->expectsOutput('Legacy soft-deletion grace period reached: 1')
        ->expectsOutput('Preview only. No records were deleted.')
        ->doesntExpectOutputToContain($scheduled->email)
        ->doesntExpectOutputToContain($legacy->email)
        ->assertSuccessful();

    expect(User::withTrashed()->get()->map->getAttributes()->all())->toBe($before);
    Http::assertNothingSent();
    Exceptions::assertNothingReported();
});
