<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class WeaponDeliveryPoint extends Model
{
    use SoftDeletes;
    use HasTranslations;

    public $translatable = ['name', 'description'];

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'contact_person',
        'description',
        'photo',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
    ];

    public function deliveries()
    {
        return $this->hasMany(WeaponDelivery::class);
    }
}
