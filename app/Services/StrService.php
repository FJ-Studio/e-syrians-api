<?php

declare(strict_types=1);

namespace App\Services;

class StrService
{
    public static function hash(string $string): string
    {
        return hash('sha256', $string);
    }

    public static function mapArabicNumbers(string $string): string
    {
        $arabicNumbers = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($arabicNumbers, $englishNumbers, $string);
    }
}
