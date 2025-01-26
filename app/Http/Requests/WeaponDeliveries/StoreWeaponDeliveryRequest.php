<?php

declare(strict_types=1);

namespace App\Http\Requests\WeaponDeliveries;

use App\Enums\WeaponCategory;
use App\Services\StrService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWeaponDeliveryRequest extends FormRequest
{
    private function convertArabicToWesternDigits($input)
    {
        if (!is_string($input)) {
            return $input;
        }
        return StrService::mapArabicNumbers($input);
    }
    protected function prepareForValidation(): void
    {
        $this->merge([
            'national_id' => $this->convertArabicToWesternDigits($this->input('national_id')),
            'phone' => $this->convertArabicToWesternDigits($this->input('phone')),
        ]);
    }
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo('weapon_delivery:store');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weapons' => [
                'required',
                'array',
            ],
            'weapons.*' => [
                'string',
                Rule::in(array_map(fn($case) => $case->value, WeaponCategory::cases())), // Validate each array element
            ],
            'notes' => [
                'required',
                'string',
            ],
            'weapon_delivery_point_id' => [
                'sometimes',
                'integer',
                'exists:weapon_delivery_points,id',
            ],
            'national_id' => [
                'required',
                'string',
                'min:10',
                'max:12',
            ],
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'surname' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'address' => [
                'required',
                'string',
                'min:5',
                'max:255',
            ],
            'phone' => [
                'required',
                'string',
                'min:10',
                'max:15',
            ],
        ];
    }
}
