<?php

namespace App\Http\Requests\Violations;

use App\Enums\ViolationCategoryEnum;
use App\Enums\ViolationStatusEnum;
use Illuminate\Foundation\Http\FormRequest;

class StoreViolationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255', 'in:'.implode(',', array_map(fn ($case) => $case->value, ViolationCategoryEnum::cases()))],
            'target' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'date_of_violation' => ['required', 'date'],
            'location' => ['required', 'string'],
            'target_group' => ['required', 'string'],
            // move files into update request
            // 'attachments' => ['nullable', 'array'],
            // 'attachments.*' => ['required', 'file'],
            'links' => ['nullable', 'array'],
            'links.*' => ['required', 'url'],
            'status' => ['required', 'string', 'in:'.implode(',', array_map(fn ($case) => $case->value, ViolationStatusEnum::cases()))],
        ];
    }
}
