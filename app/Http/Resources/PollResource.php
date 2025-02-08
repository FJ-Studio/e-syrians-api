<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'max_selections' => $this->max_selections,
            'audience_can_add_options' => $this->audience_can_add_options,
            'audience' => $this->audience,
            // 'created_by' => $this->created_by,
            'deletion_reason' => $this->deletion_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'user' => new UserResource($this->whenLoaded('user')),
            'options' => PollOptionResource::collection($this->whenLoaded('options')),
            'votes' => PollVoteResource::collection($this->whenLoaded('votes')),
            'ups' => 0,
            'downs' => 0,
        ];
    }
}
