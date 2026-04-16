<?php

declare(strict_types=1);

namespace App\Http\Requests\FeatureRequests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-only soft-delete with a required deletion_reason for the audit trail
 * (mirrors the Poll moderation pattern — a soft delete without a reason is
 * useless for moderation review).
 */
class DestroyFeatureRequest extends FormRequest
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
            'deletion_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
