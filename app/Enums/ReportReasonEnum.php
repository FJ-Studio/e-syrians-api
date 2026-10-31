<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportReasonEnum: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case HateSpeech = 'hate_speech';
    case InappropriateContent = 'inappropriate_content';
    case Misinformation = 'misinformation';
    case Impersonation = 'impersonation';
    case Privacy = 'privacy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam or advertising',
            self::Harassment => 'Harassment or threats',
            self::HateSpeech => 'Hate speech',
            self::InappropriateContent => 'Inappropriate content',
            self::Misinformation => 'False or misleading information',
            self::Impersonation => 'Impersonation',
            self::Privacy => 'Personal information exposed',
            self::Other => 'Other',
        };
    }
}
