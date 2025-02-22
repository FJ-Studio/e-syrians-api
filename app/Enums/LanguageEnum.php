<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageEnum: string
{
    case Arabic = 'arabic';
    case Kurdish = 'kurdish';
    case English = 'english';
    case French = 'french';
    case Spanish = 'spanish';
    case German = 'german';
    case Italian = 'italian';
    case Dutch = 'dutch';
    case Portuguese = 'portuguese';
    case Russian = 'russian';
    case Chinese = 'chinese';
    case Japanese = 'japanese';
    case Korean = 'korean';
    case Turkish = 'turkish';
    case Persian = 'persian';
    case Urdu = 'urdu';
    case Hindi = 'hindi';
    case Other = 'other';
}
