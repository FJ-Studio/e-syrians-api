<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\CountryEnum;
use App\Enums\EducationLevelEnum;
use App\Enums\EthnicityEnum;
use App\Enums\GenderEnum;
use App\Enums\HealthStatusEnum;
use App\Enums\HometownEnum;
use App\Enums\IncomeSourceEnum;
use App\Enums\LanguageEnum;
use App\Enums\MaritalStatusEnum;
use App\Enums\ReligiousAffiliationEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Personal data
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'surname' => ['required', 'string', 'max:255', 'min:2'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:15', 'min:9'],
            'gender' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, GenderEnum::cases()))],
            'birth_date' => ['required', 'date'],
            'hometown' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            // E-data
            'email' => ['required', 'email:rfc,dns,spoof,strict', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'google_id' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            // Location
            'country' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, CountryEnum::cases()))],
            'city' => ['nullable', 'string', 'max:255'],
            'shelter' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string'],
            'city_inside_syria' => [
                'nullable', // still allows null when not required
                'required_if:country,SY',
                'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases())),
            ],
            // Education and work
            'education_level' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, EducationLevelEnum::cases()))],
            'skills' => ['nullable', 'string'],
            'source_of_income' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, IncomeSourceEnum::cases()))],
            'estimated_monthly_income' => ['nullable', 'numeric'],
            'number_of_dependents' => ['nullable', 'integer'],
            // Health
            'health_status' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, HealthStatusEnum::cases()))],
            'health_insurance' => ['nullable', 'boolean'],
            'easy_access_to_healthcare_services' => ['nullable', 'boolean'],
            // Other
            'ethnicity' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, EthnicityEnum::cases()))],
            'religious_affiliation' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, ReligiousAffiliationEnum::cases()))],
            'marital_status' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, MaritalStatusEnum::cases()))],
            'communication' => ['nullable', 'string'],
            'more_info' => ['nullable', 'string'],
            'other_nationalities' => ['nullable', 'array'],
            'other_nationalities.*' => ['string', 'in:'.implode(',', array_map(fn ($case) => $case->value, CountryEnum::cases()))],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'in:'.implode(',', array_map(fn ($case) => $case->value, LanguageEnum::cases()))],
            'marked_as_fake_at' => ['nullable', 'date'],
            'marked_as_fake_reason' => ['nullable', 'string'],
            'record_place' => ['nullable', 'string'],
            'record_id' => ['nullable', 'string'],
        ];
    }
}
