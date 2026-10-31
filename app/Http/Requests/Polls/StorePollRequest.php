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
use App\Contracts\AudienceServiceContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\Validation\ValidationRule;

class StorePollRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'duration' => StrService::mapArabicNumbers((string) $this->input('duration', '')),
            'max_selections' => StrService::mapArabicNumbers((string) $this->input('max_selections', '')),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('citizen');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'duration' => ['required', 'integer', 'min:1', 'max:365'],
            'max_selections' => ['required', 'integer', 'min:1', 'max:10'],
            'audience_can_add_options' => ['required', 'boolean'],
            'reveal_results' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, RevealResultsEnum::cases()))],
            'voters_are_visible' => ['required', 'boolean'],
            'audience_only' => ['nullable', 'boolean'],
            // options
            'options' => ['required', 'array', 'min:2', 'max:100'],
            'options.*' => ['required', 'string', 'max:255'],
            // fields that are used to build the audience:
            'gender' => ['nullable', 'array'],
            'gender.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, GenderEnum::cases()))],
            // age group
            'min_age' => ['nullable', 'integer', 'min:13', 'max:119'],
            'max_age' => ['nullable', 'integer', 'min:14', 'max:120'],
            // location
            'country' => ['nullable', 'array'],
            'country.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, CountryEnum::cases()))],
            // religion
            'religious_affiliation' => ['nullable', 'array'],
            'religious_affiliation.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, ReligiousAffiliationEnum::cases()))],
            // hometown
            'hometown' => ['nullable', 'array'],
            'hometown.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            // ethnicity
            'ethnicity' => ['nullable', 'array'],
            'ethnicity.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, EthnicityEnum::cases()))],
            // province (only relevant when country is SY)
            'province' => ['nullable', 'array'],
            'province.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            // Reusable audience list — accepted as the UUID exposed
            // by the audience API (not the internal DB id). Must
            // belong to the current user. Mutually exclusive with
            // demographic criteria (see withValidator).
            'audience_uuid' => ['nullable', 'string', 'uuid'],
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
     * Enforce cross-field rules that don't fit the flat rules array:
     *
     *   1. `audience_uuid` is mutually exclusive with the
     *      demographic criteria block (gender, min_age, max_age,
     *      country, religious_affiliation, hometown, ethnicity,
     *      province). A poll gated by a saved audience list has
     *      no meaning for "must be under 30 AND on the list" —
     *      the two axes drive different UI + different vote-time
     *      checks. If we ever want intersection semantics we'll
     *      add it as an explicit combinator, not by accident.
     *
     *   2. `audience_uuid` must reference an audience the caller
     *      owns. Ownership check is done via the service so a
     *      soft-deleted audience is also rejected (matches what
     *      the vote path already enforces).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $audienceUuid = $this->input('audience_uuid');

            if ($audienceUuid === null) {
                return;
            }

            $demographicKeys = [
                'gender', 'min_age', 'max_age', 'country',
                'religious_affiliation', 'hometown', 'ethnicity', 'province',
            ];
            foreach ($demographicKeys as $key) {
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
