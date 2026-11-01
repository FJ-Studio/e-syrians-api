<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['device_info' => 'encrypted', 'ip_address' => 'encrypted', 'new_device' => 'boolean'];
    }
}
