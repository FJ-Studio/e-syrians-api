<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAnnouncement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
