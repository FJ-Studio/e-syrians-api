<?php

namespace App\Enums;

enum WeaponDeliveryStatus: string
{
    case New = 'new';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
