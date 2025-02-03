<?php

declare(strict_types=1);

namespace App\Enums;

enum EthnicityEnum: string
{
    case arab = "arab";
    case kurd = "kurd";
    case greek = 'greek';
    case assyrian = "assyrian";
    case armenian = "armenian";
    case turkmen = "turkmen";
    case chechen = "chechen";
    case circassian = "circassian";
    case other = "other";
}
