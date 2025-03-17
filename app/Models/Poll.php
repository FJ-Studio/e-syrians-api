<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Poll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'question',
        'start_date',
        'end_date',
        'audience',
        'max_selections',
        'audience_can_add_options',
        'created_by',
        'deletion_reason',
        'deleted_at',
        'reveal_results',
        'voters_are_visible',
    ];

    protected $casts = [
        'audience' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'max_selections' => 'integer',
        'audience_can_add_options' => 'boolean',
        'voters_are_visible' => 'boolean',
    ];

    protected $appends = ['ups_count', 'downs_count'];

    public function getUpsCountAttribute()
    {
        return Cache::remember("poll_{$this->id}_ups_count", 60, function () {
            return $this->ups()->count();
        });
    }

    public function getDownsCountAttribute()
    {
        return Cache::remember("poll_{$this->id}_downs_count", 60, function () {
            return $this->downs()->count();
        });
    }

    /**
     * Get the user that created the poll.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the options for the poll.
     */
    public function options()
    {
        return $this->hasMany(PollOption::class);
    }

    /**
     * Get the votes for the poll.
     */
    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Get the voters for the poll.
     */
    public function voters()
    {
        return $this->hasManyThrough(User::class, PollVote::class, 'poll_id', 'id', 'id', 'user_id');
    }

    public function uniqueVotersCount()
    {
        return $this->votes()->distinct('user_id')->count('user_id');
    }

    /**
     * Get the reactions for the poll.
     */
    public function reactions()
    {
        return $this->hasMany(PollReaction::class);
    }

    /**
     * Get the upvote reactions for the poll.
     */
    public function ups()
    {
        return $this->reactions()->where('reaction', 'up');
    }

    /**
     * Get the downvote reactions for the poll.
     */
    public function downs()
    {
        return $this->reactions()->where('reaction', 'down');
    }
}
