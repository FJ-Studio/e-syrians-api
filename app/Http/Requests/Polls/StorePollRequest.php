<?php

declare(strict_types=1);

namespace App\Http\Requests\Polls;

use App\Enums\CountryEnum;
use App\Enums\EthnicityEnum;
use App\Enums\GenderEnum;
use App\Enums\HometownEnum;
use App\Enums\ReligiousAffiliationEnum;
use App\Enums\RevealResultsEnum;
use App\Services\StrService;
use Illuminate\Foundation\Http\FormRequest;

class StorePollRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'duration' => StrService::mapArabicNumbers((string) $this->input('duration', '')),
            'max_selections' => StrService::mapArabicNumbers((string) $this->input('max_selections', '')),
        ]);

        // Normalize allowed_voters: trim whitespace, convert Arabic numbers to Latin
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            // city inside syria (only relevant when country is SY)
            'city_inside_syria' => ['nullable', 'array'],
            'city_inside_syria.*' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            // specific voters (national IDs or emails, one per entry)
            'allowed_voters' => ['nullable', 'array', 'max:500'],
            'allowed_voters.*' => ['required', 'string', 'max:255', 'regex:/^([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}|[0-9]{5,20})$/'],
        ];
    }
}
