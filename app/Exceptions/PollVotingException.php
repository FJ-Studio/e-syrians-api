<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class PollVotingException extends Exception
{
    public function __construct(string $message = 'voting_error', int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
