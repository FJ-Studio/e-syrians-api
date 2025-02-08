<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected $casts = [
        'audience' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'max_selections' => 'integer',
        'audience_can_add_options' => 'boolean',
    ];

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
     * Get the reactions for the poll.
     */
    public function reactions()
    {
        return $this->hasMany(PollReaction::class);
    }
    /** 
     * Get the reactions ups reactions for the poll.
     */
    public function ups()
    {
        return $this->reactions()->where('reaction', 'up');
    }

    /** 
     * Get the reactions downs reactions for the poll.
     */
    public function downs()
    {
        return $this->reactions()->where('reaction', 'down');
    }
}
