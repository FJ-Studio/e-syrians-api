<?php

namespace App\Enums;

enum WeaponDeliveryStatus: string
{
    case New = 'new';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public static function getValues(): array
    {
        return [
            self::New,
            self::Completed,
            self::Rejected,
        ];
    }
}
