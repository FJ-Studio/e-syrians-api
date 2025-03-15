<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProviderEnum: string
{
    case GOOGLE = 'google';
    case APPLE = 'apple';
}
