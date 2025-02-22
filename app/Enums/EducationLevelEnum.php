<?php

declare(strict_types=1);

namespace App\Enums;

enum EducationLevelEnum: string
{
    case None = 'none';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case HighSchool = 'high-school';
    case UniversityDegree = 'university-degree';
    case Postgraduate = 'postgraduate';
}
