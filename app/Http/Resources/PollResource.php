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
        $user = auth('sanctum')->user();
        $userId = $user?->id;

        return [
            'id' => $this->id,
            'question' => $this->question,
            'start_date' => $this->start_date->toISOString(),
            'end_date' => $this->end_date->toISOString(),
            'max_selections' => $this->max_selections,
            'audience_can_add_options' => $this->audience_can_add_options,
            'audience' => $this->audience,
            'deletion_reason' => $this->deletion_reason,
            'created_at' => $this->created_at->toISOString(),
            'deleted_at' => $this->when($this->deleted_at, fn() => $this->deleted_at->toISOString()),
            'reveal_results' => $this->reveal_results,
            'ups_count' => $this->ups_count,
            'downs_count' => $this->downs_count,
            'voters_are_visible' => $this->voters_are_visible,

            'user' => $this->relationLoaded('user')
                ? new UserResource($this->user)
                : null,

            'options' => $this->relationLoaded('options')
                ? PollOptionResource::collection($this->options->map(function ($option) {
                    $totalVotes = $this->total_votes ?? 0; // Get total votes from the poll
                    $optionVotes = $option->votes_count ?? 0; // Get votes for this option
                    $percentage = $totalVotes > 0 ? round(($optionVotes / $totalVotes) * 100, 2) : 0; // Calculate %

                    return array_merge($option->toArray(), [
                        'percentage' => $this->reveal_results ? $percentage : null // Show % only if results are revealed
                    ]);
                }))
                : [],

            'votes' => $this->relationLoaded('votes')
                ? PollVoteResource::collection($this->votes)
                : [],

            'reactions' => $this->relationLoaded('reactions')
                ? PollReactionResource::collection($this->reactions)
                : [],

            'has_voted' => $userId ? ($this->has_voted ?? false) : false,
            'has_reacted' => $userId ? ($this->has_reacted ?? false) : false,
            'has_upvoted' => $userId ? ($this->has_upvoted ?? false) : false,
            'has_downvoted' => $userId ? ($this->has_downvoted ?? false) : false,

            'selected_options' => $this->relationLoaded('votes')
                ? PollOptionResource::collection($this->votes->where('user_id', $userId)->pluck('option'))
                : [],
        ];
    }
}
