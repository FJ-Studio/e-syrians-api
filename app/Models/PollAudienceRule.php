<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PollAudienceRule extends Model
{
    protected $fillable = [
        'poll_id',
        'criterion',
        'value',
    ];

    public function poll()
    {
        return $this->belongsTo(Poll::class);
    }
}
