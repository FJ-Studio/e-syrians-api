<?php

declare(strict_types=1);

use App\Models\Poll;
use App\Models\User;
use App\Models\Report;
use Livewire\Livewire;
use App\Models\FeatureRequest;
use App\Enums\ReportStatusEnum;
use App\Http\Middleware\Recaptcha;
use App\Contracts\ReportServiceContract;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    test()->reporter = User::factory()->create(['verified_at' => null, 'country' => 'TR']);
    test()->targetUser = User::factory()->create();
    test()->targetPoll = Poll::forceCreate([
        'question' => 'Reported poll question?',
        'created_by' => test()->targetUser->id,
        'start_date' => now(),
        'end_date' => now()->addDays(7),
        'max_selections' => 1,
        'audience_can_add_options' => false,
        'reveal_results' => 'before-voting',
        'voters_are_visible' => false,
        'is_private' => false,
        'audience_only' => false,
    ]);
    test()->targetFeature = FeatureRequest::forceCreate([
        'title' => 'Reported feature request',
        'description' => 'Feature request content for moderation.',
        'created_by' => test()->targetUser->id,
    ]);
});

function reportPayload(string $type = 'poll'): array
{
    return [
        'reportable_type' => $type,
        'reportable_id' => match ($type) {
            'user' => test()->targetUser->uuid,
            'feature_request' => test()->targetFeature->id,
            default => test()->targetPoll->id,
        },
        'reason' => 'spam',
        'details' => 'Repeated unsolicited advertising.',
    ];
}

it('requires sign in before accepting a report', function (): void {
    $this->postJson('/reports', reportPayload())->assertUnauthorized();
    expect(Report::count())->toBe(0);
});

it('accepts an unverified user report and resolves both morph directions', function (string $type): void {
    $payload = reportPayload($type);
    $response = $this->postJson('/reports', $payload, authHeader(test()->reporter));
    $response->assertCreated()->assertJsonPath('messages.0', 'report_submitted');
    $report = Report::findOrFail($response->json('data.id'));
    $target = match ($type) {
        'user' => test()->targetUser,
        'feature_request' => test()->targetFeature,
        default => test()->targetPoll,
    };

    expect($report->reportable->is($target))->toBeTrue()
        ->and($target->reports()->sole()->is($report))->toBeTrue()
        ->and($report->reporter->is(test()->reporter))->toBeTrue()
        ->and($report->status)->toBe(ReportStatusEnum::Pending);
    expect($response->json('data'))->toHaveKeys(['id'])->not->toHaveKeys(['reported_by', 'details', 'review_notes']);
})->with(['user', 'poll', 'feature_request']);

it('deduplicates retries without overwriting the original report or outcome', function (): void {
    $headers = authHeader(test()->reporter);
    $first = $this->postJson('/reports', reportPayload(), $headers)->assertCreated();
    $report = Report::findOrFail($first->json('data.id'));
    $report->forceFill(['status' => ReportStatusEnum::Dismissed, 'review_notes' => 'Reviewed original report'])->save();
    $second = $this->postJson('/reports', [...reportPayload(), 'reason' => 'harassment'], $headers);
    $second->assertOk()->assertJsonPath('data.id', $report->id)->assertJsonPath('messages.0', 'report_already_submitted');
    expect(Report::count())->toBe(1)
        ->and($report->fresh()->reason->value)->toBe('spam')
        ->and($report->fresh()->status)->toBe(ReportStatusEnum::Dismissed);
});

it('accepts supported reasons and optional details', function (string $reason): void {
    $this->postJson('/reports', [
        ...reportPayload(), 'reason' => $reason, 'details' => null,
    ], authHeader(test()->reporter))->assertCreated();
})->with(['spam', 'harassment', 'hate_speech', 'inappropriate_content', 'misinformation', 'impersonation', 'privacy']);

it('allows separate reporters to report the same target', function (): void {
    $this->postJson('/reports', reportPayload(), authHeader(test()->reporter))->assertCreated();
    auth('sanctum')->forgetUser();
    $this->postJson('/reports', reportPayload(), authHeader(test()->targetUser))->assertCreated();
    expect(test()->targetPoll->reports()->count())->toBe(2);
});

it('rejects invalid report input', function (array $changes, string $field): void {
    $response = $this->postJson('/reports', [...reportPayload(), ...$changes], authHeader(test()->reporter));
    $response->assertUnprocessable();
    expect($response->json('messages'))->toHaveKey($field);
    expect(Report::count())->toBe(0);
})->with([
    'arbitrary model' => [['reportable_type' => User::class], 'reportable_type'],
    'unknown type' => [['reportable_type' => 'device'], 'reportable_type'],
    'array type' => [['reportable_type' => ['poll']], 'reportable_type'],
    'missing target' => [['reportable_id' => null], 'reportable_id'],
    'invalid target' => [['reportable_id' => 'abc'], 'reportable_id'],
    'negative target' => [['reportable_id' => -1], 'reportable_id'],
    'user numeric ID' => [['reportable_type' => 'user', 'reportable_id' => 1], 'reportable_id'],
    'unknown reason' => [['reason' => 'anything'], 'reason'],
    'missing reason' => [['reason' => null], 'reason'],
    'other without details' => [['reason' => 'other', 'details' => null], 'details'],
    'short details' => [['details' => 'bad'], 'details'],
    'long details' => [['details' => str_repeat('x', 2001)], 'details'],
]);

