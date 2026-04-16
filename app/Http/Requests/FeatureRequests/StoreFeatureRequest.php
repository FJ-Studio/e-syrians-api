<?php

declare(strict_types=1);

namespace App\Http\Requests\FeatureRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreFeatureRequest extends FormRequest
{
    /**
     * Authorization is handled upstream by auth:sanctum + UserIsVerified
     * middleware. Any authenticated verified user can submit a feature.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['required', 'string', 'min:20', 'max:4000'],
        ];
    }
}
