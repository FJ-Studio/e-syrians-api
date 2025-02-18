<?php

namespace App\Http\Requests\Polls;

use Illuminate\Foundation\Http\FormRequest;

class StorePollReaction extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
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
            'poll_id' => ['required', 'integer', 'exists:polls,id'],
            'reaction' => ['required', 'in:up,down'],
        ];
    }
}
