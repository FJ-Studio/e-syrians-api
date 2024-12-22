<?php

namespace App\Enums;

enum WeaponCategory: string
{
    case Pistol = 'pistol';
    case Shotgun = 'shotgun';
    case Sniper = 'sniper';
    case MachineGun = 'machine_gun';
    case Grenade = 'grenade';
    case Explosive = 'explosive';
    case Other = 'other';

    public static function getValues(): array
    {
        return [
            self::Pistol,
            self::Shotgun,
            self::Sniper,
            self::MachineGun,
            self::Grenade,
            self::Explosive,
            self::Other,
        ];
    }
}
