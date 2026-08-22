<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Resources\Json\JsonResource;

class UserVerificationResource extends JsonResource
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
            'user_id' => $this->user_id,
            'verifier_id' => $this->verifier_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'cancelation_payload' => $this->cancelation_payload,
            'cancelled_at' => $this->cancelled_at,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'uuid' => $this->user->uuid,
                    'name' => $this->user->name,
                    'surname' => $this->user->surname,
                    'avatar' => $this->user->avatar ? Storage::disk('s3')->temporaryUrl($this->user->avatar, now()->addMinutes(config('e-syrians.files.avatar.ttl', 60))) : null,
                ];
            }),
            'verifier' => $this->whenLoaded('verifier', function () {
                return [
                    'uuid' => $this->verifier->uuid,
                    'name' => $this->verifier->name,
                    'surname' => $this->verifier->surname,
                    'avatar' => $this->verifier->avatar ? Storage::disk('s3')->temporaryUrl($this->verifier->avatar, now()->addMinutes(config('e-syrians.files.avatar.ttl', 60))) : null,
                ];
            }),
        ];
    }
}
