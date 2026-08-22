<?php

declare(strict_types=1);

namespace App\Http\Requests\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

class VerifyTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string'],
            'is_recovery_code' => ['sometimes', 'boolean'],
        ];
    }
}
