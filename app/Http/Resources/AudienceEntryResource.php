<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\AudienceEntry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Privacy-safe representation of a single audience entry.
 *
 * `resolved_user_id` is INTENTIONALLY reduced to a boolean
 * `resolved` — the audience creator sees "this identifier
 * matches a registered user (yes / no)" but does NOT see the
 * matching user's name, uuid, or any other profile detail. That
 * one-way disclosure is the privacy invariant the whole feature
 * hangs on: users can list potential contacts without leaking
 * who has actually registered.
 *
 * If a future admin surface (Filament) needs the resolved user
 * details, it should use the model relations directly rather
 * than this resource.
 *
 * @property AudienceEntry $resource
 */
class AudienceEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'identifier' => $this->resource->identifier,
            'identifier_type' => $this->resource->identifier_type->value,
            'resolved' => $this->resource->resolved_user_id !== null,
            'created_at' => $this->resource->created_at->toIso8601String(),
        ];
    }
}
