<?php

namespace App\Services;

class AiScoringService
{
    public function __construct(
        private InfluencerScoringService $deterministicScoring,
        private AiGatewayService $ai,
    ) {
    }

    public function scoreCreators(array $creators, ?string $projectContext = null): array
    {
        $normalized = [];
        foreach ($creators as $creator) {
            if (!is_array($creator)) {
                continue;
            }

            $detail = $this->deterministicScoring->detailedScore($creator);
            $normalized[] = [
                'id' => (string) ($creator['id'] ?? ''),
                'handle' => (string) ($creator['handle'] ?? ''),
                'platform' => strtolower((string) ($creator['platform'] ?? 'instagram')),
                'followers' => $creator['followers'] ?? null,
                'engagementRate' => $creator['engagementRate'] ?? null,
                'email' => empty($creator['email']) ? null : (string) $creator['email'],
                'bio' => (string) ($creator['bio'] ?? ''),
                'postsCount' => $creator['postsCount'] ?? null,
                'avgLikes' => $creator['avgLikes'] ?? null,
                'avgComments' => $creator['avgComments'] ?? null,
                'isVerified' => (bool) ($creator['isVerified'] ?? false),
                'sourceHashtags' => array_values(array_filter((array) ($creator['sourceHashtags'] ?? []))),
                'baseScore' => $detail['score'],
                'baseSignals' => $detail['signals'],
                'baseRisks' => $detail['risks'],
            ];
        }

        if ($normalized === []) {
            return [];
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'scores' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'aiFitScore' => ['type' => 'number'],
                            'reason' => ['type' => 'string'],
                            'signals' => [
                                'type' => 'object',
                                'properties' => [
                                    'nicheFit' => ['type' => 'number'],
                                    'audienceFit' => ['type' => 'number'],
                                    'contactability' => ['type' => 'number'],
                                    'consistency' => ['type' => 'number'],
                                    'brandSafety' => ['type' => 'number'],
                                    'commercialIntent' => ['type' => 'number'],
                                ],
                                'required' => ['nicheFit', 'audienceFit', 'contactability', 'consistency', 'brandSafety', 'commercialIntent'],
                                'additionalProperties' => false,
                            ],
                            'risks' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['id', 'aiFitScore', 'reason', 'signals', 'risks'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['scores'],
            'additionalProperties' => false,
        ];

        $systemPrompt = 'You score creators for outreach quality. Be skeptical, commercially minded, and conservative. Do not overrate creators just because engagement looks flashy. Penalize thin data, low contactability, weak niche fit, spammy behavior, and obvious mismatch with the project. Scores are 0-100.';
        $userPrompt = "Project context:
" . ($projectContext ?: 'General creator outreach campaign') . "

Creators to evaluate:
" . json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $aiResult = $this->ai->structured(
            $systemPrompt,
            $userPrompt,
            'submit_scores',
            'Return outreach fit scores for all creators.',
            $schema,
            0.1,
        );

        $indexed = [];
        foreach ((array) ($aiResult['scores'] ?? []) as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $indexed[(string) $row['id']] = $row;
            }
        }

        $output = [];
        foreach ($normalized as $creator) {
            $id = $creator['id'];
            $baseScore = (int) ($creator['baseScore'] ?? 0);
            $row = $indexed[$id] ?? [];
            $aiFitScore = (int) round(max(0, min(100, (float) ($row['aiFitScore'] ?? $baseScore))));
            $finalScore = (int) round(($baseScore * 0.55) + ($aiFitScore * 0.45));
            $output[] = [
                'id' => $id,
                'baseScore' => $baseScore,
                'aiFitScore' => $aiFitScore,
                'score' => $finalScore,
                'finalScore' => $finalScore,
                'tier' => strtolower($this->deterministicScoring->tier($finalScore)),
                'reason' => (string) ($row['reason'] ?? 'No AI rationale returned.'),
                'signals' => $row['signals'] ?? [
                    'nicheFit' => null,
                    'audienceFit' => null,
                    'contactability' => null,
                    'consistency' => null,
                    'brandSafety' => null,
                    'commercialIntent' => null,
                ],
                'risks' => array_values(array_unique(array_merge(
                    (array) ($creator['baseRisks'] ?? []),
                    array_values(array_filter((array) ($row['risks'] ?? []), fn ($v) => is_string($v) && trim($v) !== '')),
                ))),
            ];
        }

        return $output;
    }
}
