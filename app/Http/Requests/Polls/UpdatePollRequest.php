<?php

declare(strict_types=1);

namespace App\Http\Requests\Polls;

use App\Enums\GenderEnum;
use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
use App\Enums\EthnicityEnum;
use App\Services\StrService;
use App\Enums\RevealResultsEnum;
use App\Enums\ReligiousAffiliationEnum;
use Illuminate\Foundation\Http\FormRequest;
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

        if ($this->has('allowed_voters') && is_array($this->input('allowed_voters'))) {
            $this->merge([
                'allowed_voters' => array_values(array_filter(
                    array_map(
                        fn ($v) => strtolower(trim(StrService::mapArabicNumbers((string) $v))),
                        $this->input('allowed_voters')
                    ),
                    fn ($v) => $v !== ''
                )),
            ]);
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
            'allowed_voters' => ['sometimes', 'nullable', 'array', 'max:500'],
            'allowed_voters.*' => ['required', 'string', 'max:255', 'regex:/^([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}|[0-9]{5,20})$/'],
        ];
    }
}
