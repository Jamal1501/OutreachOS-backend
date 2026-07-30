<?php

namespace App\Exceptions;

use RuntimeException;

class PipelineCancelledException extends RuntimeException
{
    public function __construct(
        string $message = 'Discovery was stopped by the user.',
        public readonly ?string $providerRunId = null,
    ) {
        parent::__construct($message);
    }
}
