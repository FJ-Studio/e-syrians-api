<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Crypt;
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
        return [
            'uuid'=>$this->uuid,
            'first_name' => Crypt::decrypt($this->name),
//            'created_at' => $this->created_at,
//            'permissions' => $this->getAllPermissions()->pluck('name'),
//            'handovers' => WeaponDeliveryResource::collection($this->whenLoaded('handovers')),
//            'received_items' => WeaponDeliveryResource::collection($this->whenLoaded('received_items')),
        ];
    }
}
