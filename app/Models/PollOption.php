<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PollOption extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'poll_id',
        'option_text',
    ];

    /**
     * Get the poll that the poll option belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Get the votes for the poll option.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Get the latest 3 voters for the poll option.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function latestVoters()
    {
        return $this->hasMany(PollVote::class)->latest()->take(3)->with('user');
    }

    /**
     * Get the user that created the poll option.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
