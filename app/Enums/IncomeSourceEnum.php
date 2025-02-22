<?php

declare(strict_types=1);

namespace App\Enums;

enum IncomeSourceEnum: string
{
    case StableJob = 'stable-job';
    case Freelance = 'freelance';
    case AidSupport = 'aid-support';
    case NoIncome = 'no-income';
    case Other = 'other';
}
