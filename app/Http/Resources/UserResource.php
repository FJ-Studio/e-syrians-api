<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Exception;
use Illuminate\Http\Request;
use App\Enums\ProfileChangeTypeEnum;
use App\Contracts\FileUploadServiceContract;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = ($request->user() && $request->user()->uuid === $this->uuid)
            || (isset($this->additional['isOwner']) && $this->additional['isOwner'] === true);

        $avatarUrl = null;
        if ($this->avatar) {
            try {
                $fileService = resolve(FileUploadServiceContract::class);
                $avatarUrl = $fileService->temporaryUrl(
                    $this->avatar,
                    (int) config('e-syrians.files.avatar.ttl', 60),
                );
            } catch (Exception $e) {
                $avatarUrl = null;
            }
        }

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'surname' => $this->surname,
            'avatar' => $avatarUrl,
            'created_at' => $this->created_at,
            'birth_date' => $this->birth_date,
            'hometown' => $this->hometown,
            'country' => $this->country,
            'gender' => $this->gender,
            'ethnicity' => $this->ethnicity,
            'verified_at' => $this->verified_at,

            // Social links
            'facebook_link' => $this->facebook_link,
            'twitter_link' => $this->twitter_link,
            'linkedin_link' => $this->linkedin_link,
            'instagram_link' => $this->instagram_link,
            'snapchat_link' => $this->snapchat_link,
            'tiktok_link' => $this->tiktok_link,
            'youtube_link' => $this->youtube_link,
            'pinterest_link' => $this->pinterest_link,
            'twitch_link' => $this->twitch_link,
            'website' => $this->website,
            'github_link' => $this->github_link,

            // Owner-only fields
            $this->mergeWhen($isOwner, [
                'record_id' => $this->record_id,
                'phone' => $this->phone,
                'national_id' => $this->national_id,
                'middle_name' => $this->middle_name,
                'email' => $this->email,
                'city' => $this->city,
                'address' => $this->address,
                'shelter' => $this->shelter,
                'education_level' => $this->education_level,
                'skills' => $this->skills,
                'marital_status' => $this->marital_status,
                'source_of_income' => $this->source_of_income,
                'estimated_monthly_income' => $this->estimated_monthly_income,
                'number_of_dependents' => $this->number_of_dependents,
                'health_status' => $this->health_status,
                'health_insurance' => $this->health_insurance,
                'easy_access_to_healthcare_services' => $this->easy_access_to_healthcare_services,
                'religious_affiliation' => $this->religious_affiliation,
                'other_nationalities' => $this->other_nationalities,
                'communication' => $this->communication,
                'more_info' => $this->more_info,
                'email_verified_at' => $this->email_verified_at,
                'phone_verified_at' => $this->phone_verified_at,
                'verification_reason' => $this->verification_reason,
                'marked_as_fake_at' => $this->marked_as_fake_at,
                'languages' => $this->languages,
                'roles' => $this->getRoleNames(),
                'permissions' => $this->getAllPermissions()->pluck('name'),
                'basic_info_updates' => (int) (config('e-syrians.verification.basic_info_updates_limit') - $this->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value)),
                'received_verification_email' => $this->received_verification_email,
                'account_verified_email' => $this->account_verified_email,
                'city_inside_syria' => $this->city_inside_syria,
                'language' => $this->language,
                'has_password' => ! is_null($this->resource->password),
            ]),

        ];
    }
}
