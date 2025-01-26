<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserProviderEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                new Enum(UserProviderEnum::class),
            ],
            'token' => ['required', 'string'],
        ];
    }
}
