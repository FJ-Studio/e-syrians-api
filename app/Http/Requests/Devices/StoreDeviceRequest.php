<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->currentAccessToken() instanceof PersonalAccessToken;
    }

    public function rules(): array
    {
        return [
            // Reject stale client work that picked up a different account's bearer.
            'user_uuid' => ['required', 'uuid', Rule::in([$this->user()?->uuid])],
            'installation_id' => ['required', 'uuid'],
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:/\A(?:ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]\z/'],
            'platform' => ['required', 'in:ios,android'],
            'model' => ['nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:en,ar,ku'],
            'consent' => ['required', 'accepted'],
        ];
    }
}
