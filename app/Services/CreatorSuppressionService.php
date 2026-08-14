<?php

namespace App\Services;

use App\Models\Creator;
use App\Models\CreatorProfile;
use App\Models\CreatorSuppression;
use App\Models\DiscoveryItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatorSuppressionService
{
    public function normalizeHandle(?string $handle): string
    {
        $value = strtolower(trim((string) $handle));
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $path = trim((string) parse_url($value, PHP_URL_PATH), '/');
            $segments = array_values(array_filter(explode('/', $path)));
            $value = (string) end($segments);
        }

        return ltrim(trim($value), '@');
    }

    public function hashEmail(?string $email): string
    {
        $normalized = strtolower(trim((string) $email));

        return $normalized === '' ? '' : hash('sha256', $normalized);
    }

    public function isSuppressed(?string $platform, ?string $handle = null, ?string $email = null): bool
    {
        $normalizedPlatform = strtolower(trim((string) $platform));
        $normalizedHandle = $this->normalizeHandle($handle);
        $emailHash = $this->hashEmail($email);

        if ($normalizedHandle === '' && $emailHash === '') {
            return false;
        }

        return CreatorSuppression::query()
            ->where(function ($query) use ($normalizedPlatform, $normalizedHandle, $emailHash) {
                if ($normalizedHandle !== '') {
                    $query->where(function ($handleQuery) use ($normalizedPlatform, $normalizedHandle) {
                        $handleQuery->where('normalized_handle', $normalizedHandle)
                            ->where(function ($platformQuery) use ($normalizedPlatform) {
                                $platformQuery->whereNull('platform');
                                if ($normalizedPlatform !== '') {
                                    $platformQuery->orWhere('platform', $normalizedPlatform);
                                }
                            });
                    });
                }
                if ($emailHash !== '') {
                    $method = $normalizedHandle !== '' ? 'orWhere' : 'where';
                    $query->{$method}('email_hash', $emailHash);
                }
            })
            ->exists();
    }

    public function suppress(?string $platform, ?string $handle, ?string $email, ?string $reason, ?string $actorId): CreatorSuppression
    {
        $normalizedPlatform = strtolower(trim((string) $platform)) ?: null;
        $normalizedHandle = $this->normalizeHandle($handle) ?: null;
        $emailHash = $this->hashEmail($email) ?: null;
        if ($normalizedHandle === null && $emailHash === null) {
            throw new InvalidArgumentException('A creator handle or email address is required.');
        }

        return DB::transaction(function () use ($normalizedPlatform, $normalizedHandle, $emailHash, $reason, $actorId) {
            $suppression = $normalizedHandle
                ? CreatorSuppression::query()->firstOrNew([
                    'platform' => $normalizedPlatform,
                    'normalized_handle' => $normalizedHandle,
                ])
                : CreatorSuppression::query()->firstOrNew(['email_hash' => $emailHash]);

            $emailAlreadySuppressedElsewhere = $emailHash !== null && CreatorSuppression::query()
                ->where('email_hash', $emailHash)
                ->when($suppression->exists, fn ($query) => $query->where('id', '!=', $suppression->getKey()))
                ->exists();
            if ($emailHash !== null && ! $emailAlreadySuppressedElsewhere) {
                $suppression->email_hash = $emailHash;
            }
            $suppression->source = 'privacy_request';
            $suppression->reason = trim((string) $reason) ?: null;
            $suppression->created_by_user_id = $actorId;
            $suppression->save();

            $this->removeExistingProfiles($normalizedPlatform, $normalizedHandle, $emailHash);

            return $suppression->fresh();
        });
    }

    public function filterProviderItems(string $platform, array $items): array
    {
        return array_values(array_filter($items, function ($item) use ($platform) {
            $record = is_array($item) ? $item : (array) $item;
            $handle = $record['handle']
                ?? $record['username']
                ?? $record['ownerUsername']
                ?? $record['authorUsername']
                ?? data_get($record, 'owner.username')
                ?? data_get($record, 'author.uniqueId')
                ?? data_get($record, 'authorMeta.name')
                ?? $record['profileUrl']
                ?? null;
            $email = $record['email']
                ?? $record['businessEmail']
                ?? $record['contactEmail']
                ?? data_get($record, 'owner.email')
                ?? data_get($record, 'authorMeta.email')
                ?? null;

            return ! $this->isSuppressed($platform, is_scalar($handle) ? (string) $handle : null, is_scalar($email) ? (string) $email : null);
        }));
    }

    public function filterProfiles(string $platform, array $profiles): array
    {
        return array_values(array_filter($profiles, fn (array $profile) => ! $this->isSuppressed(
            $platform,
            (string) ($profile['handle'] ?? $profile['username'] ?? $profile['profileUrl'] ?? ''),
            isset($profile['email']) ? (string) $profile['email'] : null,
        )));
    }

    private function removeExistingProfiles(?string $platform, ?string $normalizedHandle, ?string $emailHash): void
    {
        if ($normalizedHandle !== null) {
            DiscoveryItem::query()
                ->whereRaw("LOWER(LTRIM(COALESCE(handle, username, ''), '@')) = ?", [$normalizedHandle])
                ->when($platform !== null, fn ($query) => $query->where('platform', $platform))
                ->delete();
        }

        $emailCreatorIds = collect();
        if ($emailHash !== null) {
            $emailCreatorIds = Creator::query()
                ->whereNotNull('primary_email')
                ->get(['id', 'primary_email'])
                ->filter(fn (Creator $creator) => hash_equals($emailHash, $this->hashEmail($creator->primary_email)))
                ->pluck('id');
        }

        if ($normalizedHandle === null && $emailCreatorIds->isEmpty()) {
            return;
        }

        $query = CreatorProfile::query()->where(function ($profileQuery) use ($platform, $normalizedHandle, $emailCreatorIds) {
            if ($normalizedHandle !== null) {
                $profileQuery->where(function ($handleQuery) use ($platform, $normalizedHandle) {
                    $handleQuery->whereRaw("LOWER(LTRIM(handle, '@')) = ?", [$normalizedHandle]);
                    if ($platform !== null) {
                        $handleQuery->where('platform', $platform);
                    }
                });
            }
            if ($emailCreatorIds->isNotEmpty()) {
                $method = $normalizedHandle !== null ? 'orWhereIn' : 'whereIn';
                $profileQuery->{$method}('creator_id', $emailCreatorIds);
            }
        });

        $creatorIds = $query->pluck('creator_id')->filter()->unique()->values();
        $query->delete();

        Creator::query()->whereIn('id', $creatorIds)
            ->whereDoesntHave('profiles')
            ->delete();
    }
}
