<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $isOwner = $request->user() && $request->user()->id === $this->id;
        $request_for = $request->get('request_for', false);

        return [
            // 'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'surname' => $this->surname,
            'avatar' => $this->avatar,
            'created_at' => $this->created_at,
            'birth_date' => $this->birth_date,
            'hometown' => $this->hometown,
            'country' => $this->country,
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
            'avatar' => $this->avatar ? Storage::disk('s3')->temporaryUrl($this->avatar, now()->addMinutes(config('e-syrians.files.avatar.ttl', 60))) : null,
            'country' => $this->country,
            'gender' => $this->gender,
            'ethnicity' => $this->ethnicity,
            'verified_at' => $this->verified_at,

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
                'other_nationalities' => $this->other_nationalities,
                'roles' => $this->getRoleNames(),
                'permissions' => $this->getAllPermissions()->pluck('name'),
                'basic_info_updates' => (int)(config('e-syrians.verification.basic_data_updates_limit') - $this->getTotalUpdatesCount(ProfileChangeTypeEnum::BasicData->value)),
            ]),

            'handovers' => WeaponDeliveryResource::collection($this->whenLoaded('handovers')),
            'received_items' => WeaponDeliveryResource::collection($this->whenLoaded('received_items')),
        ];
    }
}
