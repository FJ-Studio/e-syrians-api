<?php

declare(strict_types=1);

namespace App\Enums;

enum ViolationCategoryEnum: string
{
    case Violence = 'violence';
    case HateSpeech = 'hate-speech';
    case ChildAbuse = 'child-abuse';
    case SexualAbuse = 'sexual-abuse';
    case Corruption = 'corruption';
    case Other = 'other';
}
