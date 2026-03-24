<?php

namespace App\Contracts;

use App\DataTransferObjects\ProviderRunResult;

interface DiscoveryProvider
{
    public function discover(string $platform, array $hashtags, int $limit): ProviderRunResult;
}
