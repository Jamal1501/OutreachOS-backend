<?php

namespace App\Services;

use App\Models\Creator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CreatorLocationInferenceService
{
    private const MIN_CONFIDENCE_TO_STORE = 0.55;

    /**
     * Infer a creator's own location from public profile/enrichment fields only.
     * This deliberately does not infer audience location.
     */
    public function infer(array $payload, string $platform = ''): array
    {
        $signals = [];

        $this->collectStructuredSignals($signals, $payload);
        $this->collectLocationHintSignals($signals, $payload);
        $this->collectBioSignals($signals, $payload);

        if ($signals === []) {
            return $this->emptyResult();
        }

        usort($signals, fn (array $a, array $b) => ($b['confidence'] <=> $a['confidence']));
        $best = $signals[0];

        $sources = [];
        foreach ($signals as $signal) {
            $source = trim((string) ($signal['source'] ?? ''));
            if ($source !== '') {
                $sources[] = $source;
            }
        }

        $sources = array_values(array_unique($sources));
        $confidence = min(0.98, (float) $best['confidence'] + min(0.08, max(0, count($sources) - 1) * 0.02));

        return [
            'country' => $best['country'] ?? null,
            'city' => $best['city'] ?? null,
            'confidence' => round($confidence, 2),
            'sources' => array_slice($sources, 0, 8),
            'method' => 'public_profile_location_v1',
            'platform' => strtolower(trim($platform)),
        ];
    }

    /**
     * Apply a higher-confidence inferred location to a Creator model.
     * The caller remains responsible for saving the model.
     */
    public function applyToCreator(Creator $creator, array $payload, string $platform = '', bool $overwrite = false): ?array
    {
        $inferred = $this->infer($payload, $platform);
        $confidence = (float) ($inferred['confidence'] ?? 0);

        if ($confidence < self::MIN_CONFIDENCE_TO_STORE) {
            return null;
        }

        $metadata = is_array($creator->metadata) ? $creator->metadata : [];
        $current = is_array($metadata['creator_location'] ?? null) ? $metadata['creator_location'] : [];
        $currentConfidence = (float) ($current['confidence'] ?? $metadata['location_confidence'] ?? 0);

        if (!$overwrite && $currentConfidence > 0 && ($confidence + 0.02) < $currentConfidence) {
            return $current !== [] ? $current : null;
        }

        $country = trim((string) ($inferred['country'] ?? ''));
        $city = trim((string) ($inferred['city'] ?? ''));

        $shouldWriteCountry = $country !== '' && (
            $overwrite
            || trim((string) ($creator->country ?? '')) === ''
            || $confidence >= ($currentConfidence + 0.08)
        );

        $shouldWriteCity = $city !== '' && (
            $overwrite
            || trim((string) ($creator->city ?? '')) === ''
            || $confidence >= ($currentConfidence + 0.08)
        );

        if ($shouldWriteCountry) {
            $creator->country = $country;
        }

        if ($shouldWriteCity) {
            $creator->city = $city;
        }

        $metadata['creator_location'] = [
            'country' => $country ?: ($creator->country ?: null),
            'city' => $city ?: ($creator->city ?: null),
            'confidence' => $confidence,
            'sources' => $inferred['sources'] ?? [],
            'method' => $inferred['method'] ?? 'public_profile_location_v1',
            'platform' => $inferred['platform'] ?? strtolower(trim($platform)),
            'inferred_at' => now()->toDateTimeString(),
        ];
        $metadata['location_confidence'] = $confidence;
        $metadata['location_sources'] = $inferred['sources'] ?? [];
        $metadata['location_method'] = $inferred['method'] ?? 'public_profile_location_v1';
        $creator->metadata = $metadata;

        return $metadata['creator_location'];
    }

    public function confidenceFromCreator(?Creator $creator): ?float
    {
        if (!$creator) {
            return null;
        }

        $metadata = is_array($creator->metadata) ? $creator->metadata : [];
        $value = $metadata['creator_location']['confidence'] ?? $metadata['location_confidence'] ?? null;

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    public function sourcesFromCreator(?Creator $creator): array
    {
        if (!$creator) {
            return [];
        }

        $metadata = is_array($creator->metadata) ? $creator->metadata : [];
        $sources = $metadata['creator_location']['sources'] ?? $metadata['location_sources'] ?? [];

        return array_values(array_filter(array_map('strval', (array) $sources), fn (string $source) => trim($source) !== ''));
    }

    private function collectStructuredSignals(array &$signals, array $payload): void
    {
        $country = $this->firstFilled($payload, [
            'country',
            'Country',
            'Country_Guess',
            'country_guess',
            'region',
            'countryCode',
            'country_code',
            'businessAddress.countryCode',
            'businessAddress.country',
        ]);
        $city = $this->firstFilled($payload, [
            'city',
            'City',
            'City_Guess',
            'city_guess',
            'cityName',
            'addressCityName',
            'businessAddress.cityName',
        ]);

        $normalizedCountry = $this->normalizeCountry($country);
        $normalizedCity = $this->normalizeCity($city);
        $cityCountry = $this->countryForCity($normalizedCity);

        if ($normalizedCountry !== null || $normalizedCity !== null) {
            $signals[] = [
                'country' => $normalizedCountry ?: $cityCountry,
                'city' => $normalizedCity,
                'confidence' => $normalizedCountry && $normalizedCity ? 0.92 : ($normalizedCity ? 0.86 : 0.78),
                'source' => 'structured_profile_location',
            ];
        }
    }

    private function collectLocationHintSignals(array &$signals, array $payload): void
    {
        $hints = [];
        foreach ([
            'locationHint',
            'location',
            'locationCreated',
            'addressCityName',
            'cityName',
            'region',
            'country',
            'countryCode',
        ] as $key) {
            $value = trim((string) Arr::get($payload, $key, ''));
            if ($value !== '') {
                $hints[] = $value;
            }
        }

        foreach (array_values(array_unique($hints)) as $hint) {
            $signal = $this->signalFromFreeText($hint, 'location_hint:' . Str::limit($hint, 60, ''));
            if ($signal !== null) {
                $signal['confidence'] = max((float) $signal['confidence'], 0.72);
                $signals[] = $signal;
            }
        }
    }

    private function collectBioSignals(array &$signals, array $payload): void
    {
        $bioParts = [];
        foreach (['bio', 'biography', 'description', 'signature', 'fullName', 'username', 'handle'] as $key) {
            $value = trim((string) Arr::get($payload, $key, ''));
            if ($value !== '') {
                $bioParts[] = $value;
            }
        }

        $text = trim(implode(' ', $bioParts));
        if ($text === '') {
            return;
        }

        $signal = $this->signalFromFreeText($text, 'bio_or_profile_text');
        if ($signal !== null) {
            $signal['confidence'] = min((float) $signal['confidence'], 0.68);
            $signals[] = $signal;
        }
    }

    private function signalFromFreeText(string $text, string $source): ?array
    {
        $normalized = $this->normalizeText($text);

        foreach ($this->cityMap() as $alias => $location) {
            if (strlen($alias) < 3) {
                continue;
            }
            if ($this->containsToken($normalized, $alias)) {
                return [
                    'country' => $location['country'],
                    'city' => $location['city'],
                    'confidence' => 0.7,
                    'source' => $source,
                ];
            }
        }

        foreach ($this->countryMap() as $alias => $country) {
            if (strlen($alias) < 3) {
                continue;
            }
            if ($this->containsToken($normalized, $alias)) {
                return [
                    'country' => $country,
                    'city' => null,
                    'confidence' => 0.62,
                    'source' => $source,
                ];
            }
        }

        return null;
    }

    private function firstFilled(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) Arr::get($payload, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeCountry(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $key = $this->normalizeText($value);
        $map = $this->countryMap();

        return $map[$key] ?? null;
    }

    private function normalizeCity(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $key = $this->normalizeText($value);
        $map = $this->cityMap();

        return $map[$key]['city'] ?? (string) Str::of($value)->squish()->limit(80, '');
    }

    private function countryForCity(?string $city): ?string
    {
        $city = trim((string) $city);
        if ($city === '') {
            return null;
        }

        $normalized = $this->normalizeText($city);
        foreach ($this->cityMap() as $alias => $location) {
            if ($alias === $normalized || $this->normalizeText((string) $location['city']) === $normalized) {
                return $location['country'];
            }
        }

        return null;
    }

    private function containsToken(string $haystack, string $needle): bool
    {
        $needle = $this->normalizeText($needle);
        if ($needle === '') {
            return false;
        }

        return preg_match('/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/u', $haystack) === 1;
    }

    private function normalizeText(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function emptyResult(): array
    {
        return [
            'country' => null,
            'city' => null,
            'confidence' => 0.0,
            'sources' => [],
            'method' => 'public_profile_location_v1',
            'platform' => '',
        ];
    }

    private function countryMap(): array
    {
        return [
            'de' => 'Germany',
            'deu' => 'Germany',
            'ger' => 'Germany',
            'germany' => 'Germany',
            'deutschland' => 'Germany',
            'at' => 'Austria',
            'aut' => 'Austria',
            'austria' => 'Austria',
            'osterreich' => 'Austria',
            'ch' => 'Switzerland',
            'che' => 'Switzerland',
            'switzerland' => 'Switzerland',
            'schweiz' => 'Switzerland',
            'uk' => 'United Kingdom',
            'gb' => 'United Kingdom',
            'gbr' => 'United Kingdom',
            'united kingdom' => 'United Kingdom',
            'england' => 'United Kingdom',
            'us' => 'United States',
            'usa' => 'United States',
            'united states' => 'United States',
            'united states of america' => 'United States',
            'fr' => 'France',
            'france' => 'France',
            'es' => 'Spain',
            'spain' => 'Spain',
            'italy' => 'Italy',
            'it' => 'Italy',
            'nl' => 'Netherlands',
            'netherlands' => 'Netherlands',
            'nederland' => 'Netherlands',
            'dk' => 'Denmark',
            'denmark' => 'Denmark',
            'se' => 'Sweden',
            'sweden' => 'Sweden',
            'no' => 'Norway',
            'norway' => 'Norway',
            'fi' => 'Finland',
            'finland' => 'Finland',
            'pt' => 'Portugal',
            'portugal' => 'Portugal',
            'lk' => 'Sri Lanka',
            'sri lanka' => 'Sri Lanka',
            'ca' => 'Canada',
            'canada' => 'Canada',
            'au' => 'Australia',
            'australia' => 'Australia',
        ];
    }

    private function cityMap(): array
    {
        return [
            'berlin' => ['city' => 'Berlin', 'country' => 'Germany'],
            'hamburg' => ['city' => 'Hamburg', 'country' => 'Germany'],
            'munich' => ['city' => 'Munich', 'country' => 'Germany'],
            'munchen' => ['city' => 'Munich', 'country' => 'Germany'],
            'muenchen' => ['city' => 'Munich', 'country' => 'Germany'],
            'cologne' => ['city' => 'Cologne', 'country' => 'Germany'],
            'koln' => ['city' => 'Cologne', 'country' => 'Germany'],
            'koeln' => ['city' => 'Cologne', 'country' => 'Germany'],
            'dusseldorf' => ['city' => 'Düsseldorf', 'country' => 'Germany'],
            'duesseldorf' => ['city' => 'Düsseldorf', 'country' => 'Germany'],
            'bonn' => ['city' => 'Bonn', 'country' => 'Germany'],
            'frankfurt' => ['city' => 'Frankfurt', 'country' => 'Germany'],
            'stuttgart' => ['city' => 'Stuttgart', 'country' => 'Germany'],
            'leipzig' => ['city' => 'Leipzig', 'country' => 'Germany'],
            'dortmund' => ['city' => 'Dortmund', 'country' => 'Germany'],
            'essen' => ['city' => 'Essen', 'country' => 'Germany'],
            'bremen' => ['city' => 'Bremen', 'country' => 'Germany'],
            'dresden' => ['city' => 'Dresden', 'country' => 'Germany'],
            'hannover' => ['city' => 'Hannover', 'country' => 'Germany'],
            'hanover' => ['city' => 'Hannover', 'country' => 'Germany'],
            'nuremberg' => ['city' => 'Nuremberg', 'country' => 'Germany'],
            'nurnberg' => ['city' => 'Nuremberg', 'country' => 'Germany'],
            'nuernberg' => ['city' => 'Nuremberg', 'country' => 'Germany'],
            'vienna' => ['city' => 'Vienna', 'country' => 'Austria'],
            'wien' => ['city' => 'Vienna', 'country' => 'Austria'],
            'salzburg' => ['city' => 'Salzburg', 'country' => 'Austria'],
            'graz' => ['city' => 'Graz', 'country' => 'Austria'],
            'zurich' => ['city' => 'Zurich', 'country' => 'Switzerland'],
            'zuerich' => ['city' => 'Zurich', 'country' => 'Switzerland'],
            'basel' => ['city' => 'Basel', 'country' => 'Switzerland'],
            'bern' => ['city' => 'Bern', 'country' => 'Switzerland'],
            'geneva' => ['city' => 'Geneva', 'country' => 'Switzerland'],
            'genf' => ['city' => 'Geneva', 'country' => 'Switzerland'],
            'amsterdam' => ['city' => 'Amsterdam', 'country' => 'Netherlands'],
            'rotterdam' => ['city' => 'Rotterdam', 'country' => 'Netherlands'],
            'london' => ['city' => 'London', 'country' => 'United Kingdom'],
            'paris' => ['city' => 'Paris', 'country' => 'France'],
            'madrid' => ['city' => 'Madrid', 'country' => 'Spain'],
            'barcelona' => ['city' => 'Barcelona', 'country' => 'Spain'],
            'lisbon' => ['city' => 'Lisbon', 'country' => 'Portugal'],
            'milan' => ['city' => 'Milan', 'country' => 'Italy'],
            'rome' => ['city' => 'Rome', 'country' => 'Italy'],
            'copenhagen' => ['city' => 'Copenhagen', 'country' => 'Denmark'],
            'stockholm' => ['city' => 'Stockholm', 'country' => 'Sweden'],
            'oslo' => ['city' => 'Oslo', 'country' => 'Norway'],
            'helsinki' => ['city' => 'Helsinki', 'country' => 'Finland'],
            'new york' => ['city' => 'New York', 'country' => 'United States'],
            'nyc' => ['city' => 'New York', 'country' => 'United States'],
            'los angeles' => ['city' => 'Los Angeles', 'country' => 'United States'],
            'toronto' => ['city' => 'Toronto', 'country' => 'Canada'],
            'sydney' => ['city' => 'Sydney', 'country' => 'Australia'],
            'melbourne' => ['city' => 'Melbourne', 'country' => 'Australia'],
            'colombo' => ['city' => 'Colombo', 'country' => 'Sri Lanka'],
        ];
    }
}
