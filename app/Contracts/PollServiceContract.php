<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Poll;
use App\Models\User;
use App\Exceptions\PollVotingException;
use App\Exceptions\PollReactionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PollServiceContract
{
    /**
     * Get paginated polls with user interaction data, filtering audience-only polls.
     *
     * @return array{polls: LengthAwarePaginator, audience_only_count: int}
     */
    public function getPaginatedPolls(int $year, int $month, ?int $userId): array;

    /**
     * Get a single poll with full details
     */
    public function getPollById(int $id, ?int $userId): Poll;

    /**
     * Create a new poll with options
     */
    public function createPoll(array $data, int $userId): Poll;

    /**
     * Toggle a poll's active/deleted status
     */
    public function toggleStatus(int $pollId): void;

    /**
     * Cast a vote on a poll
     *
     * @throws PollVotingException
     */
    public function vote(int $pollId, array $optionIds, int $userId): void;

    /**
     * React (up/down) to a poll
     *
     * @throws PollReactionException
     */
    public function react(int $pollId, string $reaction, int $userId): void;

    /**
     * Determine if results should be revealed to a user
     */
    public function shouldRevealResults(Poll $poll, ?User $user): bool;

    /**
     * Get paginated voters for a specific poll option
     */
    public function getOptionVoters(int $optionId, int $perPage = 20): LengthAwarePaginator;
}
