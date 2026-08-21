<?php

declare(strict_types=1);

namespace App\Http\Requests\Audiences;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership check lives in AudienceService::findOwnedOrFail.
        // We just need an authenticated user here.
        return $this->user() !== null;
    }

    /**
     * PATCH — every field is optional. `sometimes` means "only
     * validate if present in the payload"; combined with
     * `array_filter` in the service that means missing keys
     * leave the existing value untouched.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
