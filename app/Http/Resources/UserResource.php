<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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

            $this->mergeWhen($isOwner, [
                'national_id' => $this->national_id,
                'middle_name' => $this->middle_name,
                'email' => $this->email,
                'roles' => $this->getRoleNames()->pluck('name'),
                'permissions' => $this->getAllPermissions()->pluck('name'),
            ]),

            'handovers' => WeaponDeliveryResource::collection($this->whenLoaded('handovers')),
            'received_items' => WeaponDeliveryResource::collection($this->whenLoaded('received_items')),
        ];
    }
}
