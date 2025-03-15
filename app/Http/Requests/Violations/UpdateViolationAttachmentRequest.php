<?php

namespace App\Http\Requests\Violations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateViolationAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $violation = $this->route('violation');
        if ($user->id === $violation->user_id) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['required', 'array'],
        ];
    }
}
