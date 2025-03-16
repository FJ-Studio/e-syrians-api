<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PollOptionResource extends JsonResource
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
            'poll_id' => $this->poll_id,
            'option_text' => $this->option_text,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->name,
                    'surname' => $this->user->surname,
                    'avatar' => $this->user->avatar ? Storage::disk('s3')->temporaryUrl($this->user->avatar, now()->addMinutes(config('e-syrians.files.avatar.ttl', 60))) : null,
                ];
            }),
            'votes_count' => $this->votes()->count(),
            'percentage' => $this->when(isset($this->percentage), $this->percentage), // Ensure percentage is included if set

        ];
    }
}
