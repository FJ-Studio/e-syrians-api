<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Log extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
    ];
}
