<?php

namespace App\Contracts;

use App\DataTransferObjects\ProviderRunResult;

interface EnrichmentProvider
{
    public function enrich(string $platform, array $urls, array $hashtags, int $limit, array $context = []): ProviderRunResult;
}
