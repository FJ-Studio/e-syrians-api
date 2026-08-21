<?php

declare(strict_types=1);

namespace App\Http\Requests\Audiences;

use App\Enums\AudienceEntryTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

class AddAudienceEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:5000'],
            'entries.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Same per-entry format check as StoreAudienceRequest — kept
     * as a duplicate rather than a shared trait so each request
     * can evolve independently (e.g. if bulk-add ever needs
     * different limits from initial-create).
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
