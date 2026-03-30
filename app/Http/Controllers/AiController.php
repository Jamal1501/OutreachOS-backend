<?php

namespace App\Http\Controllers;

use App\Services\AiDuplicateDetectionService;
use App\Services\AiPersonalizationService;
use App\Services\AiScoringService;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private AiScoringService $scoring,
        private AiPersonalizationService $personalization,
        private AiDuplicateDetectionService $duplicates,
    ) {
    }

    public function scoreCreators(Request $request)
    {
        $validated = $request->validate([
            'creators' => ['required', 'array', 'min:1'],
            'projectContext' => ['nullable', 'string'],
        ]);

        return response()->json([
            'scores' => $this->scoring->scoreCreators(
                $validated['creators'],
                $validated['projectContext'] ?? null,
            ),
        ]);
    }

    public function personalizeMessage(Request $request)
    {
        $validated = $request->validate([
            'creator' => ['required', 'array'],
            'template' => ['required', 'string'],
            'stage' => ['nullable', 'string'],
            'projectContext' => ['nullable', 'string'],
            'templateContext' => ['nullable', 'array'],
            'messageType' => ['nullable', 'string'],
            'replyContext' => ['nullable', 'array'],
            'previousMessage' => ['nullable', 'string'],
            'conversationGoal' => ['nullable', 'string'],
        ]);

        return response()->json($this->personalization->personalize($validated));
    }

    public function detectDuplicates(Request $request)
    {
        $validated = $request->validate([
            'creators' => ['required', 'array', 'min:2'],
        ]);

        return response()->json([
            'duplicates' => $this->duplicates->detect($validated['creators']),
        ]);
    }
}
