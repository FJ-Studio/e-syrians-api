<?php

declare(strict_types=1);

namespace App\Http\Requests\Polls;

use Illuminate\Foundation\Http\FormRequest;

class StorePollVoteRequest extends FormRequest
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
            'poll_option_id' => ['required', 'array'],
            'poll_option_id.*' => ['required', 'integer', 'exists:poll_options,id'],
            'poll_id' => ['required', 'integer', 'exists:polls,id'],
        ];
    }
}
