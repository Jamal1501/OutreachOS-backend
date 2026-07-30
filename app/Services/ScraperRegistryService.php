<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class ScraperRegistryService
{
    public function modules(bool $configuredOnly = false): array
    {
        $modules = config('scrapers.modules', []);
        if (!is_array($modules)) {
            return [];
        }

        $result = [];
        foreach ($modules as $key => $module) {
            if (!is_array($module)) {
                continue;
            }

            $normalized = $this->normalizeModule($module + ['key' => $key]);
            if ($configuredOnly && !$normalized['isConfigured']) {
                continue;
            }

            $result[$normalized['key']] = $normalized;
        }

        return $result;
    }

    public function module(string $moduleKey, bool $requireConfigured = false): ?array
    {
        $module = $this->modules()[$moduleKey] ?? null;
        if (!$module) {
            return null;
        }

        if ($requireConfigured && !$module['isConfigured']) {
            return null;
        }

        return $module;
    }


    public function estimatePipeline(
        string $planId,
        string $platform,
        int $discoveryLimit,
        int $enrichmentLimit,
        int $seedCount = 1,
        ?string $discoveryModuleKey = null,
        ?string $enrichmentModuleKey = null,
    ): array
    {
        $planId = $this->normalizePlanId($planId);
        $seedCount = max(1, $seedCount);

        $discoveryModule = $this->resolvePipelineModule($planId, $platform, 'discovery', $discoveryModuleKey);
        $enrichmentModule = $this->resolvePipelineModule($planId, $platform, 'enrichment', $enrichmentModuleKey);

        $effectiveDiscoveryLimit = $this->normalizeDiscoveryLimitForSeeds($discoveryLimit, $seedCount, $discoveryModule);
        $effectiveEnrichmentLimit = max(1, $enrichmentLimit);

$discoveryEstimatePerSeed = $this->estimateCredits($discoveryModule['key'], null, null, [
    'resultsLimit' => $effectiveDiscoveryLimit,
]);

$discoveryCreditPerSeed = (int) ($discoveryEstimatePerSeed['credit_cost'] ?? 0);
$discoveryUnitsPerSeed = (int) ($discoveryEstimatePerSeed['units'] ?? 0);

$totalDiscoveryCreditCost = $discoveryCreditPerSeed * $seedCount;
$totalDiscoveryUnits = $discoveryUnitsPerSeed * $seedCount;

$discoveryEstimate = $discoveryEstimatePerSeed;
$discoveryEstimate['units_per_seed'] = $discoveryUnitsPerSeed;
$discoveryEstimate['credit_cost_per_seed'] = $discoveryCreditPerSeed;
$discoveryEstimate['units'] = $totalDiscoveryUnits;
$discoveryEstimate['credit_cost'] = $totalDiscoveryCreditCost;

$enrichmentEstimate = $this->estimateCredits($enrichmentModule['key'], null, null, [
    'directUrls' => array_fill(0, $effectiveEnrichmentLimit, 'profile-url'),
    'resultsLimit' => $effectiveEnrichmentLimit,
]);

return [
    'planId' => $planId,
    'platform' => $platform,
    'seedCount' => $seedCount,
    'discovery' => [
        'requestedLimit' => max(1, $discoveryLimit),
        'effectiveLimitPerSeed' => $effectiveDiscoveryLimit,
        'effectiveTotalRequested' => $effectiveDiscoveryLimit * $seedCount,
        'module' => $discoveryModule,
        'estimate' => $discoveryEstimate,
    ],
    'enrichment' => [
        'requestedLimit' => max(1, $enrichmentLimit),
        'effectiveLimit' => $effectiveEnrichmentLimit,
        'module' => $enrichmentModule,
        'estimate' => $enrichmentEstimate,
    ],
    'totals' => [
        'scrapeCredits' => $totalDiscoveryCreditCost + (int) ($enrichmentEstimate['credit_cost'] ?? 0),
    ],
];
    }

    public function resolvePipelineModule(string $planId, string $platform, string $stage, ?string $preferredModuleKey = null, bool $configuredOnly = true): array
    {
        $planId = $this->normalizePlanId($planId);

        if ($preferredModuleKey) {
            $preferred = $this->module($preferredModuleKey, $configuredOnly);
            if ($preferred && $preferred['platform'] === $platform && $preferred['stage'] === $stage && in_array($planId, $preferred['allowedPlans'], true)) {
                return $preferred;
            }
        }

        $module = $this->defaultModuleForPlan($planId, $platform, $stage, $configuredOnly);
        if ($module) {
            return $module;
        }

        $fallback = $this->systemDefaultModule($platform, $stage, $configuredOnly);
        if ($fallback) {
            return $fallback;
        }

        throw new RuntimeException(sprintf('No configured scraper module found for %s %s on plan %s.', $platform, $stage, $planId));
    }

    public function normalizeDiscoveryLimitForSeeds(int $requestedLimit, int $seedCount, array $module): int
    {
        $requestedLimit = max(1, $requestedLimit);
        $seedCount = max(1, $seedCount);
        $perSeed = (int) ceil($requestedLimit / $seedCount);

        return $this->clampBatchSize($perSeed, $module);
    }

    public function clampBatchSize(int $requestedLimit, array $module): int
    {
        $requestedLimit = max(1, $requestedLimit);
        $maxBatchSize = max(1, (int) ($module['maxBatchSize'] ?? $requestedLimit));

        return min($requestedLimit, $maxBatchSize);
    }
    public function configuredActorMap(): array
    {
        $map = [];
        foreach ($this->modules() as $module) {
            if (!$module['isConfigured']) {
                continue;
            }
            $map[$module['actorKey']] = $module['actorId'];
        }

        return $map;
    }

    public function availableForPlan(string $planId, ?string $platform = null, ?string $stage = null, bool $configuredOnly = true): array
    {
        $planId = $this->normalizePlanId($planId);

        $items = array_filter($this->modules($configuredOnly), function (array $module) use ($planId, $platform, $stage) {
            if ($platform && $module['platform'] !== $platform) {
                return false;
            }

            if ($stage && $module['stage'] !== $stage) {
                return false;
            }

            return in_array($planId, $module['allowedPlans'], true);
        });

        uasort($items, function (array $a, array $b) {
            return [$a['platform'], $a['stage'], $this->depthRank($a['depth']), $a['key']]
                <=> [$b['platform'], $b['stage'], $this->depthRank($b['depth']), $b['key']];
        });

        return array_values($items);
    }

    public function defaultModuleForPlan(string $planId, string $platform, string $stage, bool $configuredOnly = true): ?array
    {
        $modules = $this->availableForPlan($planId, $platform, $stage, $configuredOnly);
        if ($modules === []) {
            return null;
        }

        $configuredDefaultKey = trim((string) config("scrapers.defaults.{$platform}.{$stage}", ''));
        foreach ($modules as $module) {
            if ($configuredDefaultKey !== '' && $module['key'] === $configuredDefaultKey) {
                return $module;
            }
        }

        usort($modules, fn (array $a, array $b) => $this->depthRank($b['depth']) <=> $this->depthRank($a['depth']));

        return $modules[0] ?? null;
    }

    public function systemDefaultModule(string $platform, string $stage, bool $configuredOnly = true): ?array
    {
        $defaults = config('scrapers.defaults.' . $platform . '.' . $stage);
        if (is_string($defaults) && trim($defaults) !== '') {
            $module = $this->module(trim($defaults), $configuredOnly);
            if ($module) {
                return $module;
            }
        }

        $modules = array_values(array_filter($this->modules($configuredOnly), fn (array $module) => $module['platform'] === $platform && $module['stage'] === $stage));
        if ($modules === []) {
            return null;
        }

        usort($modules, fn (array $a, array $b) => $this->depthRank($a['depth']) <=> $this->depthRank($b['depth']));

        return $modules[0] ?? null;
    }

    public function resolveExecution(?string $moduleKey, ?string $actorKey, ?string $actorId, string $planId): array
    {
        $planId = $this->normalizePlanId($planId);

        if ($moduleKey) {
            $module = $this->module($moduleKey, true);
            if (!$module) {
                throw new RuntimeException('Unknown or unconfigured scraper module: ' . $moduleKey);
            }

            if (!in_array($planId, $module['allowedPlans'], true)) {
                throw new RuntimeException(sprintf('Plan %s is not allowed to run scraper module %s.', $planId, $moduleKey));
            }

            return $module;
        }

        if ($actorKey) {
            $module = $this->moduleByActorKey($actorKey, $planId);
            if ($module) {
                return $module;
            }
        }

        if ($actorId) {
            $module = $this->moduleByActorId($actorId, $planId);
            if ($module) {
                return $module;
            }
        }

        throw new RuntimeException('No plan-allowed scraper module matched the request.');
    }

    public function estimateCredits(?string $moduleKey, ?string $actorKey, ?string $actorId, array $input): array
    {
        $module = $moduleKey ? $this->module($moduleKey) : null;
        if (!$module && $actorKey) {
            $module = $this->moduleByActorKey($actorKey);
        }
        if (!$module && $actorId) {
            $module = $this->moduleByActorId($actorId);
        }

        if (!$module) {
            return [
                'type' => 'scrape',
                'bucket' => 'scrape',
                'units' => max(1, $this->estimateDiscoveryCount($input)),
                'credit_cost' => max(1, $this->estimateDiscoveryCount($input)),
                'cost_class' => 'fallback',
                'module_key' => $moduleKey,
            ];
        }

        $costClass = (string) ($module['costClass'] ?? 'discovery_basic');
        $costConfig = config('scrapers.cost_classes.' . $costClass, []);
        $strategy = (string) Arr::get($costConfig, 'strategy', 'results_limit');
        $multiplier = max(1, (int) Arr::get($costConfig, 'multiplier', 1));
        $divisor = max(1, (int) Arr::get($costConfig, 'divisor', 1));
        $minUnits = max(1, (int) Arr::get($costConfig, 'min_units', 1));
        $minCreditCost = max(1, (int) Arr::get($costConfig, 'min_credit_cost', 1));

        $units = match ($strategy) {
            'target_count' => max($minUnits, $this->estimateTargetCount($input)),
            'results_limit' => max($minUnits, $this->estimateDiscoveryCount($input)),
            default => max($minUnits, $this->estimateDiscoveryCount($input)),
        };

        $creditCost = max($minCreditCost, (int) ceil(($units * $multiplier) / $divisor));

        return [
            'type' => (string) Arr::get($costConfig, 'type', 'scrape'),
            'bucket' => (string) Arr::get($costConfig, 'bucket', 'scrape'),
            'units' => $units,
            'credit_cost' => $creditCost,
            'cost_class' => $costClass,
            'module_key' => $module['key'],
            'module' => $module,
        ];
    }

    public function providerChargeLimitUsd(?string $moduleKey, ?string $actorKey, ?string $actorId, array $input): float
    {
        $estimate = $this->estimateCredits($moduleKey, $actorKey, $actorId, $input);
        $units = max(1, (int) ($estimate['units'] ?? 1));
        $costClass = (string) ($estimate['cost_class'] ?? 'discovery_basic');

        $calculated = match ($costClass) {
            'profile_standard' => 0.25 + ($units * 0.03),
            'content_deep', 'comments_deep' => 0.50 + ($units * 0.12),
            default => 0.50 + ($units * 0.005),
        };

        $hardCeiling = max(0.50, (float) config('services.apify.max_charge_hard_ceiling_usd', 10.00));

        return round(min($hardCeiling, max(0.50, $calculated)), 2);
    }

    public function assertWithinExecutionLimit(array $module, array $input): void
    {
        if (($module['stage'] ?? null) !== 'enrichment') {
            return;
        }

        $estimate = $this->estimateCredits(
            (string) ($module['key'] ?? ''),
            (string) ($module['actorKey'] ?? ''),
            (string) ($module['actorId'] ?? ''),
            $input,
        );
        $targetCount = max(1, (int) ($estimate['units'] ?? 1));
        $maximum = max(1, (int) ($module['maxBatchSize'] ?? 1));

        if ($targetCount > $maximum) {
            throw new RuntimeException("Enrichment batch exceeds the server limit of {$maximum} profiles.");
        }
    }

    private function normalizeModule(array $module): array
    {
        $actorKey = (string) ($module['actor_key'] ?? '');
        $actorId = trim((string) config('services.apify.actors.' . $actorKey));

        return [
            'key' => (string) ($module['key'] ?? ''),
            'label' => (string) ($module['label'] ?? ''),
            'platform' => (string) ($module['platform'] ?? ''),
            'stage' => (string) ($module['stage'] ?? ''),
            'depth' => (string) ($module['depth'] ?? 'basic'),
            'costClass' => (string) ($module['cost_class'] ?? 'discovery_basic'),
            'actorKey' => $actorKey,
            'actorId' => $actorId,
            'targetSheet' => (string) ($module['target_sheet'] ?? ''),
            'allowedPlans' => array_values(array_unique(array_map([$this, 'normalizePlanId'], (array) ($module['allowed_plans'] ?? [])))),
            'defaultBatchSize' => max(1, (int) ($module['default_batch_size'] ?? 10)),
            'maxBatchSize' => max(1, (int) ($module['max_batch_size'] ?? 50)),
            'fields' => array_values(array_unique(array_map('strval', (array) ($module['fields'] ?? [])))),
            'notes' => (string) ($module['notes'] ?? ''),
            'isConfigured' => $actorId !== '',
        ];
    }

    private function moduleByActorKey(string $actorKey, ?string $planId = null): ?array
    {
        $actorKey = trim($actorKey);
        if ($actorKey === '') {
            return null;
        }

        foreach ($this->modules(true) as $module) {
            if ($module['actorKey'] !== $actorKey) {
                continue;
            }

            if ($planId && !in_array($this->normalizePlanId($planId), $module['allowedPlans'], true)) {
                continue;
            }

            return $module;
        }

        return null;
    }

    private function moduleByActorId(string $actorId, ?string $planId = null): ?array
    {
        $actorId = trim($actorId);
        if ($actorId === '') {
            return null;
        }

        foreach ($this->modules(true) as $module) {
            if ($module['actorId'] !== $actorId) {
                continue;
            }

            if ($planId && !in_array($this->normalizePlanId($planId), $module['allowedPlans'], true)) {
                continue;
            }

            return $module;
        }

        return null;
    }

    private function normalizePlanId(string $planId): string
    {
        $normalized = Str::lower(trim($planId));
        if ($normalized === 'trial') {
            return 'free';
        }

        return $normalized !== '' ? $normalized : 'free';
    }

    private function depthRank(string $depth): int
    {
        return match (Str::lower(trim($depth))) {
            'basic' => 1,
            'standard' => 2,
            'deep' => 3,
            default => 99,
        };
    }

    private function estimateTargetCount(array $input): int
    {
        foreach (['directUrls', 'profileUrls', 'urls', 'usernames', 'handles', 'profiles'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && count($value) > 0) {
                return count(array_filter($value, fn ($v) => trim((string) $v) !== ''));
            }
        }

        foreach (['profileUrl', 'url', 'username', 'handle'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                return 1;
            }
        }

        return 1;
    }

    private function estimateDiscoveryCount(array $input): int
    {
        $seedCount = 1;
        foreach (['hashtags', 'searchTerms', 'queries', 'keywords'] as $seedKey) {
            $seedValue = $input[$seedKey] ?? null;
            if (is_array($seedValue)) {
                $nonEmptySeeds = array_filter($seedValue, fn ($value) => trim((string) $value) !== '');
                if (count($nonEmptySeeds) > 0) {
                    $seedCount = max($seedCount, count($nonEmptySeeds));
                }
            }
        }

        foreach (['resultsLimit', 'results_limit', 'maxItems', 'max_items', 'limit'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key])) {
                $perSeedLimit = max(1, min(1000, (int) $input[$key]));

                return max(1, min(5000, $perSeedLimit * $seedCount));
            }
        }

        foreach (['hashtags', 'searchTerms', 'queries', 'keywords'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && count($value) > 0) {
                return max(1, min(5000, count(array_filter($value, fn ($seed) => trim((string) $seed) !== '')) * 25));
            }
        }

        return 25;
    }
}
