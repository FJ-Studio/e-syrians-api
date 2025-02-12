<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileUpdate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'change_type',
        'meta_data',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
