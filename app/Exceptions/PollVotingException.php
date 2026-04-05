<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class PollVotingException extends Exception
{
    private array $details;

    public function __construct(string $message = 'voting_error', int $code = 400, array $details = [])
    {
        $this->details = $details;
        parent::__construct($message, $code);
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
