<?php

declare(strict_types=1);

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
}
