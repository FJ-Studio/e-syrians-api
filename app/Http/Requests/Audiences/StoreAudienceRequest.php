<?php

declare(strict_types=1);

namespace App\Http\Requests\Audiences;

use App\Enums\AudienceEntryTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user can create audiences. The route
        // group already applies auth:sanctum + UserIsVerified.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'entries' => ['nullable', 'array', 'max:5000'],
            'entries.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Per-entry format validation on top of the string/max checks
     * — must resolve to email OR 5–20 digit national ID via the
     * enum's detector. Runs after normal validation so we don't
     * report format errors on entries that already failed
     * required/max.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $entries = $this->input('entries', []);
            if (! is_array($entries)) {
                return;
            }
            foreach ($entries as $i => $raw) {
                if (! is_string($raw) || trim($raw) === '') {
                    continue;
                }
                if (AudienceEntryTypeEnum::detect($raw) === null) {
                    $v->errors()->add("entries.$i", 'audience_entry_invalid_format');
                }
            }
        });
    }
}