it('does not allow spoofing reporter or moderation fields', function (): void {
    $response = $this->postJson('/reports', [
        ...reportPayload(),
        'reported_by' => test()->targetUser->id,
        'status' => 'resolved',
        'reviewed_by' => test()->targetUser->id,
        'review_notes' => 'Forged review',
        'reportable' => ['id' => test()->targetUser->id],
    ], authHeader(test()->reporter))->assertCreated();
    $report = Report::findOrFail($response->json('data.id'));
    expect($report->reported_by)->toBe(test()->reporter->id)
        ->and($report->status)->toBe(ReportStatusEnum::Pending)
        ->and($report->reviewed_by)->toBeNull()
        ->and($report->review_notes)->toBeNull();
});

it('does not allow users to report themselves', function (): void {
    $this->postJson('/reports', [
        ...reportPayload('user'), 'reportable_id' => test()->reporter->uuid,
    ], authHeader(test()->reporter))->assertUnprocessable();
});

it('rejects missing and deleted targets', function (string $type, bool $deleted): void {
    $payload = reportPayload($type);
    if ($deleted) {
        match ($type) {
            'user' => test()->targetUser->delete(),
            'feature_request' => test()->targetFeature->delete(),
            default => test()->targetPoll->delete(),
        };
    } else {
        $payload['reportable_id'] = $type === 'user' ? fake()->uuid() : 999999;
    }
    $this->postJson('/reports', $payload, authHeader(test()->reporter))->assertNotFound();
    expect(Report::count())->toBe(0);
})->with(['user', 'poll', 'feature_request'])->with([true, false]);

it('does not expose private polls through reporting', function (): void {
    test()->targetPoll->update(['is_private' => true]);
    $this->postJson('/reports', reportPayload(), authHeader(test()->reporter))->assertNotFound();
});

it('allows reporting audience-only polls only when visible', function (): void {
    test()->targetPoll->update(['audience_only' => true]);
    test()->targetPoll->audienceRules()->create(['criterion' => 'country', 'value' => 'DE']);
    $headers = authHeader(test()->reporter);
    $this->postJson('/reports', reportPayload(), $headers)->assertNotFound();
    test()->reporter->update(['country' => 'DE']);
    auth('sanctum')->forgetUser();
    $this->postJson('/reports', reportPayload(), $headers)->assertCreated();
});

it('retains reported targets for moderation after soft deletion', function (string $type): void {
    $report = resolve(ReportServiceContract::class)->submit(test()->reporter, reportPayload($type));
    $target = $report->reportable;
    if ($target instanceof Poll) {
        $target->update(['is_private' => true]);
    }
    $target->delete();
    expect($report->fresh()->reportable?->is($target))->toBeTrue();
})->with(['user', 'poll', 'feature_request']);

it('does not expose reports through public or authenticated API reads', function (): void {
    $report = resolve(ReportServiceContract::class)->submit(test()->reporter, reportPayload());
    $this->getJson('/reports')->assertStatus(405);
    $this->getJson('/reports/'.$report->id, authHeader(test()->reporter))->assertNotFound();
    $this->patchJson('/reports/'.$report->id, ['status' => 'resolved'], authHeader(test()->reporter))->assertNotFound();
});

it('blocks report submission during pending account deletion', function (): void {
    test()->reporter->update(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(15)]);
    $this->postJson('/reports', reportPayload(), authHeader(test()->reporter))->assertForbidden();
});

it('requires recaptcha on the submission route', function (): void {
    $this->app->forgetInstance(Recaptcha::class);
    $this->postJson('/reports', reportPayload(), authHeader(test()->reporter))
        ->assertStatus(400)->assertJsonPath('messages.0', 'recaptcha_token_required');
});

it('throttles report submissions', function (): void {
    $headers = authHeader(test()->reporter);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/reports', reportPayload(), $headers)->assertSuccessful();
    }
    $this->postJson('/reports', reportPayload(), $headers)->assertStatus(429);
});

it('restricts moderation to administrators', function (): void {
    $report = resolve(ReportServiceContract::class)->submit(test()->reporter, reportPayload());
    expect(fn () => resolve(ReportServiceContract::class)->review($report, test()->reporter, ReportStatusEnum::Resolved, 'Removed spam'))
        ->toThrow(HttpException::class);
    $this->actingAs(test()->reporter);
    expect(ReportResource::canViewAny())->toBeFalse();
    Livewire::test(ListReports::class)->assertForbidden();
    Livewire::test(ViewReport::class, ['record' => $report->id])->assertForbidden();
});

it('lets administrators view and process reports in the existing panel', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $report = resolve(ReportServiceContract::class)->submit(test()->reporter, reportPayload());
    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$report])
        ->callTableAction('resolved', $report, data: ['review_notes' => 'Inspected the poll and removed spam.'])
        ->assertHasNoTableActionErrors();

    $report->refresh();
    expect($report->status)->toBe(ReportStatusEnum::Resolved)
        ->and($report->reviewed_by)->toBe($admin->id)
        ->and($report->reviewed_at)->not->toBeNull();

    Livewire::test(ViewReport::class, ['record' => $report->id])
        ->assertSuccessful()
        ->assertSee('Reported poll question?')
        ->callAction('reopen');

    expect($report->fresh()->status)->toBe(ReportStatusEnum::Pending)
        ->and($report->fresh()->reviewed_by)->toBeNull()
        ->and($report->fresh()->reviewed_at)->toBeNull();
});

it('requires moderation notes when closing a report', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $report = resolve(ReportServiceContract::class)->submit(test()->reporter, reportPayload());
    expect(fn () => resolve(ReportServiceContract::class)->review($report, $admin, ReportStatusEnum::Dismissed, ' '))
        ->toThrow(ValidationException::class);
});
