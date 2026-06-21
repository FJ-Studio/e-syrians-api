<?php

declare(strict_types=1);

namespace App\Enums;

enum ProfileChangeTypeEnum: string
{
    case BasicData = 'basic_data';
    case Address = 'address';
    case Regular = 'regular';
    case Social = 'social';
    case Avatar = 'avatar';
    /**
     * Religion changes are gated separately from the rest of
     * Census data because polls can target by religious
     * affiliation — without a per-year cap, a user could flip
     * their religion just-in-time to vote in a poll that targets
     * a different group. Audit-logged under this dedicated change
     * type so we can rate-limit on it independently of the
     * generic Census audit.
     */
    case Religion = 'religion';
}
