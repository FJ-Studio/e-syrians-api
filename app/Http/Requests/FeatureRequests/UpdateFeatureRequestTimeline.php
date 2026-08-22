<?php

declare(strict_types=1);

namespace App\Http\Requests\FeatureRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-only partial update of a feature request's timeline stamps.
 *
 * `sometimes` lets the caller submit only the fields they want to change;
 * a literal `null` value clears a previously-set stamp. The controller
 * forwards only the validated fields that are actually present in the
 * request to the service, so omission != null.
 */
class UpdateFeatureRequestTimeline extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `role:admin` middleware is the source of truth.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'coded_at' => ['sometimes', 'nullable', 'date'],
            'tested_at' => ['sometimes', 'nullable', 'date'],
            'deployed_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
