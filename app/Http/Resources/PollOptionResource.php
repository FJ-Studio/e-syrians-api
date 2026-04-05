<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Contracts\FileUploadServiceContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PollOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'poll_id' => $this->poll_id,
            'option_text' => $this->option_text,
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', function () {
                $fileService = app(FileUploadServiceContract::class);
                $avatarUrl = null;
                if ($this->user->avatar) {
                    try {
                        $avatarUrl = $fileService->temporaryUrl(
                            $this->user->avatar,
                            (int) config('e-syrians.files.avatar.ttl', 60),
                        );
                    } catch (\Exception $e) {
                        $avatarUrl = null;
                    }
                }

                return [
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->name,
                    'surname' => $this->user->surname,
                    'avatar' => $avatarUrl,
                ];
            }),
            'votes_count' => $this->votes_count ?? 0,
            'percentage' => $this->when(isset($this->percentage), $this->percentage),
        ];

        // Include voters_preview when the relationship is loaded
        if ($this->relationLoaded('latestVoters')) {
            $fileService = app(FileUploadServiceContract::class);
            $data['voters_preview'] = $this->latestVoters->map(function ($vote) use ($fileService) {
                $user = $vote->user;
                $avatarUrl = null;
                if ($user->avatar) {
                    try {
                        $avatarUrl = $fileService->temporaryUrl(
                            $user->avatar,
                            (int) config('e-syrians.files.avatar.ttl', 60),
                        );
                    } catch (\Exception $e) {
                        $avatarUrl = null;
                    }
                }

                return [
                    'id' => $user->uuid,
                    'name' => $user->name,
                    'surname' => $user->surname,
                    'avatar' => $avatarUrl,
                ];
            });
        }

        return $data;
    }
}
