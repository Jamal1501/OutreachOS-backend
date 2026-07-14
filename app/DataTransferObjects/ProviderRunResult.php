<?php

namespace App\DataTransferObjects;

final class ProviderRunResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $platform,
        public readonly string $runId,
        public readonly string $datasetId,
        public readonly array $items,
        public readonly array $requestPayload = [],
        public readonly array $responsePayload = [],
        public readonly array $billing = [],
    ) {
    }

    public function itemCount(): int
    {
        return count($this->items);
    }
}
