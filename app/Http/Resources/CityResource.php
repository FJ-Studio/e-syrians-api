<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class CityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid'=>$this->uuid,
            'name_ar' => $this->getTranslation('name' , 'ar') ,
            'name_en' => $this->getTranslation('name' , 'en') ,
            'children'=>$this->whenLoaded('children',function(){
                return  CityResource::collection($this->children);
            }),
        ];

    }
}
