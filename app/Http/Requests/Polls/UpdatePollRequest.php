<?php

declare(strict_types=1);

namespace App\Http\Requests\Polls;

use App\Models\Poll;
use App\Enums\GenderEnum;
use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
use App\Enums\EthnicityEnum;
use App\Services\StrService;
use App\Enums\RevealResultsEnum;
use App\Enums\ReligiousAffiliationEnum;
use App\Contracts\AudienceServiceContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * UpdatePollRequest — validates PATCH /polls/{poll}.
 *
 * Edit window closes the moment the poll receives its first vote.
 * That gate lives at the model layer (Poll::isEditable()) and is
 * re-checked here in authorize() so we surface the right HTTP
 * status (403 with `poll_has_votes_cannot_edit`) before any
 * validation runs. The fields mirror StorePollRequest exactly,
 * but everything is `sometimes` — clients can PATCH any subset.
 *
 * Race condition note: a vote may land between when the client
 * opens the edit form and when the PATCH arrives. That's an
 * accepted v1 trade-off; the 403 here is the safety net, and the
 * client surfaces it as a humane toast.
 */
class UpdatePollRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Normalize numerals only when the key was actually sent.
        // Force-merging with a default of '' would defeat `sometimes`
        // validation — the rule would see the empty string as
        // "present" and then the `required` part of the rule would
        // 422 every partial PATCH that doesn't touch these fields.
        $merge = [];
        if ($this->has('duration')) {
            $merge['duration'] = StrService::mapArabicNumbers((string) $this->input('duration'));
        }
        if ($this->has('max_selections')) {
            $merge['max_selections'] = StrService::mapArabicNumbers((string) $this->input('max_selections'));
        }
        if ($merge !== []) {
            $this->merge($merge);
        }

    }

    public function authorize(): bool
    {
        $poll = $this->route('poll');
        $user = $this->user();

        if (! $user?->hasRole('citizen') || ! $poll) {
            return false;
        }

        // Ownership — only the creator can edit.
        if ($poll->created_by !== $user->id) {
            return false;
        }

        // Vote-lock — once any vote lands, the poll is immutable.
        // We check via the votes relationship rather than a cached
        // count column so a freshly-cast vote (in flight when the
        // client opened the form) is caught here at save time.
        if ($poll->votes()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
            'duration' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'max_selections' => ['sometimes', 'required', 'integer', 'min:1', 'max:10'],
            'audience_can_add_options' => ['sometimes', 'required', 'boolean'],
            'reveal_results' => ['sometimes', 'required', 'in:'.implode(',', array_map(fn ($case) => $case->value, RevealResultsEnum::cases()))],
            'voters_are_visible' => ['sometimes', 'required', 'boolean'],
            'audience_only' => ['sometimes', 'nullable', 'boolean'],
            // Options — when present, replaces the existing list
            // wholesale. The service deletes-then-inserts in a
            // transaction; safe because the zero-vote gate above
            // guarantees no PollVote rows reference an option_id.
            'options' => ['sometimes', 'required', 'array', 'min:2', 'max:100'],
            'options.*' => ['required', 'string', 'max:255'],
            // Audience rules — same wholesale-replace semantic.
            'gender' => ['sometimes', 'nullable', 'array'],
            'gender.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, GenderEnum::cases()))],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:13', 'max:119'],
            'max_age' => ['sometimes', 'nullable', 'integer', 'min:14', 'max:120'],
            'country' => ['sometimes', 'nullable', 'array'],
            'country.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, CountryEnum::cases()))],
            'religious_affiliation' => ['sometimes', 'nullable', 'array'],
            'religious_affiliation.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, ReligiousAffiliationEnum::cases()))],
            'hometown' => ['sometimes', 'nullable', 'array'],
            'hometown.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            'ethnicity' => ['sometimes', 'nullable', 'array'],
            'ethnicity.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, EthnicityEnum::cases()))],
            'province' => ['sometimes', 'nullable', 'array'],
            'province.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            // Reusable audience list. `nullable` allows clearing.
            // Accepted as UUID (matches the audience API surface);
            // PollService resolves it to the internal id.
            'audience_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            // Legacy pasted voter lists were replaced by reusable
            // audiences. Reject the old field explicitly instead of
            // silently ignoring it for non-web clients.
            'allowed_voters' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allowed_voters.missing' => 'allowed_voters_no_longer_supported',
        ];
    }

    /**
     * Cross-field validation for audience wiring.
     *
     * Mirrors StorePollRequest: `audience_uuid` is mutually
     * exclusive with the demographic criteria block, and must
     * reference an audience the caller owns. On PATCH we only apply
     * these checks when the client actually sent `audience_uuid` — a
     * bare `{ "question": "…" }` PATCH must not fail just because the
     * poll already has an audience attached.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $demographicKeys = [
                'gender', 'min_age', 'max_age', 'country',
                'religious_affiliation', 'hometown', 'ethnicity', 'province',
            ];
            $sentInlineCriteria = function () use ($demographicKeys): bool {
                foreach ($demographicKeys as $key) {
                    if (! $this->has($key)) {
                        continue;
                    }
                    $value = $this->input($key);
                    $hasValue = is_array($value) ? count($value) > 0 : ($value !== null && $value !== '');
                    if ($hasValue) {
                        return true;
                    }
                }

                return false;
            };

            // Case 1 — poll already backed by a saved audience, and
            // the client PATCH ships inline criteria WITHOUT also
            // detaching the audience. Under the old code the request
            // succeeded but the inline changes were silently dropped
            // because PollService still gates on `audience_id`.
            // Reject the request so the client either sends
            // `"audience_uuid": null` (explicit detach + inline) or
            // omits the inline fields.
            /** @var Poll|null $existingPoll */
            $existingPoll = $this->route('poll');
            $pollHasAudience = $existingPoll !== null && $existingPoll->audience_id !== null;
            $sendingDetach = $this->has('audience_uuid') && $this->input('audience_uuid') === null;

            if ($pollHasAudience && ! $sendingDetach && $sentInlineCriteria()) {
                $v->errors()->add(
                    'audience_uuid',
                    'poll_uses_saved_audience_detach_before_setting_inline_criteria',
                );

                return;
            }

            // Case 2 — the client is attaching or replacing the
            // saved audience. Enforce mutual exclusion with inline
            // criteria (same rules as StorePollRequest) and verify
            // ownership.
            if (! $this->has('audience_uuid')) {
                return;
            }

            $audienceUuid = $this->input('audience_uuid');
            if ($audienceUuid === null) {
                // Explicit detach — no conflict / ownership checks
                // needed. Inline criteria alongside are allowed
                // because PollService will rebuild rules from
                // scratch once `audience_id` is cleared.
                return;
            }

            foreach ($demographicKeys as $key) {
                if (! $this->has($key)) {
                    continue;
                }
                $value = $this->input($key);
                $hasValue = is_array($value) ? count($value) > 0 : ($value !== null && $value !== '');
                if ($hasValue) {
                    $v->errors()->add('audience_uuid', 'audience_and_demographic_criteria_are_mutually_exclusive');

                    return;
                }
            }

            /** @var AudienceServiceContract $audiences */
            $audiences = resolve(AudienceServiceContract::class);
            $resolved = $audiences->resolveOwnedUuidToId((string) $audienceUuid, (int) $this->user()->id);
            if ($resolved === null) {
                $v->errors()->add('audience_uuid', 'audience_not_found_or_not_owned');
            }
        });
    }
}
