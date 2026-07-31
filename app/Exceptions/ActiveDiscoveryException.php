<?php

namespace App\Exceptions;

use RuntimeException;

class ActiveDiscoveryException extends RuntimeException
{
    public function __construct(public readonly string $activeJobId)
    {
        parent::__construct('A discovery is already running in this workspace.');
    }
}
