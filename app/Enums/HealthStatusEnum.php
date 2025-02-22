<?php

declare(strict_types=1);

namespace App\Enums;

enum HealthStatusEnum: string
{
    case Good = 'good';
    case ChronicIllness = 'chronic-illness';
    case DisabledSpecialNeeds = 'disabled-special-needs';
}
