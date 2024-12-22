<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class SecurityPersonnel extends Model
{
    use SoftDeletes;
    use HasUuids;
    use HasTranslations;

    public $translatable = ['position', 'description'];

    protected $fillable = [
        'user_id',
        'position',
        'description',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
