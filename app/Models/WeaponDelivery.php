<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WeaponDelivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'citizen_id',
        'weapon_delivery_point_id',
        'added_by',
        'updates',
        'status',
        'deliveries',
    ];

    protected $casts = [
        'updates' => 'array',
        'deliveries' => 'array',
    ];

    public function citizen()
    {
        return $this->belongsTo(User::class, 'citizen_id', 'id');
    }

    public function weaponDeliveryPoint()
    {
        return $this->belongsTo(WeaponDeliveryPoint::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by', 'id');
    }
}
