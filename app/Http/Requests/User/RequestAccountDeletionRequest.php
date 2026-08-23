<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class RequestAccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The service does the actual Hash::check — validation just
        // enforces that a non-empty password is present so the service
        // never sees `null`.
        return [
            'password' => ['required', 'string', 'min:1'],
        ];
    }
}
