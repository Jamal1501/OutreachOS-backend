<?php

namespace App\Services\Providers;

use App\DataTransferObjects\ProviderRunResult;
use App\Exceptions\InsufficientCreditsException;
use App\Services\ProviderUsageLogger;
use App\Services\WorkspaceBillingService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApifyRunExecutor
{
    public function __construct(
        private ProviderUsageLogger $usageLogger,
        private \App\Services\ScraperRegistryService $scrapers,
        private WorkspaceBillingService $billing,
    ) {
    }

    private const TERMINAL_RUN_STATUSES = ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT', 'TIMED_OUT'];
    private const DEFAULT_POLL_SECONDS = 3;
    private const DEFAULT_TIMEOUT_SECONDS = 300;

    public function run(string $actorKey, string $platform, array $input, array $context = []): ProviderRunResult
    {
        $token = (string) config('services.apify.token');
        $actorId = (string) config('services.apify.actors.' . $actorKey);

        if ($token === '' || $actorId === '') {
            throw new RuntimeException('Missing Apify config for actor: ' . $actorKey);
        }

        $usageReservationId = null;
        $usageReservation = null;
        $moduleKey = isset($context['moduleKey']) ? trim((string) $context['moduleKey']) : null;
        $workspaceId = isset($context['workspaceId']) ? trim((string) $context['workspaceId']) : null;
        $maxTotalChargeUsd = isset($context['maxTotalChargeUsd']) && is_numeric($context['maxTotalChargeUsd'])
            ? (float) $context['maxTotalChargeUsd']
            : (config('services.apify.default_max_total_charge_usd') !== null ? (float) config('services.apify.default_max_total_charge_usd') : null);

        $query = array_filter([
            'maxTotalChargeUsd' => $maxTotalChargeUsd,
            'memoryMbytes' => isset($context['memoryMbytes']) && is_numeric($context['memoryMbytes']) ? (int) $context['memoryMbytes'] : null,
            'timeoutSecs' => isset($context['timeoutSecs']) && is_numeric($context['timeoutSecs']) ? (int) $context['timeoutSecs'] : null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            if ($workspaceId && $moduleKey) {
                $reservation = $this->billing->reserveApify(
                    workspaceId: $workspaceId,
                    moduleKey: $moduleKey,
                    actorKey: $actorKey,
                    actorId: $actorId,
                    input: $input,
                    maxChargeUsd: $maxTotalChargeUsd,
                );
                $usageReservation = $reservation;
                $usageReservationId = $reservation['usage_event_id'] ?? null;
            }

            $url = "https://api.apify.com/v2/acts/{$actorId}/runs";
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }

            $startResponse = Http::withToken($token)
                ->acceptJson()
                ->timeout(90)
                ->post($url, $input);

            if (!$startResponse->successful()) {
                if ($usageReservationId) {
                    $this->billing->refundReservation($usageReservationId, 'Failed to start Apify actor', [
                        'status' => $startResponse->status(),
                    ]);
                }

                $this->usageLogger->logApify([
                    'actor_id' => $actorId,
                    'actor_key' => $actorKey,
                    'status' => 'failed_to_start',
                    'max_total_charge_usd' => $maxTotalChargeUsd,
                    'request_payload' => $input,
                    'response_payload' => ['body' => $startResponse->body()],
                    'error_message' => 'Failed to start Apify actor',
                ]);

                throw new RuntimeException('Failed to start Apify actor: ' . $startResponse->body());
            }

            $startData = $startResponse->json('data') ?? [];
            $runId = (string) ($startData['id'] ?? '');
            if ($runId === '') {
                throw new RuntimeException('Apify run ID missing in response');
            }

            $runData = $this->pollRun($token, $runId);
            $datasetId = (string) ($runData['defaultDatasetId'] ?? '');
            $datasetFetchLimit = isset($context['fetchLimit']) && is_numeric($context['fetchLimit'])
                ? max(1, (int) $context['fetchLimit'])
                : (isset($input['resultsLimit']) && is_numeric($input['resultsLimit'])
                    ? max(1, (int) $input['resultsLimit'])
                    : (isset($input['directUrls']) && is_array($input['directUrls']) ? max(1, count($input['directUrls'])) : null));

            $items = $this->fetchDatasetItems($token, $datasetId, $datasetFetchLimit);
            $actualProviderCostUsd = $this->extractApifyRunCostUsd($runData);

            if ($usageReservationId) {
                $this->billing->consumeReservation(
                    $usageReservationId,
                    providerCostUsd: $actualProviderCostUsd,
                    metadata: [
                        'module_key' => $moduleKey,
                        'run_id' => $runId,
                        'dataset_id' => $datasetId,
                        'provider_cost_source' => $actualProviderCostUsd !== null ? 'apify_run_usage' : 'apify_run_cost_unavailable',
                        'max_total_charge_usd' => $maxTotalChargeUsd,
                    ],
                    referenceId: $runId,
                );
            }

            $this->usageLogger->logApify([
                'actor_id' => $actorId,
                'actor_key' => $actorKey,
                'run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => $runData['status'] ?? 'SUCCEEDED',
                'max_total_charge_usd' => $maxTotalChargeUsd,
                'estimated_cost_usd' => $actualProviderCostUsd,
                'request_payload' => $input,
                'response_payload' => [
                    'start' => $startData,
                    'run' => $runData,
                ],
            ]);

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
                billing: [
                    'usageEventId' => $usageReservationId,
                    'creditBucket' => $usageReservation['credit_bucket'] ?? null,
                    'creditCost' => $usageReservation['credit_cost'] ?? null,
                    'units' => $usageReservation['units'] ?? null,
                    'providerCostUsd' => $actualProviderCostUsd,
                    'providerCostSource' => $actualProviderCostUsd !== null ? 'apify_run_usage' : 'apify_run_cost_unavailable',
                    'remainingBalanceAfterReservation' => $usageReservation['remaining_balance'] ?? null,
                ],
            );
        } catch (InsufficientCreditsException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($usageReservationId) {
                $this->billing->refundReservation($usageReservationId, 'Pipeline Apify execution failed', [
                    'message' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
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

    private function extractApifyRunCostUsd(array $runData): ?float
    {
        foreach ([
            'usageTotalUsd',
            'usageUsd',
            'costUsd',
            'stats.costUsd',
            'usage.totalUsd',
            'usage.totalCostUsd',
        ] as $path) {
            $value = data_get($runData, $path);
            if (is_numeric($value)) {
                return round(max(0, (float) $value), 6);
            }
        }

        return null;
    }

    private function fetchDatasetItems(string $token, string $datasetId, ?int $limit = null): array
    {
        if ($datasetId === '') {
            return [];
        }

        $query = [
            'clean' => 'true',
            'format' => 'json',
        ];

        if ($limit !== null && $limit > 0) {
            $query['limit'] = $limit;
            $query['offset'] = 0;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(90)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", $query);

        if (!$response->successful()) {
            throw new RuntimeException('Failed to fetch dataset items: ' . $response->body());
        }

        $items = json_decode($response->body(), true);

        return is_array($items) ? $items : [];
    }
}
