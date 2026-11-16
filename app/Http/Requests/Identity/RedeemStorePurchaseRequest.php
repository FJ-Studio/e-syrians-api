<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

class RedeemStorePurchaseRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'platform' => ['bail', 'required', 'string', 'in:ios,android'],
            'proof' => ['bail', 'required', 'string', 'max:2048', 'regex:/^[!-~]+$/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform.required' => 'identity_store_platform_required',
            'platform.string' => 'identity_store_platform_invalid',
            'platform.in' => 'identity_store_platform_invalid',
            'proof.required' => 'identity_store_proof_required',
            'proof.string' => 'identity_store_proof_invalid',
            'proof.max' => 'identity_store_proof_invalid',
            'proof.regex' => 'identity_store_proof_invalid',
        ];
    }
}
