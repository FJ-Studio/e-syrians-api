<?php

use App\Services\StrService;

it('maps Arabic numerals to Latin', function (): void {
    expect(StrService::mapArabicNumbers('٠١٢٣٤٥٦٧٨٩'))->toBe('0123456789');
});

it('preserves Latin numbers', function (): void {
    expect(StrService::mapArabicNumbers('0123456789'))->toBe('0123456789');
});

it('maps mixed Arabic and Latin numbers', function (): void {
    expect(StrService::mapArabicNumbers('٠12٣4٥'))->toBe('012345');
});

it('preserves non-numeric characters', function (): void {
    expect(StrService::mapArabicNumbers('test@gmail.com'))->toBe('test@gmail.com');
});

it('maps Arabic numbers within an email-like string', function (): void {
    expect(StrService::mapArabicNumbers('user٣٢١@test.com'))->toBe('user321@test.com');
});

it('handles empty string', function (): void {
    expect(StrService::mapArabicNumbers(''))->toBe('');
});

it('hashes strings consistently', function (): void {
    $hash1 = StrService::hash('test');
    $hash2 = StrService::hash('test');

    expect($hash1)->toBe($hash2);
    expect(strlen($hash1))->toBe(64); // SHA-256 produces 64 hex characters
});

it('produces different hashes for different inputs', function (): void {
    expect(StrService::hash('test1'))->not->toBe(StrService::hash('test2'));
});
