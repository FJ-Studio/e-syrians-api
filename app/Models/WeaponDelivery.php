<?php

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
}
