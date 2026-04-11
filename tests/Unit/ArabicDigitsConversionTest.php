<?php

use App\Services\StrService;

test('converts Arabic digits to English digits', function (): void {
    $arabicDigits = '٠١٢٣٤٥٦٧٨٩';
    $englishDigits = '0123456789';
    $convertedDigits = StrService::mapArabicNumbers($arabicDigits);
    expect($convertedDigits)->toBe($englishDigits);
});
