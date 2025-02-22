<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserVerification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'verifier_id',
        'user_id',
        'cancelled_at',
        'cancelation_payload',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'cancelation_payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }
}
