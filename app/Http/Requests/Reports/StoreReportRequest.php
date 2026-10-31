<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Enums\ReportReasonEnum;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reportable_type' => ['required', Rule::in(['user', 'poll', 'feature_request'])],
            // Users have public UUIDs; polls and feature requests have numeric IDs.
            'reportable_id' => $this->input('reportable_type') === 'user'
                ? ['required', 'uuid']
                : ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::enum(ReportReasonEnum::class)],
            'details' => ['required_if:reason,other', 'nullable', 'string', 'min:10', 'max:2000'],
        ];
    }
}
