<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class UserBlock extends Model
{
    public $timestamps = false;

    protected $fillable = ['blocker_id', 'blocked_id'];

    /**
     * A block is private to its owner, but visibility is restricted in both
     * directions. Keep the exclusion in SQL so pagination stays correct.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function excludeFor(Builder $query, string $column, ?int $viewer): Builder
    {
        if ($viewer === null) {
            return $query;
        }

        return $query
            ->whereNotIn($column, self::query()->select('blocked_id')->where('blocker_id', $viewer))
            ->whereNotIn($column, self::query()->select('blocker_id')->where('blocked_id', $viewer));
    }

    public static function between(?int $viewer, int $other): bool
    {
        return $viewer !== null && $viewer !== $other
            && self::query()->whereIn('blocker_id', [$viewer, $other])
                ->whereIn('blocked_id', [$viewer, $other])
                ->exists();
    }
}
