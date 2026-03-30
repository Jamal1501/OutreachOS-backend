<?php

namespace App\Services;

class AiDuplicateDetectionService
{
    public function __construct(private AiGatewayService $ai)
    {
    }

    public function detect(array $creators): array
    {
        $candidates = [];
        $count = count($creators);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = (array) $creators[$i];
                $b = (array) $creators[$j];
                $platformA = strtolower((string) ($a['platform'] ?? ''));
                $platformB = strtolower((string) ($b['platform'] ?? ''));
                if ($platformA === '' || $platformA === $platformB) {
                    continue;
                }

                $signals = [];
                $emailA = strtolower(trim((string) ($a['email'] ?? '')));
                $emailB = strtolower(trim((string) ($b['email'] ?? '')));
                $handleA = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($a['handle'] ?? '')));
                $handleB = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($b['handle'] ?? '')));
                $nameA = strtolower(trim((string) ($a['fullName'] ?? '')));
                $nameB = strtolower(trim((string) ($b['fullName'] ?? '')));

                if ($emailA !== '' && $emailA === $emailB) {
                    $signals[] = 'exact_email';
                }
                if ($handleA !== '' && $handleA === $handleB) {
                    $signals[] = 'exact_handle';
                } elseif ($handleA !== '' && $handleB !== '' && strlen($handleA) > 3 && strlen($handleB) > 3 && (str_contains($handleA, $handleB) || str_contains($handleB, $handleA))) {
                    $signals[] = 'handle_substring';
                }
                if ($nameA !== '' && $nameA === $nameB && strlen($nameA) > 2) {
                    $signals[] = 'exact_name';
                }

                if ($signals !== []) {
                    $candidates[] = ['indexA' => $i, 'indexB' => $j, 'preSignals' => $signals];
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'duplicates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'indexA' => ['type' => 'number'],
                            'indexB' => ['type' => 'number'],
                            'confidence' => ['type' => 'number'],
                            'signals' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['indexA', 'indexB', 'confidence', 'signals', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['duplicates'],
            'additionalProperties' => false,
        ];

        $systemPrompt = 'You are a strict cross-platform duplicate detector. Be conservative. Only report likely same-person matches with confidence 70 or higher. Weak name similarity alone is not enough.';
        $userPrompt = "Creators:
" . json_encode(array_values($creators), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "

Candidate pairs:
" . json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $result = $this->ai->structured(
            $systemPrompt,
            $userPrompt,
            'submit_duplicates',
            'Return duplicate matches that are likely the same person across platforms.',
            $schema,
            0.0,
        );

        $output = [];
        foreach ((array) ($result['duplicates'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $indexA = (int) ($row['indexA'] ?? -1);
            $indexB = (int) ($row['indexB'] ?? -1);
            if (!isset($creators[$indexA], $creators[$indexB])) {
                continue;
            }
            if ((float) ($row['confidence'] ?? 0) < 70) {
                continue;
            }
            $a = (array) $creators[$indexA];
            $b = (array) $creators[$indexB];
            if (strtolower((string) ($a['platform'] ?? '')) === strtolower((string) ($b['platform'] ?? ''))) {
                continue;
            }
            $output[] = [
                'creatorA' => [
                    'handle' => $a['handle'] ?? null,
                    'platform' => $a['platform'] ?? null,
                    'fullName' => $a['fullName'] ?? null,
                ],
                'creatorB' => [
                    'handle' => $b['handle'] ?? null,
                    'platform' => $b['platform'] ?? null,
                    'fullName' => $b['fullName'] ?? null,
                ],
                'confidence' => (float) ($row['confidence'] ?? 0),
                'signals' => array_values((array) ($row['signals'] ?? [])),
                'reason' => (string) ($row['reason'] ?? ''),
            ];
        }

        return $output;
    }
}
