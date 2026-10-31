<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Audience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Audience $resource
 */
class AudienceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Entry counts come from two places depending on the caller:
        //   - List endpoint (`AudienceService::list`) eagerly
        //     `withCount` aliases them onto the model, so we read
        //     the aliased attributes directly.
        //   - Detail endpoint eager-loads the `entries` relation
        //     itself, so we fall back to counting the loaded
        //     collection when the withCount aliases aren't there.
        // Neither path issues an N+1 query.
        $entriesTotal = $this->resource->entries_total_count
            ?? ($this->resource->relationLoaded('entries')
                ? $this->resource->entries->count()
                : 0);

        $entriesResolved = $this->resource->entries_resolved_count
            ?? ($this->resource->relationLoaded('entries')
                ? $this->resource->entries->whereNotNull('resolved_user_id')->count()
                : 0);

        return [
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'entries_total_count' => (int) $entriesTotal,
            'entries_resolved_count' => (int) $entriesResolved,
            // Detail-only — heavy, so gate behind whenLoaded.
            'entries' => AudienceEntryResource::collection(
                $this->whenLoaded('entries')
            ),
            // created_at / updated_at are always populated by Eloquent
            // on a persisted row — nullsafe would be dead code and
            // trips PHPStan (`nullsafe.neverNull`). deleted_at IS
            // legitimately nullable because of SoftDeletes.
            'created_at' => $this->resource->created_at->toIso8601String(),
            'updated_at' => $this->resource->updated_at->toIso8601String(),
            'deleted_at' => $this->resource->deleted_at?->toIso8601String(),
        ];
    }
}
