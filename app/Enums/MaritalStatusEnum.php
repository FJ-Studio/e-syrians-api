<?php

declare(strict_types=1);

namespace App\Enums;

enum MaritalStatusEnum: string
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case Engaged = 'engaged';
    case InARelationship = 'in-a-relationship';
    case Complicated = 'complicated';
    case Other = 'other';
}
