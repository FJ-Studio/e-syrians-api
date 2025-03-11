<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Violation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'user_id',
        'category',
        'target',
        'description',
        'date_of_violation',
        'location',
        'target_group',
        'attachments',
        'links',
        'status',
    ];

    protected $casts = [
        'attachments' => 'array',
        'links' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
