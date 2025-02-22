<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PollReaction extends Model
{

    protected static function booted()
    {
        static::saved(function ($reaction) {
            Cache::forget("poll_{$reaction->poll_id}_ups_count");
            Cache::forget("poll_{$reaction->poll_id}_downs_count");
        });

        static::deleted(function ($reaction) {
            Cache::forget("poll_{$reaction->poll_id}_ups_count");
            Cache::forget("poll_{$reaction->poll_id}_downs_count");
        });
    }

    protected $fillable = [
        'poll_id',
        'user_id',
        'reaction',
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
