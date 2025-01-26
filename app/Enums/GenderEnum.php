<?php

declare(strict_types=1);

namespace App\Enums;

enum GenderEnum: string
{
    case Female = 'f';
    case Male = 'm';
    case Other = 'o';
}
