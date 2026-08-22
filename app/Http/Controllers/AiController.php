<?php

namespace App\Http\Controllers;

use App\Services\AiDiscoveryBriefService;
use App\Services\AiDuplicateDetectionService;
use App\Services\AiPersonalizationService;
use App\Services\AiScoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    public function parseDiscoveryBrief(Request $request)
    {
        $validated = $request->validate([
            'brief' => ['required', 'string', 'min:8', 'max:5000'],
            'platform' => ['nullable', 'string', 'in:instagram,tiktok'],
            'projectContext' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json($this->withBillingUsage($request, [
            'criteria' => $this->briefs->parse((string) $validated['brief'], [
                'platform' => $validated['platform'] ?? 'instagram',
                'projectContext' => $validated['projectContext'] ?? null,
            ]),
        ]));
    }

    public function __construct(
        private AiScoringService $scoring,
        private AiPersonalizationService $personalization,
        private AiDuplicateDetectionService $duplicates,
        private AiDiscoveryBriefService $briefs,
    ) {}

    public function scoreCreators(Request $request)
    {
        $validated = $request->validate([
            'creators' => ['required', 'array', 'min:1', 'max:50'],
            'creators.*' => ['array', 'max:80'],
            'projectContext' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json($this->withBillingUsage($request, [
            'scores' => $this->scoring->scoreCreators(
                $validated['creators'],
                $validated['projectContext'] ?? null,
            ),
        ]));
    }

    public function personalizeMessage(Request $request)
    {
        $validated = $request->validate([
            'creator' => ['required', 'array', 'max:100'],
            'template' => ['nullable', 'string', 'max:5000'],
            'generationMode' => ['nullable', 'string', 'max:64'],
            'tonePreference' => ['nullable', 'string', 'max:64'],
            'stage' => ['nullable', 'string', 'max:120'],
            'projectContext' => ['nullable', 'string', 'max:5000'],
            'outreachContext' => ['nullable', 'array', 'max:40'],
            'templateContext' => ['nullable', 'array', 'max:100'],
            'messageType' => ['nullable', 'string', 'max:80'],
            'taskContext' => ['nullable', 'array', 'max:100'],
            'replyContext' => ['nullable', 'array', 'max:100'],
            'previousMessage' => ['nullable', 'string', 'max:5000'],
            'conversationGoal' => ['nullable', 'string', 'max:2000'],
            'outputLanguage' => ['nullable', Rule::in(['en', 'de'])],
        ]);

        return response()->json($this->withBillingUsage(
            $request,
            $this->personalization->personalize($validated),
        ));
    }

    public function detectDuplicates(Request $request)
    {
        $validated = $request->validate([
            'creators' => ['required', 'array', 'min:2', 'max:100'],
            'creators.*' => ['array', 'max:80'],
        ]);

        return response()->json($this->withBillingUsage($request, [
            'duplicates' => $this->duplicates->detect($validated['creators']),
        ]));
    }

    private function withBillingUsage(Request $request, array $payload): array
    {
        $usage = (array) $request->attributes->get('ai_credit_usage', []);
        if ($usage === []) {
            return $payload;
        }

        $payload['_billing'] = [
            'aiCreditsConsumed' => (int) ($usage['credits_consumed'] ?? 0),
            'aiCreditsRemaining' => (int) ($usage['remaining_balance'] ?? 0),
            'aiCreditsBalance' => (int) ($usage['remaining_base_balance'] ?? 0),
            'bonusAiCredits' => (int) ($usage['remaining_bonus_balance'] ?? 0),
            'usageEventIds' => array_values((array) ($usage['usage_event_ids'] ?? [])),
        ];

        return $payload;
    }
}
