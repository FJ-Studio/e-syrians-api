<?php

declare(strict_types=1);

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of `POST /users/devices`.
 *
 * The route is `auth:sanctum` so the user MUST be authenticated; the
 * controller resolves the authed user and hands it to
 * `DeviceService::registerDevice` along with the validated payload.
 *
 * Field rationale:
 *   - `subscription_id` — OneSignal per-device id (modern term; older
 *     OneSignal docs call it "player id"). The mobile client reads
 *     it from the OneSignal SDK via
 *     `OneSignal.User.pushSubscription.getIdAsync()` and POSTs it
 *     unchanged. Bounded at 255 chars because that's the column
 *     length set by the migration.
 *   - `platform` — restricted to the two values the iOS / Android
 *     OneSignal SDKs report. The mobile client populates this with
 *     `Platform.OS` so a web subscription would be rejected (correct
 *     — we don't deliver push to web from this app).
 *   - `model` — optional, surfaced when listing the user's devices
 *     (e.g. "iPhone 15 Pro"). Trimmed in the SDK already; we just
 *     cap length to keep the column small.
 */
class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware is `auth:sanctum` — authentication is the
        // only gate. There's no per-row authorization here: a user
        // can always register their OWN device. Cross-user safety is
        // handled by `DeviceService::registerDevice` which assigns
        // the device to `$request->user()` directly.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subscription_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'model' => ['nullable', 'string', 'max:255'],
        ];
    }
}
