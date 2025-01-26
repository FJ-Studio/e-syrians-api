<?php

declare(strict_types=1);

namespace App\Enums;

enum WeaponDeliveryStatusEnum: string
{
    case New = 'new';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
