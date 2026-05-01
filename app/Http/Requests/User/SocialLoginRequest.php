<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserProviderEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class SocialLoginRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                new Enum(UserProviderEnum::class),
            ],
            'token' => ['required', 'string'],
            // Optional. Apple sends the user's name only on the first sign-in
            // (it isn't in the JWT — it comes from the client SDK separately).
            // We accept it here so the user record gets a real name on creation
            // instead of the email-derived fallback.
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
