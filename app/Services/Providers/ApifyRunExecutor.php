<?php

namespace App\Services\Providers;

use App\DataTransferObjects\ProviderRunResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApifyRunExecutor
{
    private const TERMINAL_RUN_STATUSES = ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMED_OUT'];
    private const DEFAULT_POLL_SECONDS = 5;
    private const DEFAULT_TIMEOUT_SECONDS = 300;

    public function run(string $actorKey, string $platform, array $input): ProviderRunResult
    {
        $token = (string) config('services.apify.token');
        $actorId = (string) config('services.apify.actors.' . $actorKey);

        if ($token === '' || $actorId === '') {
            throw new RuntimeException('Missing Apify config for actor: ' . $actorKey);
        }

        $startResponse = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->post("https://api.apify.com/v2/acts/{$actorId}/runs", $input);

        if (!$startResponse->successful()) {
            throw new RuntimeException('Failed to start Apify actor: ' . $startResponse->body());
        }

        $startData = $startResponse->json('data') ?? [];
        $runId = (string) ($startData['id'] ?? '');
        if ($runId === '') {
            throw new RuntimeException('Apify run ID missing in response');
        }

        $runData = $this->pollRun($token, $runId);
        $datasetId = (string) ($runData['defaultDatasetId'] ?? '');
        $items = $this->fetchDatasetItems($token, $datasetId);

        return new ProviderRunResult(
            provider: 'apify',
            platform: $platform,
            runId: $runId,
            datasetId: $datasetId,
            items: $items,
            requestPayload: $input,
            responsePayload: [
                'start' => $startData,
                'run' => $runData,
            ],
        );
    }

    private function pollRun(string $token, string $runId): array
    {
        $deadline = time() + self::DEFAULT_TIMEOUT_SECONDS;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get("https://api.apify.com/v2/actor-runs/{$runId}");

            if (!$response->successful()) {
                throw new RuntimeException('Failed to poll Apify run: ' . $response->body());
            }

            $run = $response->json('data') ?? [];
            $status = strtoupper((string) ($run['status'] ?? ''));

            if (in_array($status, self::TERMINAL_RUN_STATUSES, true)) {
                if ($status !== 'SUCCEEDED') {
                    throw new RuntimeException('Apify run ended with status ' . $status);
                }

                return $run;
            }

            sleep(self::DEFAULT_POLL_SECONDS);
        } while (time() < $deadline);

        throw new RuntimeException('Timed out while waiting for Apify run ' . $runId);
    }

    private function fetchDatasetItems(string $token, string $datasetId): array
    {
        if ($datasetId === '') {
            return [];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to fetch dataset items: ' . $response->body());
        }

        $items = json_decode($response->body(), true);

        return is_array($items) ? $items : [];
    }
}
