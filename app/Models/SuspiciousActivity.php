<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SuspiciousActivity extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'severity',
        'score',
        'rules_triggered',
        'evidence',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'detected_at',
    ];

    protected $casts = [
        'rules_triggered' => 'array',
        'evidence' => 'array',
        'score' => 'integer',
        'reviewed_at' => 'datetime',
        'detected_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
