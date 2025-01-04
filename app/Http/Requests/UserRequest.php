<?php
namespace App\Http\Requests;
use App\Rules\EmailRule;
use App\Enums\GenderEnum;
use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
use App\Enums\IncomeSourceEnum;
use App\Enums\HealthStatusEnum;
use Illuminate\Validation\Rule;
use App\Enums\EducationLevelEnum;
use App\Enums\ReligiousAffiliation;
use App\Rules\NationalSyrianNumberRule;
use Illuminate\Foundation\Http\FormRequest;
class UserRequest extends FormRequest
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

        $rules = [
            'name' => 'required|string|min:2|max:255' ,
            'middle_name' => 'nullable|string|min:2|max:255' ,
            'last_name' => 'required|string|min:3|max:255' ,
            'national_id' =>['required_without:email','min:4','max:14','string',new  NationalSyrianNumberRule()] ,
            'gender' => ['required' , Rule::in(array_map(fn ($case) => $case->value , GenderEnum::cases()))] ,
            'birth_date' => 'required|date|date_format:Y-m-d|before:today' ,
            'hometown' => ['required' , Rule::in(array_map(fn ($case) => $case->value , HometownEnum::cases()))] ,
            'address' => 'nullable|string|max:255' ,
            'phone' => 'nullable|string|max:20' ,
            'email' => ['required_without:national_id' , 'email' , 'max:255' , new EmailRule()] ,
            'password' => 'nullable|string|min:6|max:255' ,
            'photo' => 'nullable|string|max:255' ,
            'country' => ['required' , Rule::in(array_map(fn ($case) => $case->value , CountryEnum::cases()))] ,
            'city' => 'required|string|max:255' ,
            'shelter' => 'nullable|boolean' ,
            'education_level' => ['nullable' , Rule::in(array_map(fn ($case) => $case->value , EducationLevelEnum::cases()))] ,
            'skills' => 'nullable|string' ,
            'current_source_income' => ['nullable' , Rule::in(array_map(fn ($case) => $case->value , IncomeSourceEnum::cases()))] ,
            'estimated_monthly_income' => 'nullable|numeric|min:0' ,
            'number_of_dependents' => 'nullable|integer|min:0' ,
            'health_status' => ['nullable' , Rule::in(array_map(fn ($case) => $case->value , HealthStatusEnum::cases()))] ,
            'health_insurance' => 'nullable|boolean' ,
            'easy_access_to_healthcare_services' => 'nullable|boolean' ,
            'communication' => 'nullable|string' ,
            'more_info' => 'nullable|string' ,
            'religious_affiliation' => ['nullable' , Rule::in(array_map(fn ($case) => $case->value , ReligiousAffiliation::cases()))] ,
            'other_nationalities' => 'nullable|string' ,
            'languages' => 'nullable|string' ,
            'verified_at' => 'nullable|date' ,
            'marked_as_fake_at' => 'nullable|date' ,
            'marked_as_fake_reason' => 'nullable|string' ,
        ];
        return $rules;
    }


}
