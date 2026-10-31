<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AudienceService for validation-side errors that don't
 * fit a FormRequest (temporal checks, ownership violations,
 * "referenced by active poll" gates, etc.). Mirrors the shape of
 * PollVotingException so ApiService can render a consistent JSON
 * envelope — a raw string message key + optional structured
 * details for the frontend.
 */
class AudienceException extends Exception
{
    /** @var array<string, mixed> */
    private array $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message = 'audience_error', int $code = 400, array $details = [])
    {
        $this->details = $details;
        parent::__construct($message, $code);
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
