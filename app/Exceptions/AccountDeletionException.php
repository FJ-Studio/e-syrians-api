<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AccountDeletionService when request-deletion /
 * cancel-deletion fails a precondition (wrong password, no pending
 * request, etc.). Mirrors PollVotingException — the controller
 * catches, unwraps `getDetails()`, and hands the pair
 * `[message, details]` back through ApiService::error.
 */
class AccountDeletionException extends Exception
{
    private array $details;

    public function __construct(string $message = 'account_deletion_error', int $code = 422, array $details = [])
    {
        $this->details = $details;
        parent::__construct($message, $code);
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
