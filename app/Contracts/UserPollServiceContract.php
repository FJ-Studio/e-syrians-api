<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface UserPollServiceContract
{
    public function getUserPolls(User $user, int $perPage = 25): LengthAwarePaginator;

    public function getUserReactions(User $user, int $perPage = 25): LengthAwarePaginator;

    /**
     * @return array{data: Collection, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function getUserVotes(User $user, int $page = 1, int $perPage = 25): array;
}
