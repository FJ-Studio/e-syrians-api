<?php

declare(strict_types=1);

namespace App\Enums;

enum ProfileChangeTypeEnum: string
{
    case BasicData = 'basic_data';
    case Address = 'address';
    case Regular = 'regular';
    // case Social = 'social';
    // case Avatar = 'avatar';
}
