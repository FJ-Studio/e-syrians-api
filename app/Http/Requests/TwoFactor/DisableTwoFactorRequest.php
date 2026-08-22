<?php

declare(strict_types=1);

namespace App\Http\Requests\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string'],
            'is_recovery_code' => ['sometimes', 'boolean'],
        ];
    }
}
