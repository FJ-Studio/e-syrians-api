<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class FeatureRequestException extends Exception
{
    public function __construct(string $message = 'feature_request_error', int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
