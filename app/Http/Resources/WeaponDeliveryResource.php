<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WeaponDeliveryResource extends JsonResource
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
            'id' => $this->id,
            'citizen_id' => $this->citizen_id,
            'weapon_delivery_point_id' => $this->weapon_delivery_point_id,
            'added_by' => $this->added_by,
            'updates' => $this->updates,
            'status' => $this->status,
            'deliveries' => $this->deliveries,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'citizen' => new UserResource($this->whenLoaded('citizen')),
            'added_by' => new UserResource($this->whenLoaded('added_by')),
            'weapon_delivery_point' => new WeaponDeliveryPointResource($this->whenLoaded('weapon_delivery_point')),
        ];
    }
}
