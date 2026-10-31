<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Models\User;
use App\Models\Report;
use App\Models\FeatureRequest;
use App\Enums\ReportStatusEnum;
use App\Contracts\ReportServiceContract;
use Illuminate\Validation\ValidationException;

class ReportService implements ReportServiceContract
{
    public function submit(User $reporter, array $data): Report
    {
        // Never resolve a client-supplied model class or arbitrary morph type.
        $target = match ($data['reportable_type']) {
            'user' => User::query()->where('uuid', $data['reportable_id'])->firstOrFail(),
            'poll' => $this->findPoll((int) $data['reportable_id'], $reporter),
            'feature_request' => FeatureRequest::query()->findOrFail($data['reportable_id']),
            default => abort(422, 'invalid_report_target'),
        };

        if ($target instanceof User && $target->is($reporter)) {
            throw ValidationException::withMessages(['reportable_id' => ['cannot_report_yourself']]);
        }

        // The unique index also protects concurrent retries. Existing reports
        // retain their evidence and moderation outcome instead of being overwritten.
        return $target->reports()->firstOrCreate([
            'reported_by' => $reporter->id,
        ], [
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
        ]);
    }

    public function review(Report $report, User $reviewer, ReportStatusEnum $status, ?string $notes): void
    {
        abort_unless($reviewer->hasRole('admin'), 403);

        if ($status !== ReportStatusEnum::Pending && mb_strlen(trim($notes ?? '')) < 3) {
            throw ValidationException::withMessages(['review_notes' => ['review_notes_required']]);
        }

        $report->forceFill([
            'status' => $status,
            'reviewed_by' => $status === ReportStatusEnum::Pending ? null : $reviewer->id,
            'reviewed_at' => $status === ReportStatusEnum::Pending ? null : now(),
            'review_notes' => $status === ReportStatusEnum::Pending ? null : trim($notes ?? ''),
        ])->save();
    }

    private function findPoll(int $id, User $reporter): Poll
    {
        $poll = Poll::withoutGlobalScope('public_polls')->findOrFail($id);

        abort_if($poll->is_private && $poll->created_by !== $reporter->id, 404);
        abort_unless($poll->isVisibleTo($reporter), 404);

        return $poll;
    }
}
