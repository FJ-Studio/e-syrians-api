<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialLinksRequest extends FormRequest
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
            'facebook_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'twitter_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'linkedin_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'github_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'instagram_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'snapchat_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'tiktok_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'youtube_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'pinterest_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'twitch_link' => ['nullable', 'string', 'max:255', 'active_url'],
            'website' => ['nullable', 'string', 'max:255', 'active_url'],
        ];
    }
}
