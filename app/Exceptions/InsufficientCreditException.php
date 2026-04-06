<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        string $message = 'Insufficient credits for this action.',
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function context(): array
    {
        return $this->context;
    }
}
