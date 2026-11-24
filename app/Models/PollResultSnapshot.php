<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $poll_id
 * @property int $schema_version
 * @property string $question
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $max_selections
 * @property int $total_votes
 * @property int $unique_voters_count
 * @property array<int, array{id: int, poll_id: int, option_text: string, created_at: string|null, votes_count: int, percentage: float|int}> $options
 * @property Carbon $closed_at
 * @property Carbon $captured_at
 */
class PollResultSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'max_selections' => 'integer',
            'total_votes' => 'integer',
            'unique_voters_count' => 'integer',
            'options' => 'array',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'closed_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Final poll results are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Final poll results are immutable.');
        });
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class)->withTrashed()->withoutGlobalScope('public_polls');
    }
}
