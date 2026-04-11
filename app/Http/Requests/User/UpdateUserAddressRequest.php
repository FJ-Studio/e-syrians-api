<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\CountryEnum;
use App\Enums\HometownEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateUserAddressRequest extends FormRequest
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
            'country' => [
                'required',
                'in:'.implode(',', array_map(fn ($case) => $case->value, CountryEnum::cases())),
            ],
            'city_inside_syria' => [
                'nullable', // still allows null when not required
                'required_if:country,SY',
                'in:'.implode(',', array_map(fn ($case) => $case->value, HometownEnum::cases())),
            ],
        ];
    }
}
