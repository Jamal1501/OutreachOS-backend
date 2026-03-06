<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApifyController extends Controller
{
    public function runTikTokHashtagActor(Request $request)
    {
        $token = env('APIFY_API_TOKEN');
        $actorId = env('APIFY_TIKTOK_HASHTAG_ACTOR_ID');

        if (!$token || !$actorId) {
            return response()->json([
                'error' => 'Missing Apify env vars'
            ], 500);
        }

        $input = $request->all();

        $response = Http::withToken($token)
            ->post("https://api.apify.com/v2/acts/{$actorId}/runs", $input);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Apify run failed',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Actor started',
            'apify' => $response->json(),
        ]);
    }

    public function getRunStatus($runId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN'
            ], 500);
        }

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/actor-runs/{$runId}");

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch run status',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Run status fetched',
            'apify' => $response->json(),
        ]);
    }

    public function getDatasetResults(Request $request, $datasetId)
    {
        $token = env('APIFY_API_TOKEN');

        if (!$token) {
            return response()->json([
                'error' => 'Missing APIFY_API_TOKEN'
            ], 500);
        }

        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);

        $response = Http::withToken($token)
            ->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'clean' => 'true',
                'format' => 'json',
                'limit' => $limit,
                'offset' => $offset,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to fetch dataset results',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'message' => 'Dataset results fetched',
            'datasetId' => $datasetId,
            'count' => is_array($response->json()) ? count($response->json()) : 0,
            'items' => $response->json(),
        ]);
    }
}
