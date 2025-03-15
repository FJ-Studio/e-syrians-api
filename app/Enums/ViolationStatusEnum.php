<?php

declare(strict_types=1);

namespace App\Enums;

enum ViolationStatusEnum: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Removed = 'removed';
}
