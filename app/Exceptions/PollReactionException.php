<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class PollReactionException extends Exception
{
    public function __construct(string $message = 'reaction_error', int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
