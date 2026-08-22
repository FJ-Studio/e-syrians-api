<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Contracts\PollServiceContract;
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
        $user = auth('sanctum')->check() ? auth('sanctum')->user() : null;
        $userId = $user?->id;
        $isCreator = $userId !== null && $userId === $this->created_by;

        $pollService = resolve(PollServiceContract::class);
        $revealResults = $pollService->shouldRevealResults($this->resource, $user);

        [$isInAudience, $audienceFailures] = $this->resource->audienceCheckFor($user);

        // Audience exposure rule:
        //   - Demographic audience (gender / age / country / …) is
        //     exposed to all viewers so clients can render the actual
        //     targeting rules.
        //   - Saved reusable audience lists expose only a small
        //     summary (uuid, name, counts), never entry identifiers.
        $audience = $this->resource->audience;

        // A saved-audience poll: `audience_id` is set → the accessor
        // returns `['audience' => { uuid, name, entries_total_count, … }]`.
        // Exposing the summary is safe (name + counts, no identifiers)
        // and required so the client can render "Gated by list: <name>"
        // instead of falling into the demographic branch with empty data.
        $isSavedAudience = isset($audience['audience']);

        return [
            'id' => $this->id,
            'question' => $this->question,
            'start_date' => $this->start_date->toISOString(),
            'end_date' => $this->end_date->toISOString(),
            'max_selections' => $this->max_selections,
            'audience_can_add_options' => $this->audience_can_add_options,
            'is_in_audience' => $isInAudience,
            'audience_failures' => $audienceFailures,
            'audience' => $audience,
            // True iff the poll is gated by a reusable saved audience
            // (`polls.audience_id` set). Client uses this to render a
            // "Gated by list" summary instead of the demographic
            // criteria sheet.
            'audience_is_saved_list' => $isSavedAudience,
            'deletion_reason' => $this->deletion_reason,
            'created_at' => $this->created_at->toISOString(),
            'deleted_at' => $this->when($this->deleted_at, fn () => $this->deleted_at->toISOString()),
            'reveal_results' => $this->reveal_results,
            'ups_count' => $this->ups_count,
            'downs_count' => $this->downs_count,
            'voters_are_visible' => $this->voters_are_visible,
            'audience_only' => $this->audience_only,
            // `is_private` is creator-only. The flag is normally
            // hidden by the public_polls global scope so non-owners
            // never see private polls at all — but the My Polls
            // listing skips that scope (so owners CAN see their
            // own private polls), and the management UI needs to
            // render a "Private" pill so the owner knows what
            // they're looking at.
            'is_private' => $this->when($isCreator, fn () => (bool) $this->resource->is_private),
            // Creator-only edit gate. Editing is allowed only
            // while the poll has zero votes — once anyone casts a
            // vote the poll becomes immutable. The mobile + web
            // kebab/action menus read this to decide whether to
            // show the "Edit poll" action.
            'is_editable' => $this->when(
                $isCreator,
                fn () => ($this->unique_voters_count ?? 0) === 0,
            ),
            'unique_voters_count' => $this->unique_voters_count ?? 0,
            /*
             * Backward-compat alias. Before UserPollController::myPolls
             * was wrapped in PollResource, it returned raw model rows
             * where `votes_count` (from withCount('votes')) was on
             * the wire — the web's account dashboard My Polls table
             * reads it as the "Participants count" column. When the
             * relationship was counted (owner-scoped endpoints that
             * still call withCount('votes')) we return that raw count
             * so the existing behaviour is preserved exactly; for
             * endpoints that don't (public /polls), we fall back to
             * unique_voters_count so the field always has a sensible
             * value. The web should migrate to unique_voters_count
             * for semantic clarity (raw `votes_count` double-counts
             * multi-select votes), but that's a separate change —
             * this alias unblocks the deploy.
             */
            'votes_count' => $this->votes_count ?? $this->unique_voters_count ?? 0,

            'user' => $this->relationLoaded('user')
                ? new UserResource($this->user)
                : null,

            'options' => $this->relationLoaded('options')
                ? PollOptionResource::collection(
                    $this->options->map(function ($option) use ($revealResults) {
                        // Load voters preview when voters are visible
                        if ($this->voters_are_visible) {
                            $option->load(['latestVoters.user:id,uuid,name,surname,avatar']);
                        }

                        if (! $revealResults) {
                            $option->percentage = null;

                            return new PollOptionResource($option);
                        }
                        $totalVotes = $this->total_votes ?? 0;
                        $optionVotes = $option->votes_count ?? 0;
                        $percentage = $totalVotes > 0 ? round(($optionVotes / $totalVotes) * 100, 2) : 0;
                        $option->percentage = $percentage;

                        return new PollOptionResource($option);
                    })
                )
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
                ? $this->votes->where('user_id', $userId)->pluck('poll_option_id')
                : [],
        ];
    }
}
