<?php

declare(strict_types=1);

namespace App\Enums;

enum RevealResultsEnum: string
{
    case BeforeVoting = 'before-voting';
    case AfterVoting = 'after-voting';
    case AfterExpiration = 'after-expiration';
}
