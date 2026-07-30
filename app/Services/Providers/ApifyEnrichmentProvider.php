<?php

namespace App\Services\Providers;

use App\Contracts\EnrichmentProvider;
use App\DataTransferObjects\ProviderRunResult;
use App\Services\ScraperRegistryService;
use App\Services\WorkspaceBillingService;
use Throwable;

class ApifyEnrichmentProvider implements EnrichmentProvider
{
    public function __construct(
        private ApifyRunExecutor $executor,
        private ScraperRegistryService $scrapers,
        private WorkspaceBillingService $billing,
    ) {
    }

    public function enrich(string $platform, array $urls, array $hashtags, int $limit, array $context = []): ProviderRunResult
    {
        $planId = (string) ($context['planId'] ?? 'free');
        $module = $this->scrapers->resolvePipelineModule($planId, $platform, 'enrichment', $context['moduleKey'] ?? null);
        $actorKey = (string) ($module['actorKey'] ?? ($platform === 'instagram' ? 'instagram_profile' : 'tiktok_profile'));
        $cleanUrls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        $effectiveLimit = min(max(1, $limit), count($cleanUrls) ?: max(1, $limit));
        $cleanUrls = array_slice($cleanUrls, 0, $effectiveLimit);
        $workspaceId = trim((string) ($context['workspaceId'] ?? ''));
        $batchSize = max(1, (int) ($module['maxBatchSize'] ?? 100));
        $aggregateInput = $this->buildInput($platform, $cleanUrls);
        $reservation = $workspaceId !== ''
            ? $this->billing->reserveApify(
                workspaceId: $workspaceId,
                moduleKey: (string) $module['key'],
                actorKey: $actorKey,
                actorId: (string) $module['actorId'],
                input: $aggregateInput,
                maxChargeUsd: null,
            )
            : null;
        $reservationId = (string) ($reservation['usage_event_id'] ?? '');
        $items = [];
        $runs = [];
        $providerCostUsd = 0.0;
        $providerCostKnown = false;
        $totalBatches = max(1, (int) ceil(count($cleanUrls) / $batchSize));
        $completedProfiles = 0;

        try {
            foreach (array_chunk($cleanUrls, $batchSize) as $batchIndex => $batchUrls) {
                $input = $this->buildInput($platform, $batchUrls);
                $result = $this->executor->run($actorKey, $platform, $input, array_merge($context, [
                    'moduleKey' => $module['key'],
                    'stage' => 'enrichment',
                    'fetchLimit' => count($batchUrls),
                    'externalUsageReservationId' => $reservationId,
                ]));

                $items = array_merge($items, $result->items);
                $cost = $result->billing['providerCostUsd'] ?? null;
                if (is_numeric($cost)) {
                    $providerCostUsd += (float) $cost;
                    $providerCostKnown = true;
                }
                $runs[] = [
                    'batch' => $batchIndex + 1,
                    'runId' => $result->runId,
                    'datasetId' => $result->datasetId,
                    'requestedProfiles' => count($batchUrls),
                    'returnedItems' => count($result->items),
                ];
                $completedProfiles += count($batchUrls);
                if (is_callable($context['onBatchProgress'] ?? null)) {
                    ($context['onBatchProgress'])($completedProfiles, count($cleanUrls), $batchIndex + 1, $totalBatches);
                }
            }

            if ($reservationId !== '') {
                $this->billing->consumeReservation(
                    $reservationId,
                    providerCostUsd: $providerCostKnown ? $providerCostUsd : null,
                    metadata: [
                        'batched_enrichment' => true,
                        'batch_size' => $batchSize,
                        'batch_count' => count($runs),
                        'runs' => $runs,
                    ],
                    referenceId: (string) ($runs[0]['runId'] ?? ''),
                );
            }
        } catch (Throwable $exception) {
            if ($reservationId !== '') {
                $this->billing->refundReservation($reservationId, 'Batched enrichment failed', [
                    'completed_batches' => count($runs),
                    'message' => $exception->getMessage(),
                ]);
            }
            throw $exception;
        }

        return new ProviderRunResult(
            provider: 'apify',
            platform: $platform,
            runId: (string) ($runs[0]['runId'] ?? ''),
            datasetId: (string) ($runs[0]['datasetId'] ?? ''),
            items: count($items) > $limit ? array_slice($items, 0, $limit) : $items,
            requestPayload: $aggregateInput,
            responsePayload: ['batched' => true, 'runs' => $runs],
            billing: [
                'usageEventId' => $reservationId ?: null,
                'creditBucket' => $reservation['credit_bucket'] ?? null,
                'creditCost' => $reservation['credit_cost'] ?? null,
                'units' => $reservation['units'] ?? count($cleanUrls),
                'providerCostUsd' => $providerCostKnown ? $providerCostUsd : null,
                'providerCostSource' => $providerCostKnown ? 'apify_batched_run_usage' : 'apify_run_cost_unavailable',
                'remainingBalanceAfterReservation' => $reservation['remaining_balance'] ?? null,
            ],
        );
    }

    private function buildInput(string $platform, array $urls): array
    {
        if ($platform === 'instagram') {
            return [
                'addParentData' => false,
                'directUrls' => array_values($urls),
                'onlyPostsNewerThan' => '100 days',
                'resultsLimit' => max(1, count($urls)),
                'resultsType' => 'details',
                'search' => '',
                'searchLimit' => 10,
                'searchType' => 'hashtag',
            ];
        }

        return [
            'directUrls' => array_values($urls),
            'profiles' => array_values(array_filter(array_map(function (string $url) {
                $parts = explode('@', $url, 2);
                $handle = $parts[1] ?? '';
                return trim(explode('/', $handle)[0] ?? '');
            }, $urls))),
            'resultsLimit' => max(1, count($urls)),
            'excludePinnedPosts' => false,
            'shouldDownloadAvatars' => false,
            'shouldDownloadCovers' => false,
            'shouldDownloadSlideshowImages' => false,
            'shouldDownloadSubtitles' => false,
            'shouldDownloadVideos' => false,
        ];
    }
}
