<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserCensusDataRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'middle_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'shelter' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string'],
            'education_level' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\EducationLevelEnum::cases()))],
            'skills' => ['nullable', 'string'],
            'source_of_income' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\IncomeSourceEnum::cases()))],
            'estimated_monthly_income' => ['nullable', 'numeric'],
            'number_of_dependents' => ['nullable', 'integer'],
            'health_status' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\HealthStatusEnum::cases()))],
            'health_insurance' => ['nullable', 'boolean'],
            'easy_access_to_healthcare_services' => ['nullable', 'boolean'],
            'religious_affiliation' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\ReligiousAffiliationEnum::cases()))],
            'marital_status' => ['nullable', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\MaritalStatusEnum::cases()))],
            'communication' => ['nullable', 'string'],
            'more_info' => ['nullable', 'string'],
            'other_nationalities' => ['nullable', 'array'],
            'other_nationalities.*' => ['string', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\CountryEnum::cases()))],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'in:'.implode(',', array_map(fn ($case) => $case->value, \App\Enums\LanguageEnum::cases()))],
        ];
    }
}
