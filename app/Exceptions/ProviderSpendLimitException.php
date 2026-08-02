<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderSpendLimitException extends RuntimeException
{
    public function __construct(
        private readonly array $limitContext,
        string $message = 'New provider work is temporarily paused by a safety limit. Your credits were not charged. Please try again later or contact support.',
    ) {
        parent::__construct($message);
    }

    public function limitContext(): array
    {
        return $this->limitContext;
    }
}
