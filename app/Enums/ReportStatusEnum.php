<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportStatusEnum: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }
}
