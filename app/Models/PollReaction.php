<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollReaction extends Model
{
    protected $fillable = [
        'poll_id',
        'user_id',
    ];
    /**
     * Get the poll that the reaction belongs to.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Get the user that made this reaction.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
