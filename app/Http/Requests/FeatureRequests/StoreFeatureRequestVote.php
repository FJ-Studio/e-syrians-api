<?php

declare(strict_types=1);

namespace App\Http\Requests\FeatureRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreFeatureRequestVote extends FormRequest
{
    /**
     * Authorization handled by auth:sanctum + UserIsVerified middleware.
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
            'feature_request_id' => ['required', 'integer', 'exists:feature_requests,id'],
            'vote' => ['required', 'in:up,down'],
        ];
    }
}
