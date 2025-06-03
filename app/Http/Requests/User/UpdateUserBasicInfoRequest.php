<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\EthnicityEnum;
use App\Enums\GenderEnum;
use App\Enums\HometownEnum;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserBasicInfoRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'surname' => ['required', 'string', 'max:255', 'min:2'],
            'birth_date' => ['required', 'date'],
            'gender' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, GenderEnum::cases()))],
            'ethnicity' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, EthnicityEnum::cases()))],
            'hometown' => ['required', 'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases()))],
            'national_id' => ['nullable', 'string', 'max:20', 'min:5'],
        ];
    }
}
