<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\MassPrunable;

class ProfileUpdate extends Model
{
    use MassPrunable;
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'change_type',
        'meta_data',
        'changes',
        'ip_address',
        'user_agent',
        'request_source',
        'session_id',
        'blocked',
        'block_reason',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'changes' => 'array',
        'blocked' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Prune local records older than 30 days.
     * BigQuery holds the permanent copy.
     */
    public function prunable()
    {
        return static::where('created_at', '<=', now()->subDays(30));
    }
}
