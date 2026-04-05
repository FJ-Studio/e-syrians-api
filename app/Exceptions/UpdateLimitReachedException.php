<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class UpdateLimitReachedException extends Exception
{
    public function __construct(string $message = 'update_limit_reached', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}
