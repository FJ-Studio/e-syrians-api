<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the request body for the pre-registration email
 * availability check (`POST /users/check-email-availability`).
 *
 * Notes:
 *   - We validate FORMAT here, not uniqueness — the controller checks
 *     uniqueness against the hashed-email column and returns the
 *     result as a boolean in the response body. Failing validation on
 *     uniqueness would leak the same information through an error
 *     code, but the explicit `available: false` is the friendlier
 *     contract for the mobile sign-up step.
 *   - `email:rfc,dns,spoof,strict` mirrors `UserStoreRequest::rules()`
 *     so the same input that's accepted here is accepted by the
 *     final register endpoint — no surprises for the user when they
 *     reach step 6 of the wizard.
 *   - `recaptcha_token` is consumed by the `recaptcha` middleware
 *     bound to this route in `routes/api.php`; we don't need to
 *     declare a validation rule for it.
 */
class CheckEmailAvailabilityRequest extends FormRequest
{
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
            'email' => ['required', 'email:rfc,dns,spoof,strict', 'max:255'],
        ];
    }
}
