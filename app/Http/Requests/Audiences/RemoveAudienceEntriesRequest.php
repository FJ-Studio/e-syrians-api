<?php

declare(strict_types=1);

namespace App\Http\Requests\Audiences;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk-remove request for AudienceController::removeEntries.
 *
 * Web/mobile clients used to fan out one DELETE per selected entry,
 * which multiplied the per-request recaptcha + throttle cost and
 * produced partial-delete outcomes on any transient failure. This
 * FormRequest backs the batched endpoint that took its place.
 */
class RemoveAudienceEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entry_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'entry_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
