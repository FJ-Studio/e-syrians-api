<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationReasonEnum: string
{
    case First_Registrant = 'first_registrant';
    case Verifiers = 'verifiers';
}
