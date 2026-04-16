<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\FeatureRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureRequest */
class FeatureRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $authUser */
        $authUser = auth('sanctum')->user();
        $userId = $authUser?->id;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'timeline' => [
                'created_at' => $this->created_at->toISOString(),
                'coded_at' => $this->coded_at?->toISOString(),
                'tested_at' => $this->tested_at?->toISOString(),
                'deployed_at' => $this->deployed_at?->toISOString(),
            ],
            'ups_count' => $this->ups_count,
            'downs_count' => $this->downs_count,
            'score' => $this->score,
            'user' => $this->relationLoaded('user') && $this->user !== null
                ? new UserResource($this->user)
                : null,
            'has_upvoted' => $userId !== null ? (bool) ($this->has_upvoted ?? false) : false,
            'has_downvoted' => $userId !== null ? (bool) ($this->has_downvoted ?? false) : false,
            'created_at' => $this->created_at->toISOString(),
            'deleted_at' => $this->when($this->deleted_at !== null, fn () => $this->deleted_at?->toISOString()),
            'deletion_reason' => $this->deletion_reason,
        ];
    }
}
